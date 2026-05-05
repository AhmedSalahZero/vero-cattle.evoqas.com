<?php

namespace App\Http\Controllers;

use App\Services\FinancialReportService;
use App\Services\LivestockIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Report Controller (Phase 4)
//
//  Thin controller — all calculations live in:
//    FinancialReportService       (P&L, Balance Sheet, Cash Flow, TB)
//    LivestockIntelligenceService (ADG, FCR, WAC, COGS, Gross Margin)
//
//  Routes exposed:
//    GET  reports/trial-balance
//    GET  reports/profit-loss
//    GET  reports/balance-sheet
//    GET  reports/cash-flow
//    GET  reports/gross-margin
//    GET  reports/adg-fcr
//    GET  reports/cogs/{lot}
//    GET  reports/animal/{animal}/costing
//
//  All routes require 'view reports' permission.
//  Super-admin can pass company_id; regular users default to their own.
//
//  FIX (Session 27):
//    All Inertia::render() calls now point to 'Accounting/Reports/Index'
//    (the single unified Vue page) with an activeTab prop so Vue knows
//    which tab to display. Previously each method rendered a different
//    component name (Reports/TrialBalance, Reports/ProfitAndLoss, etc.)
//    that did not exist on disk — causing Inertia 404s on every tab.
//
//    Each method also passes the shared filter-panel props (companies,
//    farms, lots, fiscalYears) so dropdowns are populated regardless
//    of which tab the user lands on.
//
//    resolveCompany() fixed: SA with no company_id in request now
//    falls back to the first available company instead of returning 0.
// ══════════════════════════════════════════════════════════════════

class ReportController extends Controller
{
    public function __construct(
        private FinancialReportService       $financials,
        private LivestockIntelligenceService $livestock,
    ) {}
    // ── INDEX — landing page, no report data yet ──────────────────
    // GET /reports
    public function index(Request $request)
    {
        $this->authorise('view reports');

        $companyId = $this->resolveCompany($request);

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => null,
                'filters'   => [],
                'activeTab' => $request->get('tab', 'trial-balance'),
            ]
        ));
    }
    // ── TRIAL BALANCE ─────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: TrialBalance (#5)
    // GET /reports/trial-balance?period_start=&period_end=
    public function trialBalance(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $companyId = $this->resolveCompany($request);

        $data = $this->financials->trialBalance(
            $companyId,
            $request->period_start,
            $request->period_end,
        );

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => $request->only(['period_start', 'period_end', 'company_id']),
                'activeTab' => 'trial-balance',
            ]
        ));
    }

    // ── PROFIT & LOSS ─────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: ProfitAndLoss (#6)
    // GET /reports/profit-loss?date_from=&date_to=&compare_from=&compare_to=
    public function profitAndLoss(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from'    => 'required|date',
            'date_to'      => 'required|date|after_or_equal:date_from',
            'compare_from' => 'nullable|date',
            'compare_to'   => 'nullable|date|after_or_equal:compare_from',
        ]);

        $companyId = $this->resolveCompany($request);

        $data = $this->financials->profitAndLoss(
            $companyId,
            $request->date_from,
            $request->date_to,
            $request->compare_from,
            $request->compare_to,
        );

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => $request->only(['date_from', 'date_to', 'compare_from', 'compare_to', 'company_id']),
                'activeTab' => 'profit-loss',
            ]
        ));
    }

    // ── BALANCE SHEET ─────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: BalanceSheet (#7)
    // GET /reports/balance-sheet?as_of_date=&fiscal_year_id=
    public function balanceSheet(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'as_of_date'     => 'required|date',
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
        ]);

        $companyId = $this->resolveCompany($request);

        $data = $this->financials->balanceSheet(
            $companyId,
            $request->as_of_date,
            $request->fiscal_year_id,
        );

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => $request->only(['as_of_date', 'fiscal_year_id', 'company_id']),
                'activeTab' => 'balance-sheet',
            ]
        ));
    }

    // ── CASH FLOW ─────────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: CashFlow (#8) — Indirect Method
    // GET /reports/cash-flow?date_from=&date_to=
    public function cashFlow(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = $this->resolveCompany($request);

        $data = $this->financials->cashFlow(
            $companyId,
            $request->date_from,
            $request->date_to,
        );

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => $request->only(['date_from', 'date_to', 'company_id']),
                'activeTab' => 'cash-flow',
            ]
        ));
    }

    // ── GROSS MARGIN RANKING ──────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
    // GET /reports/gross-margin?farm_id=&date_from=&date_to=
    public function grossMarginRanking(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'farm_id'   => 'nullable|integer|exists:farms,id',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $companyId = $this->resolveCompany($request);

        $lots = DB::table('lots')
            ->where('company_id', $companyId)
            ->when($request->farm_id, fn($q) => $q->where('farm_id', $request->farm_id))
            ->pluck('id');

        $ranking = $lots
            ->map(fn($lotId) => $this->livestock->costingForLot($lotId, $companyId))
            ->filter(fn($r) => $r['head_count'] > 0)
            ->sortByDesc('gross_margin')
            ->values();

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $ranking,
                'filters'   => $request->only(['farm_id', 'date_from', 'date_to', 'company_id']),
                'activeTab' => 'gross-margin',
            ]
        ));
    }

    // ── ADG / FCR REPORT ─────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
    // GET /reports/adg-fcr?lot_id=
    public function adgFcr(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'lot_id' => 'nullable|integer|exists:lots,id',
        ]);

        $companyId = $this->resolveCompany($request);

        if ($request->lot_id) {
            $data = $this->livestock->adgFcrForLot($request->lot_id, $companyId);
        } else {
            // All active lots — pluck(id) returns a flat collection of IDs
            $lotIds = DB::table('lots')
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->pluck('id');

            $data = $lotIds
                ->map(fn($id) => $this->livestock->adgFcrForLot($id, $companyId))
                ->values();
        }

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => $request->only(['lot_id', 'company_id']),
                'activeTab' => 'adg-fcr',
            ]
        ));
    }

    // ── COGS PER LOT ──────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
    // GET /reports/cogs/{lot}
    // Used for drill-down from other pages — not a tab in the main report page.
    public function cogsForLot(Request $request, int $lotId)
    {
        $this->authorise('view reports');

        $companyId = $this->resolveCompany($request);

        $data = $this->livestock->costingForLot($lotId, $companyId);

        // This route renders a standalone drill-down, not the unified Index.
        // Build the page once it is needed — for now renders as gross-margin tab
        // with a single lot pre-selected.
        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => collect([$data])->filter(fn($r) => $r['head_count'] > 0)->values(),
                'filters'   => ['company_id' => $companyId],
                'activeTab' => 'gross-margin',
            ]
        ));
    }

    // ── SINGLE ANIMAL COSTING ─────────────────────────────────────
    // 📌 METHODOLOGY REFERENCES: ADG_FCR (#9), GrossMarginBreakEven (#11)
    // GET /reports/animal/{animal}/costing
    // Drill-down from animal cards — renders as gross-margin tab.
    public function animalCosting(Request $request, int $animalId)
    {
        $this->authorise('view reports');

        $companyId = $this->resolveCompany($request);

        $data = $this->livestock->costingForAnimal($animalId, $companyId);

        return Inertia::render('Accounting/Reports/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => $data,
                'filters'   => ['animal_id' => $animalId, 'company_id' => $companyId],
                'activeTab' => 'gross-margin',
            ]
        ));
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Shared props passed to every Inertia::render() call.
     * Populates the filter-panel dropdowns on all 6 tabs regardless
     * of which tab the controller method is serving.
     */
    private function sharedProps(Request $request, int $companyId): array
    {
        $user = Auth::user();

        return [
            'companies'   => $user->is_super_admin
                ? \App\Models\Company::select('id', 'name_en', 'name_ar')
                    ->orderBy('name_en')
                    ->get()
                : [],
            'farms'       => $this->availableFarms($companyId),
            'lots'        => $this->availableLots($companyId),
            'fiscalYears' => $this->availableFiscalYears($companyId),
        ];
    }

    /**
     * All active farms for the company — used in Gross Margin filter.
     */
    private function availableFarms(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('farms')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->select('id', 'name_en', 'name_ar')
            ->orderBy('name_en')
            ->get();
    }

    /**
     * Active and completed lots — used in ADG/FCR filter.
     */
    private function availableLots(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('lots')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'completed'])
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Fiscal years for the company — used in Balance Sheet filter.
     * Dates formatted as plain Y-m-d strings (not Carbon objects)
     * so Vue receives clean strings, not ISO timestamps.
     */
    private function availableFiscalYears(int $companyId): array
    {
        return DB::table('fiscal_years')
            ->where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->select('id', 'name', 'start_date', 'end_date', 'status')
            ->get()
             ->map(fn($fy) => [
                'id'         => $fy->id,
                'name'       => $fy->name,
                'start_date' => $fy->start_date ? substr($fy->start_date, 0, 10) : null,
                'end_date'   => $fy->end_date   ? substr($fy->end_date,   0, 10) : null,
                'status'     => $fy->status,
            ])
            ->toArray();
    }

    /**
     * Resolve company_id for the current request.
     *
     * Regular user  → always their own company_id.
     * Super Admin   → company_id from request if provided,
     *                 otherwise first company in the DB (never returns 0).
     */
    private function resolveCompany(Request $request): int
    {
        $user = Auth::user();

        if (! $user->is_super_admin) {
            return (int) $user->company_id;
        }

        // SA: prefer explicit company_id from request
        if ($request->filled('company_id')) {
            return (int) $request->company_id;
        }

        // SA fallback: first company (avoids returning 0 / null)
        $first = DB::table('companies')->orderBy('id')->value('id');
        return (int) ($first ?? 0);
    }

    /**
     * Permission guard — aborts with 403 if user lacks the permission.
     */
    private function authorise(string $permission): void
    {
        $user = Auth::user();
        abort_unless(
            $user->is_super_admin || $user->hasPermissionTo($permission),
            403
        );
    }
}