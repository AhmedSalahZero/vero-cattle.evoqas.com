<?php

namespace App\Http\Controllers;

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;      // 📌 FIX: was missing — required for DB::transaction() and DB::selectOne()
use Inertia\Inertia;

// ── SEC-GAP-1 REMINDER ────────────────────────────────────────────
// The ArInvoice model MUST use SoftDeletes:
//
//   use Illuminate\Database\Eloquent\SoftDeletes;
//   class ArInvoice extends Model {
//       use SoftDeletes;
//   }
//
// Migration (run once, covers all five financial tables):
//   Schema::table('ar_invoices',     fn($t) => $t->softDeletes());
//   Schema::table('ap_invoices',     fn($t) => $t->softDeletes());
//   Schema::table('journal_entries', fn($t) => $t->softDeletes());
//   Schema::table('journal_lines',   fn($t) => $t->softDeletes());
//   Schema::table('payments',        fn($t) => $t->softDeletes());
//
// Once SoftDeletes is added, destroy() below automatically
// soft-deletes instead of hard-deletes — no controller change needed.
// ─────────────────────────────────────────────────────────────────

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AR Invoice Controller (Receivables)
//
//  📌 METHODOLOGY REFERENCE: InvoiceBalanceCalc
//  balance = total − paid  (always in invoice's native currency)
//
//  📌 METHODOLOGY REFERENCE: InvoiceForeignCurrencyBalance
//  exchange_rate stored at creation. EGP equiv = amount × rate.
//  exchange_rate = 1.0 when currency = EGP (locked).
//
//  📌 METHODOLOGY REFERENCE: InvoiceAgingBuckets
//  5 buckets: current / 1-30 / 31-60 / 61-90 / 90+
//
//  Super Admin rule: company_id from request/record — never from auth user.
// ══════════════════════════════════════════════════════════════════

class ArInvoiceController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view ar_invoices'),
            403
        );

        $query = ArInvoice::with('customer:id,name_en,name_ar');

        if ($authUser->is_super_admin) {
            if ($request->filled('company_id')) {
                $query->where('company_id', (int)$request->company_id);
            }
        } else {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('date_from'))   $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('date', '<=', $request->date_to);

        $companyId = $authUser->is_super_admin
            ? ($request->filled('company_id') ? (int)$request->company_id : null)
            : $authUser->company_id;
        $this->syncOverdueStatus($companyId);

        $invoices = $query->orderByDesc('date')->paginate(50)->withQueryString();

        // 📌 InvoiceAgingBuckets
        $aging = $this->computeAging($companyId);

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        $customers = $authUser->is_super_admin
            ? collect()
            : $this->availableCustomers($authUser->company_id);

        return Inertia::render('Accounting/ArInvoices/Index', [
            'invoices'  => $invoices->through(fn($i) => $this->formatInvoice($i)),
            'aging'     => $aging,
            'customers' => $customers,
            'companies' => $companies,
            'filters'   => $request->only(['customer_id', 'status', 'date_from', 'date_to', 'company_id']),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(ArInvoice $arInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('view ar_invoices') && $authUser->company_id === $arInvoice->company_id),
            403
        );

        $arInvoice->load([
            'customer:id,name_en,name_ar,phone,email',
            'journalEntry:id,reference,status,date',
            'payments',
        ]);

        return Inertia::render('Accounting/ArInvoices/Show', [
            'invoice'   => $this->formatInvoiceFull($arInvoice),
            'companies' => $authUser->is_super_admin
                ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create()
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create ar_invoices'),
            403
        );

        return Inertia::render('Accounting/ArInvoices/CreateEdit', [
            'mode'      => 'create',
            'invoice'   => null,
            'customers' => $authUser->is_super_admin
                ? collect()
                : $this->availableCustomers($authUser->company_id),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create ar_invoices'),
            403
        );

        // 📌 Super Admin Rule
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id')
            : (int) $authUser->company_id;

        abort_unless($companyId > 0, 422, 'company_id is required.');

        $validated = $this->validateInvoice($request);

        $customer = Customer::findOrFail($validated['customer_id']);
        abort_unless($customer->company_id === $companyId, 422, 'Customer does not belong to this company.');

        // 📌 InvoiceForeignCurrencyBalance
        $currency     = $validated['currency'] ?? 'EGP';
        $exchangeRate = $currency === 'EGP' ? 1.0 : (float)($validated['exchange_rate'] ?? 0);
        abort_unless($exchangeRate > 0, 422, 'Exchange rate must be greater than zero.');

        ArInvoice::create([
            'company_id'    => $companyId,
            'customer_id'   => $validated['customer_id'],
            'date'          => $validated['date'],
            'due_date'      => $validated['due_date'] ?? null,
            'reference'     => $validated['reference'] ?? null,
            'description'   => $validated['description'] ?? null,
            'total'         => $validated['total'],
            'paid'          => 0.00,
            'balance'       => $validated['total'],   // 📌 InvoiceBalanceCalc
            'currency'      => $currency,
            'exchange_rate' => $exchangeRate,
            'status'        => 'open',
            'notes'         => $validated['notes'] ?? null,
        ]);

        return redirect()->route('ar-invoices.index')
            ->with('success', __('messages.ar_invoice_created'));
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(ArInvoice $arInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ar_invoices') && $authUser->company_id === $arInvoice->company_id),
            403
        );

        abort_if(in_array($arInvoice->status, ['paid', 'cancelled']), 422, __('messages.invoice_not_editable'));

        // For edit: company_id from the record — safe for SA
        $companyId = $arInvoice->company_id;

        return Inertia::render('Accounting/ArInvoices/CreateEdit', [
            'mode'      => 'edit',
            'invoice'   => $this->formatInvoice($arInvoice),
            'customers' => $this->availableCustomers($companyId),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, ArInvoice $arInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ar_invoices') && $authUser->company_id === $arInvoice->company_id),
            403
        );

        abort_if(in_array($arInvoice->status, ['paid', 'cancelled']), 422, __('messages.invoice_not_editable'));

        $validated = $this->validateInvoice($request);

        // 📌 InvoiceForeignCurrencyBalance
        $currency     = $validated['currency'] ?? $arInvoice->currency;
        $exchangeRate = $currency === 'EGP' ? 1.0 : (float)($validated['exchange_rate'] ?? $arInvoice->exchange_rate);
        abort_unless($exchangeRate > 0, 422, 'Exchange rate must be greater than zero.');

        // 📌 InvoiceBalanceCalc
        // 📌 RISK-F1 FIX: bcmath for monetary subtraction — never native float
        $newBalance = bcsub((string)$validated['total'], (string)$arInvoice->paid, 4);
        $newBalance = bccomp($newBalance, '0', 4) < 0 ? '0' : $newBalance;   // floor at zero
        $newStatus  = $this->resolveInvoiceStatus($newBalance, (string)$validated['total'], $validated['due_date'] ?? null);

        $arInvoice->update([
            'customer_id'   => $validated['customer_id'],
            'date'          => $validated['date'],
            'due_date'      => $validated['due_date'] ?? null,
            'reference'     => $validated['reference'] ?? null,
            'description'   => $validated['description'] ?? null,
            'total'         => $validated['total'],
            'balance'       => (float) bcadd($newBalance, '0', 4),
            'currency'      => $currency,
            'exchange_rate' => $exchangeRate,
            'status'        => $newStatus,
            'notes'         => $validated['notes'] ?? null,
        ]);

        return redirect()->route('ar-invoices.show', $arInvoice)
            ->with('success', __('messages.ar_invoice_updated'));
    }

    // ── Cancel ────────────────────────────────────────────────────
    public function cancel(ArInvoice $arInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ar_invoices') && $authUser->company_id === $arInvoice->company_id),
            403
        );

        abort_if($arInvoice->status === 'paid',      422, __('messages.invoice_paid_cannot_cancel'));
        abort_if($arInvoice->status === 'cancelled', 422, __('messages.invoice_already_cancelled'));
        abort_if($arInvoice->paid > 0,               422, __('messages.invoice_has_payments'));

        $arInvoice->update(['status' => 'cancelled']);

        return back()->with('success', __('messages.ar_invoice_cancelled'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(ArInvoice $arInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete ar_invoices') && $authUser->company_id === $arInvoice->company_id),
            403
        );

        abort_unless($arInvoice->status === 'open', 422, __('messages.invoice_not_deletable'));
        abort_if($arInvoice->paid > 0,              422, __('messages.invoice_has_payments'));

        $arInvoice->delete();

        return redirect()->route('ar-invoices.index')
            ->with('success', __('messages.ar_invoice_deleted'));
    }

    // ── SA cascade: company → customers ───────────────────────────
    public function dataForCompany(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin, 403);

        $companyId = (int) $request->input('company_id');
        abort_unless($companyId > 0, 422);

        return response()->json([
            'customers' => $this->availableCustomers($companyId),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────

    // 📌 RISK-F1 FIX: parameters changed from float to string so bcmath comparisons
    // are used throughout. Native float comparisons (e.g. $balance <= 0) can fail
    // when IEEE 754 leaves a tiny non-zero residual after a full payment — the
    // invoice would be stuck at 'partially_paid' forever despite being fully settled.
    private function resolveInvoiceStatus(string $balance, string $total, ?string $dueDate): string
    {
        // Paid: balance is zero or negative (bccomp returns -1 or 0)
        if (bccomp($balance, '0', 4) <= 0)  return 'paid';

        // Partially paid: balance < total and not yet overdue
        if (
            bccomp($balance, $total, 4) < 0 &&
            (!$dueDate || $dueDate >= now()->toDateString())
        ) {
            return 'partially_paid';
        }

        // Overdue: past due date with remaining balance
        if ($dueDate && $dueDate < now()->toDateString() && bccomp($balance, '0', 4) > 0) {
            return 'overdue';
        }

        return 'open';
    }

    private function syncOverdueStatus(?int $companyId): void
    {
        // 📌 FIX: Skip bulk update when no company is selected (SA with no filter).
        // Running without a company_id scope would touch every invoice across ALL
        // companies on every SA index page load — dangerous and unnecessary.
        if ($companyId === null) {
            return;
        }

        // Wrap in a transaction to prevent a concurrent payment write from
        // having its status overwritten back to 'overdue' by this bulk update.
        DB::transaction(function () use ($companyId) {
            ArInvoice::whereIn('status', ['open', 'partially_paid'])
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->where('company_id', $companyId)
                ->update(['status' => 'overdue']);
        });
    }

    // 📌 METHODOLOGY REFERENCE: InvoiceAgingBuckets
    //
    // 📌 RISK-F1 FIX + MEDIUM FIX: Rewritten as a single DB aggregate query.
    //
    // Previous implementation loaded ALL open invoice rows into PHP memory and
    // accumulated balances with float += in a foreach loop — two problems:
    //   1. Full table scan into PHP for every index page load (N-row memory hit)
    //   2. Float += accumulation produces imprecise totals on large invoice sets
    //
    // This version computes all 5 buckets in one SQL query using DATEDIFF and
    // SUM(CASE WHEN ...) — single round-trip, zero PHP accumulation.
    private function computeAging(?int $companyId): array
    {
        if ($companyId === null) {
            return ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        }

        $today = now()->toDateString();

        $row = DB::selectOne("
            SELECT
                SUM(CASE
                    WHEN due_date IS NULL OR due_date >= :today1
                    THEN balance ELSE 0
                END) AS current_bucket,

                SUM(CASE
                    WHEN due_date < :today2
                     AND DATEDIFF(:today3, due_date) BETWEEN 1 AND 30
                    THEN balance ELSE 0
                END) AS bucket_1_30,

                SUM(CASE
                    WHEN due_date < :today4
                     AND DATEDIFF(:today5, due_date) BETWEEN 31 AND 60
                    THEN balance ELSE 0
                END) AS bucket_31_60,

                SUM(CASE
                    WHEN due_date < :today6
                     AND DATEDIFF(:today7, due_date) BETWEEN 61 AND 90
                    THEN balance ELSE 0
                END) AS bucket_61_90,

                SUM(CASE
                    WHEN due_date < :today8
                     AND DATEDIFF(:today9, due_date) > 90
                    THEN balance ELSE 0
                END) AS bucket_over_90

            FROM ar_invoices
            WHERE company_id  = :company_id
              AND status       IN ('open', 'partially_paid', 'overdue')
              AND balance      > 0
              AND deleted_at   IS NULL
        ", [
            'today1'     => $today,
            'today2'     => $today, 'today3'  => $today,
            'today4'     => $today, 'today5'  => $today,
            'today6'     => $today, 'today7'  => $today,
            'today8'     => $today, 'today9'  => $today,
            'company_id' => $companyId,
        ]);

        return [
            'current'  => (float) bcadd((string)($row->current_bucket  ?? 0), '0', 2),
            '1_30'     => (float) bcadd((string)($row->bucket_1_30     ?? 0), '0', 2),
            '31_60'    => (float) bcadd((string)($row->bucket_31_60    ?? 0), '0', 2),
            '61_90'    => (float) bcadd((string)($row->bucket_61_90    ?? 0), '0', 2),
            'over_90'  => (float) bcadd((string)($row->bucket_over_90  ?? 0), '0', 2),
        ];
    }

    private function validateInvoice(Request $request): array
    {
        return $request->validate([
            'customer_id'   => 'required|integer|exists:customers,id',
            'date'          => 'required|date',
            'due_date'      => 'nullable|date|after_or_equal:date',
            'reference'     => 'nullable|string|max:100',
            'description'   => 'nullable|string|max:255',
            'total'         => 'required|numeric|min:0.01',
            'currency'      => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'notes'         => 'nullable|string|max:1000',
        ]);
    }

    private function formatInvoice(ArInvoice $i): array
    {
        return [
            'id'            => $i->id,
            'company_id'    => $i->company_id,
            'customer_id'   => $i->customer_id,
            'customer'      => $i->customer?->only(['id', 'name_en', 'name_ar']),
            'date'          => $i->date instanceof \Carbon\Carbon ? $i->date->toDateString() : $i->date,
            'due_date'      => $i->due_date instanceof \Carbon\Carbon ? $i->due_date->toDateString() : $i->due_date,
            'reference'     => $i->reference,
            'description'   => $i->description,
            'total'         => $i->total,
            'paid'          => $i->paid,
            'balance'       => $i->balance,
            'currency'      => $i->currency,
            'exchange_rate' => $i->exchange_rate,
            'total_egp'     => round($i->total * $i->exchange_rate, 2),
            'balance_egp'   => round($i->balance * $i->exchange_rate, 2),
            'status'        => $i->status,
            'notes'         => $i->notes,
        ];
    }

    private function formatInvoiceFull(ArInvoice $i): array
    {
        return array_merge($this->formatInvoice($i), [
            'journal_entry' => $i->journalEntry?->only(['id', 'reference', 'status', 'date']),
            'payments'      => $i->payments
                ? $i->payments->map(fn($p) => [
                    'id'            => $p->id,
                    'date'          => $p->date,
                    'amount'        => $p->amount,
                    'method'        => $p->method,
                    'exchange_rate' => $p->exchange_rate ?? 1.0,
                ])
                : [],
        ]);
    }

    private function availableCustomers(int $companyId): \Illuminate\Support\Collection
    {
        return Customer::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }
}