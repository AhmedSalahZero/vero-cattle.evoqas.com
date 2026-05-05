<?php

namespace App\Http\Controllers;

use App\Models\FxRate;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FX Rate Controller
//
//  Purpose:
//    Store and retrieve the company's daily working exchange rates
//    for the 6 supported hard currencies:
//      USD · EUR · GBP · SAR · AED · QAR
//
//  Design principle (IMPORTANT):
//    Rates stored here are a CONVENIENCE PRE-FILL only.
//    They never override the exchange_rate on individual transactions
//    (journal lines, AP invoices, AR invoices, payments).
//    Each transaction is the authoritative record of the rate used.
//
//  Rate format: 1 foreign unit = X EGP
//    e.g. USD rate = 31.25 means 1 USD = 31.25 EGP
//
//  Storage pattern: upsert on (company_id, currency, rate_date).
//    One rate per currency per day. Updating today's rate replaces it.
//
//  Super Admin rule:
//    company_id NEVER comes from auth()->user() for SA.
//    It comes from the request (store) or the page filter.
// ══════════════════════════════════════════════════════════════════

class FxRateController extends Controller
{
    // Supported currencies — order determines display order in Vue
    private const CURRENCIES = ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'QAR'];

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolveCompanyId($authUser, $request);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view fx_rates'),
            403
        );

        // ── Current rates (latest per currency) ───────────────────
        $currentRates = $this->buildCurrentRates($companyId);

        // ── History (last 30 days, all currencies) ────────────────
        $history = $this->buildHistory($companyId);

        return Inertia::render('Accounting/FxRates/Index', [
            'currencies'   => self::CURRENCIES,
            'currentRates' => $currentRates,
            'history'      => $history,
            'companies'    => $authUser->is_super_admin
                ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
            'selectedCompanyId' => $companyId,
            'filters'      => $request->only(['company_id']),
        ]);
    }

    // ── Store (batch upsert for all 6 currencies in one submit) ───
    public function store(Request $request)
    {
        $authUser  = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit fx_rates'),
            403
        );

        $companyId = $this->resolveCompanyId($authUser, $request, true);

        $validated = $request->validate([
            'rate_date'          => 'required|date',
            'rates'              => 'required|array',
            'rates.*.currency'   => ['required', \Illuminate\Validation\Rule::in(self::CURRENCIES)],
            'rates.*.rate'       => 'required|numeric|min:0.000001',
            'rates.*.notes'      => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated, $companyId, $authUser) {
            foreach ($validated['rates'] as $row) {
                FxRate::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'currency'   => $row['currency'],
                        'rate_date'  => $validated['rate_date'],
                    ],
                    [
                        'rate'       => $row['rate'],
                        'notes'      => $row['notes'] ?? null,
                        'updated_by' => $authUser->id,
                    ]
                );
            }
        });

        return back()->with('success', __('messages.fx_rates_saved'));
    }

    // ── Destroy (delete a single historical rate row) ─────────────
    public function destroy(FxRate $fxRate)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit fx_rates') &&
             $authUser->company_id === $fxRate->company_id),
            403
        );

        $fxRate->delete();

        return back()->with('success', __('messages.fx_rate_deleted'));
    }

    // ── Latest rate endpoint (called by other forms via fetch) ────
    // GET /fx-rates/latest?currency=USD   → { rate, rate_date }
    // Used by Journal Entry, AP Invoice, AR Invoice, Payment forms
    // to pre-fill the exchange_rate field.
    public function latest(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolveCompanyId($authUser, $request);

        abort_unless($companyId > 0, 422, 'company_id required');

        $currency = strtoupper($request->input('currency', ''));
        abort_unless(in_array($currency, self::CURRENCIES), 422, 'Unsupported currency');

        $row = FxRate::where('company_id', $companyId)
            ->where('currency', $currency)
            ->orderByDesc('rate_date')
            ->first(['rate', 'rate_date']);

        if (!$row) {
            return response()->json(['rate' => null, 'rate_date' => null]);
        }

        return response()->json([
            'rate'      => (float) $row->rate,
            'rate_date' => $row->rate_date instanceof \Carbon\Carbon
                ? $row->rate_date->toDateString()
                : $row->rate_date,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Build the current-rates matrix:
     * One entry per supported currency, showing the latest rate on record.
     * Missing currencies are included with rate = null (not yet set).
     */
    private function buildCurrentRates(?int $companyId): array
    {
        if (!$companyId) {
            return array_map(fn($c) => $this->emptyRate($c), self::CURRENCIES);
        }

        // Single query: latest rate_date per currency via subquery
        $rows = FxRate::where('company_id', $companyId)
            ->whereIn('currency', self::CURRENCIES)
           ->whereIn('id', function ($sub) use ($companyId) {
            $sub->selectRaw('MAX(id)')
                ->from('fx_rates')
                ->where('company_id', $companyId)
                ->whereRaw('(currency, rate_date) IN (
                    SELECT currency, MAX(rate_date)
                    FROM fx_rates
                    WHERE company_id = ?
                    GROUP BY currency
                )', [$companyId])
                ->groupBy('currency');
            })
            ->get()
            ->keyBy('currency');

        return array_map(function ($currency) use ($rows) {
            $row = $rows->get($currency);
            if (!$row) return $this->emptyRate($currency);

            $rateDate   = $row->rate_date instanceof \Carbon\Carbon
                ? $row->rate_date->toDateString()
                : $row->rate_date;
            $daysAgo    = now()->diffInDays($rateDate, false);
            $isStale    = $daysAgo < -1;   // more than 1 day old

            return [
                'currency'   => $currency,
                'rate'       => (float) $row->rate,
                'rate_date'  => $rateDate,
                'days_ago'   => abs((int) $daysAgo),
                'is_stale'   => $isStale,
                'notes'      => $row->notes,
                'updated_by' => $row->updatedBy?->name,
            ];
        }, self::CURRENCIES);
    }

    /**
     * Build the history table: last 60 entries per company, newest first.
     */
    private function buildHistory(?int $companyId): array
    {
        if (!$companyId) return [];

        return FxRate::where('company_id', $companyId)
            ->with('updatedBy:id,name')
            ->orderByDesc('rate_date')
            ->orderBy('currency')
            ->limit(120)
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'currency'   => $r->currency,
                'rate'       => (float) $r->rate,
                'rate_date'  => $r->rate_date instanceof \Carbon\Carbon
                    ? $r->rate_date->toDateString()
                    : $r->rate_date,
                'notes'      => $r->notes,
                'updated_by' => $r->updatedBy?->name,
                'updated_at' => $r->updated_at?->format('d M Y H:i'),
            ])
            ->all();
    }

    private function emptyRate(string $currency): array
    {
        return [
            'currency'   => $currency,
            'rate'       => null,
            'rate_date'  => null,
            'days_ago'   => null,
            'is_stale'   => false,
            'notes'      => null,
            'updated_by' => null,
        ];
    }

    private function resolveCompanyId($authUser, Request $request, bool $required = false): ?int
    {
        if ($authUser->is_super_admin) {
            $id = (int) $request->input('company_id', 0);
            if ($required) {
                abort_unless($id > 0, 422, __('messages.company_required_for_super_admin'));
            }
            return $id > 0 ? $id : null;
        }
        return $authUser->company_id;
    }
}