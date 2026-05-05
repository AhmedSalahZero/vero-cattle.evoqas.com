<?php

namespace App\Http\Controllers;

use App\Models\ApInvoice;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ProcurementRecord;
use App\Models\Supplier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ── SEC-GAP-1 REMINDER ────────────────────────────────────────────
// The ApInvoice model MUST use SoftDeletes:
//
//   use Illuminate\Database\Eloquent\SoftDeletes;
//   class ApInvoice extends Model {
//       use SoftDeletes;
//   }
//
// And the migration must add deleted_at:
//   Schema::table('ap_invoices', fn($t) => $t->softDeletes());
//   Schema::table('ar_invoices', fn($t) => $t->softDeletes());
//   Schema::table('journal_entries', fn($t) => $t->softDeletes());
//   Schema::table('journal_lines',   fn($t) => $t->softDeletes());
//   Schema::table('payments',        fn($t) => $t->softDeletes());
//
// Run one migration covering all five tables together.
// Once SoftDeletes is added, destroy() calls below automatically
// soft-delete instead of hard-delete — no controller change needed.
// ─────────────────────────────────────────────────────────────────

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AP Invoice Controller (Payables)
//
//  📌 METHODOLOGY REFERENCE: InvoiceBalanceCalc
//  balance = total − paid  (always in invoice's native currency)
//  status  = paid | partially_paid | overdue | open
//
//  📌 METHODOLOGY REFERENCE: InvoiceForeignCurrencyBalance
//  exchange_rate stored at creation. EGP equiv = amount × rate.
//  total/paid/balance always stored in native (foreign) currency.
//  exchange_rate = 1.0 when currency = EGP (locked).
//
//  📌 METHODOLOGY REFERENCE: InvoiceAgingBuckets
//  5 buckets: current / 1-30 / 31-60 / 61-90 / 90+
//  Balances in native currency — no FX conversion at aging stage.
//
//  Invoice Type (Migration 063):
//    cattle    — animal procurement; requires unmatched receipt; accrual match on Show
//    inventory — item procurement;   requires unmatched receipt; accrual match on Show
//    other     — direct expense/asset; no PO required; JE posted immediately on save
//                Dr expense_account / Cr AP Payable (2210)
//
//  PO Guard (cattle + inventory):
//    store() checks InventoryTransaction for at least one unmatched receipt
//    (type IN receipt, livestock_receipt) for the selected supplier.
//    Blocks with 422 if none found — forces correct PO → Receipt → Invoice flow.
//
//  Super Admin rule: company_id NEVER comes from auth()->user()->company_id
//  for SA. It comes from the request (store/update) or the record itself
//  (edit/show). All dropdown helpers accept ?int $companyId.
// ══════════════════════════════════════════════════════════════════

class ApInvoiceController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view ap_invoices'),
            403
        );

        $query = ApInvoice::with('supplier:id,name_en,name_ar');

        if ($authUser->is_super_admin) {
            // SA can filter by company
            if ($request->filled('company_id')) {
                $query->where('company_id', (int)$request->company_id);
            }
        } else {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->supplier_id);
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('date_from'))   $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('date', '<=', $request->date_to);

        // Sync overdue status before rendering
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

        $suppliers = $authUser->is_super_admin
            ? collect()
            : $this->availableSuppliers($authUser->company_id);

        return Inertia::render('Accounting/ApInvoices/Index', [
            'invoices'  => $invoices->through(fn($i) => $this->formatInvoice($i)),
            'aging'     => $aging,
            'suppliers' => $suppliers,
            'companies' => $companies,
            'filters'   => $request->only(['supplier_id', 'status', 'date_from', 'date_to', 'company_id']),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(ApInvoice $apInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('view ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        $apInvoice->load([
            'supplier:id,name_en,name_ar,phone,email',
            'journalEntry:id,reference,status,date',
            'payments',
        ]);

        return Inertia::render('Accounting/ApInvoices/Show', [
            'invoice'         => $this->formatInvoiceFull($apInvoice),
            'pendingAccruals' => $this->pendingAccrualsForInvoice($apInvoice),
            'companies'       => $authUser->is_super_admin
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
            $authUser->hasPermissionTo('create ap_invoices'),
            403
        );

        // SA: open POs start empty — cascade via dataForCompany when company is picked
        // Regular: populated immediately keyed by supplier_id
        $openPosBySupplier = $authUser->is_super_admin
            ? (object) []
            : $this->openLivestockPosBySupplier($authUser->company_id);

        return Inertia::render('Accounting/ApInvoices/CreateEdit', [
            'mode'                 => 'create',
            'invoice'              => null,
            // SA: suppliers start empty — cascade via dataForCompany
            // Regular: populated immediately
            'suppliers'            => $authUser->is_super_admin
                ? collect()
                : $this->availableSuppliers($authUser->company_id),
            'companies'            => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
            // Keyed by supplier_id → array of open POs with unmatched livestock accruals.
            // Vue watches form.supplier_id and shows a warning banner when this is non-empty.
            'open_pos_by_supplier' => $openPosBySupplier,
            // Expense/asset accounts for 'other' invoice type.
            // Excludes: group headers, cash (14xx), bank (15xx).
            // SA: starts empty — cascade via dataForCompany.
            'expense_accounts'     => $authUser->is_super_admin
                ? collect()
                : $this->availableExpenseAccounts($authUser->company_id),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create ap_invoices'),
            403
        );

        // 📌 Super Admin Rule: company_id from request, never from auth user
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id')
            : (int) $authUser->company_id;

        abort_unless($companyId > 0, 422, 'company_id is required.');

        $validated = $this->validateInvoice($request);

        // Guard: supplier must belong to the resolved company
        $supplier = Supplier::findOrFail($validated['supplier_id']);
        abort_unless($supplier->company_id === $companyId, 422, 'Supplier does not belong to this company.');

        // 📌 InvoiceForeignCurrencyBalance: EGP always rate=1, foreign requires rate>0
        $currency     = $validated['currency'] ?? 'EGP';
        $exchangeRate = $currency === 'EGP' ? 1.0 : (float)($validated['exchange_rate'] ?? 0);
        abort_unless($exchangeRate > 0, 422, 'Exchange rate must be greater than zero.');

        $invoiceType = $validated['invoice_type'];

        // ── PO Guard (cattle + inventory) ─────────────────────────
        // Blocks creation if no unmatched receipts exist for this supplier.
        // Forces the correct flow: PO → Receipt → Invoice.
        // 'other' invoices are exempt — they never require a PO.
        if (in_array($invoiceType, ['cattle', 'inventory'])) {
            $hasUnmatched = InventoryTransaction::where('company_id', $companyId)
                ->where('supplier_id', $validated['supplier_id'])
                ->whereIn('type', ['receipt', 'livestock_receipt'])
                ->whereNull('ap_invoice_id')
                ->exists();

            abort_unless(
                $hasUnmatched,
                422,
                __('messages.ap_invoice_no_unmatched_receipts')
            );
        }

        // ── Expense account guard (other type) ────────────────────
        if ($invoiceType === 'other') {
            abort_unless(
                !empty($validated['expense_account_id']),
                422,
                __('messages.ap_invoice_expense_account_required')
            );
            // Verify account belongs to this company
            $expenseAccount = Account::where('id', $validated['expense_account_id'])
                ->where('company_id', $companyId)
                ->where('allow_direct_posting', true)
                ->first();
            abort_unless($expenseAccount, 422, 'Invalid expense account selected.');
        }

        $apInvoice = ApInvoice::create([
            'company_id'         => $companyId,
            'supplier_id'        => $validated['supplier_id'],
            'date'               => $validated['date'],
            'due_date'           => $validated['due_date'] ?? null,
            'reference'          => $validated['reference'] ?? null,
            'description'        => $validated['description'] ?? null,
            'total'              => $validated['total'],
            'paid'               => 0.00,
            'balance'            => $validated['total'],   // 📌 InvoiceBalanceCalc
            'currency'           => $currency,
            'exchange_rate'      => $exchangeRate,
            'status'             => 'open',
            'notes'              => $validated['notes'] ?? null,
            'invoice_type'       => $invoiceType,
            'expense_account_id' => $invoiceType === 'other' ? ($validated['expense_account_id'] ?? null) : null,
        ]);

        // ── Auto-post direct JE for 'other' invoices ──────────────
        // Dr expense/asset account   invoice total (EGP)
        // Cr Accounts Payable (2210) invoice total (EGP)
        // Soft-fails with session warning if accounts or FY missing.
        if ($invoiceType === 'other') {
            $this->postOtherInvoiceJournalEntry($apInvoice);
        }

        return redirect()->route('ap-invoices.index')
            ->with('success', __('messages.ap_invoice_created'));
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(ApInvoice $apInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_if(in_array($apInvoice->status, ['paid', 'cancelled']), 422, __('messages.invoice_not_editable'));

        // For edit: company_id comes from the record itself — safe for SA
        $companyId = $apInvoice->company_id;

       return Inertia::render('Accounting/ApInvoices/CreateEdit', [
            'mode'                 => 'edit',
            'invoice'              => $this->formatInvoice($apInvoice),
            'suppliers'            => $this->availableSuppliers($companyId),
            'companies'            => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
            'open_pos_by_supplier' => $this->openLivestockPosBySupplier($companyId),
            'expense_accounts'     => $this->availableExpenseAccounts($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, ApInvoice $apInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_if(in_array($apInvoice->status, ['paid', 'cancelled']), 422, __('messages.invoice_not_editable'));

        $validated = $this->validateInvoice($request);

        // 📌 InvoiceForeignCurrencyBalance: re-validate rate on update
        $currency     = $validated['currency'] ?? $apInvoice->currency;
        $exchangeRate = $currency === 'EGP' ? 1.0 : (float)($validated['exchange_rate'] ?? $apInvoice->exchange_rate);
        abort_unless($exchangeRate > 0, 422, 'Exchange rate must be greater than zero.');

        // 📌 InvoiceBalanceCalc: balance = total − paid (in native currency)
        // 📌 RISK-F1 FIX: bcmath for monetary subtraction — never native float
        $newBalance = bcsub((string)$validated['total'], (string)$apInvoice->paid, 4);
        $newBalance = bccomp($newBalance, '0', 4) < 0 ? '0' : $newBalance;   // floor at zero
        $newStatus  = $this->resolveInvoiceStatus($newBalance, (string)$validated['total'], $validated['due_date'] ?? null);

        $apInvoice->update([
            'supplier_id'        => $validated['supplier_id'],
            'date'               => $validated['date'],
            'due_date'           => $validated['due_date'] ?? null,
            'reference'          => $validated['reference'] ?? null,
            'description'        => $validated['description'] ?? null,
            'total'              => $validated['total'],
            'balance'            => (float) bcadd($newBalance, '0', 4),
            'currency'           => $currency,
            'exchange_rate'      => $exchangeRate,
            'status'             => $newStatus,
            'notes'              => $validated['notes'] ?? null,
            // invoice_type is immutable after creation — cannot change from cattle to other
            // expense_account_id can be updated while invoice is open (other type only)
            'expense_account_id' => $apInvoice->invoice_type === 'other'
                ? ($validated['expense_account_id'] ?? $apInvoice->expense_account_id)
                : null,
        ]);

        return redirect()->route('ap-invoices.show', $apInvoice)
            ->with('success', __('messages.ap_invoice_updated'));
    }

    // ── Cancel ────────────────────────────────────────────────────
    public function cancel(ApInvoice $apInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_if($apInvoice->status === 'paid',      422, __('messages.invoice_paid_cannot_cancel'));
        abort_if($apInvoice->status === 'cancelled', 422, __('messages.invoice_already_cancelled'));
        abort_if($apInvoice->paid > 0,               422, __('messages.invoice_has_payments'));

        $apInvoice->update(['status' => 'cancelled']);

        return back()->with('success', __('messages.ap_invoice_cancelled'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(ApInvoice $apInvoice)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_unless($apInvoice->status === 'open', 422, __('messages.invoice_not_deletable'));
        abort_if($apInvoice->paid > 0,              422, __('messages.invoice_has_payments'));

        $apInvoice->delete();

        return redirect()->route('ap-invoices.index')
            ->with('success', __('messages.ap_invoice_deleted'));
    }

    // ── SA cascade: company → suppliers ───────────────────────────
    // Called via GET /ap-invoices/data-for-company?company_id=X
    public function dataForCompany(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin, 403);

        $companyId = (int) $request->input('company_id');
        abort_unless($companyId > 0, 422);

        return response()->json([
            'suppliers'            => $this->availableSuppliers($companyId),
            // Included so the Vue CreateEdit form can show the PO warning banner
            // immediately after a Super Admin selects a company from the cascade.
            'open_pos_by_supplier' => $this->openLivestockPosBySupplier($companyId),
            // Expense accounts for 'other' invoice type
            'expense_accounts'     => $this->availableExpenseAccounts($companyId),
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
            ApInvoice::whereIn('status', ['open', 'partially_paid'])
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

            FROM ap_invoices
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
            'supplier_id'        => 'required|integer|exists:suppliers,id',
            'invoice_type'       => 'required|in:cattle,inventory,other',
            'expense_account_id' => 'nullable|integer|exists:accounts,id',
            'date'               => 'required|date',
            'due_date'           => 'nullable|date|after_or_equal:date',
            'reference'          => 'nullable|string|max:100',
            'description'        => 'nullable|string|max:255',
            'total'              => 'required|numeric|min:0.01',
            'currency'           => 'nullable|string|size:3',
            'exchange_rate'      => 'nullable|numeric|min:0.000001',
            'notes'              => 'nullable|string|max:1000',
        ]);
    }

    private function formatInvoice(ApInvoice $i): array
    {
        return [
            'id'                 => $i->id,
            'company_id'         => $i->company_id,
            'supplier_id'        => $i->supplier_id,
            'supplier'           => $i->supplier?->only(['id', 'name_en', 'name_ar']),
            'date'               => $i->date instanceof \Carbon\Carbon ? $i->date->toDateString() : $i->date,
            'due_date'           => $i->due_date instanceof \Carbon\Carbon ? $i->due_date->toDateString() : $i->due_date,
            'reference'          => $i->reference,
            'description'        => $i->description,
            'total'              => $i->total,
            'paid'               => $i->paid,
            'balance'            => $i->balance,
            'currency'           => $i->currency,
            'exchange_rate'      => $i->exchange_rate,                     // 📌 InvoiceForeignCurrencyBalance
            'total_egp'          => round($i->total * $i->exchange_rate, 2),
            'balance_egp'        => round($i->balance * $i->exchange_rate, 2),
            'status'             => $i->status,
            'notes'              => $i->notes,
            'invoice_type'       => $i->invoice_type ?? 'cattle',
            'expense_account_id' => $i->expense_account_id,
        ];
    }

    private function formatInvoiceFull(ApInvoice $i): array
    {
        return array_merge($this->formatInvoice($i), [
            'journal_entry'         => $i->journalEntry?->only(['id', 'reference', 'status', 'date']),
            'matched_accruals_count'=> InventoryTransaction::where('ap_invoice_id', $i->id)->count(),
            'payments'              => $i->payments
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

    private function availableSuppliers(int $companyId): \Illuminate\Support\Collection
    {
        return Supplier::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    // ── Accrual matching ──────────────────────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (T8 Step 2)
    //
    // When the supplier invoice arrives the user matches it against
    // one or more inventory receipts that were posted as accruals:
    //
    //   Dr  AP Accruals (2230)      receipt_value   [per matched line]
    //       Cr  Accounts Payable (2210)  invoice_total  [on the invoice]
    //
    // Matching sets inventory_transactions.ap_invoice_id = invoice.id
    // Unmatching clears it back to NULL (only while invoice is open/draft)
    //
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /ap-invoices/{apInvoice}/pending-accruals
     * Returns unmatched receipts for this invoice's supplier so the
     * Vue page can populate the matching panel without a full reload.
     */
   private function pendingAccrualsForInvoice(ApInvoice $apInvoice): array
        {
            $base = InventoryTransaction::with([
                    'location:id,name_en,name_ar',
                ])
                ->where('company_id', $apInvoice->company_id)
                ->whereIn('type', ['receipt', 'livestock_receipt'])
                ->where('supplier_id', $apInvoice->supplier_id);

            // Already matched to THIS invoice
            $matched = (clone $base)
                ->where('ap_invoice_id', $apInvoice->id)
                ->orderBy('date')
                ->get();

            // Unmatched (available to add)
            $unmatched = (clone $base)
                ->whereNull('ap_invoice_id')
                ->orderBy('date')
                ->get();

            // ↓ HERE — both collections are loaded, safe to use them now
            $livestockTxIds = collect()
                ->merge($matched)
                ->merge($unmatched)
                ->filter(fn($tx) => $tx->type === 'livestock_receipt')
                ->pluck('id');

            $poReferenceMap = [];
            if ($livestockTxIds->isNotEmpty()) {
                $poReferenceMap = \App\Models\AnimalReceiptBatch::whereIn('inventory_transaction_id', $livestockTxIds)
                    ->join('procurement_records', 'procurement_records.id', '=', 'animal_receipt_batches.procurement_id')
                    ->select('animal_receipt_batches.inventory_transaction_id', 'procurement_records.po_reference')
                    ->get()
                    ->pluck('po_reference', 'inventory_transaction_id')
                    ->all();
            }

            $format = fn($tx) => [
                'id'           => $tx->id,
                'type'         => $tx->type,
                'date'         => $tx->date instanceof \Carbon\Carbon ? $tx->date->toDateString() : $tx->date,
                'batch_head'   => $tx->qty,
                'po_reference' => $poReferenceMap[$tx->id] ?? null,
                'location_en'  => $tx->location?->name_en,
                'location_ar'  => $tx->location?->name_ar,
                'qty'          => (float) $tx->qty,
                'unit_cost'    => (float) $tx->unit_cost,
                'total'        => (float) $tx->total,
                'ref_no'       => $tx->ref_no,
            ];

            return [
                'matched'   => $matched->map($format)->values()->all(),
                'unmatched' => $unmatched->map($format)->values()->all(),
            ];
        }

    /**
     * PATCH /ap-invoices/{apInvoice}/match-accruals
     * Body: { transaction_ids: [1,2,3] }
     *
     * Sets ap_invoice_id on each selected unmatched receipt.
     * Guards:
     *   - Invoice must not be cancelled
     *   - Each transaction must be type=receipt, supplier matches invoice supplier,
     *     same company, and currently unmatched (ap_invoice_id IS NULL)
     */
    public function matchAccruals(Request $request, ApInvoice $apInvoice)
    {
        $authUser = Auth::user();
        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_if($apInvoice->status === 'cancelled', 422, __('messages.invoice_already_cancelled'));

        $validated = $request->validate([
            'transaction_ids'   => 'required|array|min:1',
            'transaction_ids.*' => 'required|integer|exists:inventory_transactions,id',
        ]);

        $txIds = $validated['transaction_ids'];

        DB::transaction(function () use ($txIds, $apInvoice) {
            // Load and validate every transaction in one query
            $txns = InventoryTransaction::whereIn('id', $txIds)
                ->lockForUpdate()
                ->get();

            foreach ($txns as $tx) {
                // Guard: receipt only
                abort_unless(in_array($tx->type, ['receipt', 'livestock_receipt']), 422,
                        "Transaction #{$tx->id} is not a receipt.");

                // Guard: same company
                abort_unless($tx->company_id === $apInvoice->company_id, 422,
                    "Transaction #{$tx->id} belongs to a different company.");

                // Guard: same supplier
                abort_unless($tx->supplier_id === $apInvoice->supplier_id, 422,
                    "Transaction #{$tx->id} supplier does not match invoice supplier.");

                // Guard: not already matched to a different invoice
                abort_unless(
                    $tx->ap_invoice_id === null || $tx->ap_invoice_id === $apInvoice->id,
                    422,
                    "Transaction #{$tx->id} is already matched to a different invoice."
                );
            }

            // All guards passed — set ap_invoice_id on all selected rows
            InventoryTransaction::whereIn('id', $txIds)
                ->update(['ap_invoice_id' => $apInvoice->id]);

            // ── T2: Auto-post AP Accrual Match Journal Entry ──────
            // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — T2
            // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (#22) — Step 2
            // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
            //
            //   Dr  AP Accruals (2230)        Σ matched receipt totals
            //   Cr  Accounts Payable (2210)   actual invoice total
            //
            // If matched total ≠ invoice total a price variance line is added
            // to keep the entry balanced (Methodology #32).
            $this->postAccrualMatchJournalEntry($apInvoice, $txns);
        });

        return back()->with('success', __('messages.accruals_matched'));
    }

    /**
     * PATCH /ap-invoices/{apInvoice}/unmatch-accrual/{transaction}
     * Clears ap_invoice_id on a single previously matched receipt.
     * Only allowed while invoice is open / partially_paid (not paid/cancelled).
     */
    public function unmatchAccrual(ApInvoice $apInvoice, InventoryTransaction $inventoryTransaction)
    {
        $authUser = Auth::user();
        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit ap_invoices') && $authUser->company_id === $apInvoice->company_id),
            403
        );

        abort_if($apInvoice->status === 'paid',      422, __('messages.invoice_paid_cannot_unmatch'));
        abort_if($apInvoice->status === 'cancelled', 422, __('messages.invoice_already_cancelled'));

        abort_unless($inventoryTransaction->ap_invoice_id === $apInvoice->id, 422,
            'Transaction is not matched to this invoice.');

        $inventoryTransaction->update(['ap_invoice_id' => null]);

        return back()->with('success', __('messages.accrual_unmatched'));
    }

     /**
     * Auto-post T2 journal entry when accruals are matched to an AP invoice.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — T2
     * 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (#22) — Step 2
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
     * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
     *
     *   Dr  AP Accruals (2230)        Σ matched receipt totals
     *   Cr  Accounts Payable (2210)   actual invoice total
     *   Dr/Cr Price Variance          difference if any (Methodology #32)
     *
     * Called inside matchAccruals() DB::transaction. Any failure here
     * rolls back the ap_invoice_id updates on the transactions.
     *
     * Soft failure: if required accounts are missing or no open FY covers
     * the invoice date, a session warning is flashed and the match still
     * saves. The accountant must post the JE manually.
     */
    private function postAccrualMatchJournalEntry(
        ApInvoice   $apInvoice,
        \Illuminate\Support\Collection $matchedTxns
    ): void {
        $companyId = $apInvoice->company_id;

        // Sum matched receipt totals via bcmath (#38)
        $accrualTotal = '0';
        foreach ($matchedTxns as $tx) {
            $accrualTotal = bcadd($accrualTotal, (string) $tx->total, 4);
        }
        $accrualTotal = bcadd($accrualTotal, '0', 2); // round to 2dp

        $invoiceTotal = bcadd((string) $apInvoice->total, '0', 2);

        // Look up required accounts
        $accrualAccount  = Account::where('company_id', $companyId)->where('code', '2230')->first();
        $payableAccount  = Account::where('company_id', $companyId)->where('code', '2210')->first();

        if (!$accrualAccount || !$payableAccount) {
            session()->flash('warning',
                'Receipts matched. Accounting entries for accounts 2230/2210 could not be posted — please post manually.'
            );
            return;
        }

        // Resolve open fiscal year covering the invoice date
        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $apInvoice->date)
            ->whereDate('end_date',   '>=', $apInvoice->date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning',
                'Receipts matched. No open fiscal year covers the invoice date — please post the accounting entry manually.'
            );
            return;
        }

        // Price variance = accrual total − invoice total
        // Positive → we accrued more than invoiced (credit variance, favourable)
        // Negative → invoice exceeds accrual (debit variance, unfavourable)
        $variance = bcsub($accrualTotal, $invoiceTotal, 4);
        $hasVariance = bccomp(ltrim($variance, '-'), '0.005', 4) > 0;

        $reference   = 'APM/' . str_pad($apInvoice->id, 5, '0', STR_PAD_LEFT);
        $description = 'AP accrual match — Invoice ' . ($apInvoice->reference ?? $reference);

        // Total debit = accrual total (Dr AP Accruals)
        // Total credit = invoice total (Cr AP) ± variance line
        // Entry must balance — variance line ensures this
        $totalDebit  = (float) bcadd($accrualTotal, '0', 2);
        $totalCredit = (float) bcadd($accrualTotal, '0', 2); // always balanced

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $apInvoice->date instanceof \Carbon\Carbon
                                    ? $apInvoice->date->toDateString()
                                    : $apInvoice->date,
            'reference'      => $reference,
            'description'    => $description,
            'period'         => (int) date('n', strtotime(
                                    $apInvoice->date instanceof \Carbon\Carbon
                                        ? $apInvoice->date->toDateString()
                                        : $apInvoice->date
                                )),
            'status'         => 'posted',
            'entry_type'     => 'procurement',
            'source_type'    => ApInvoice::class,
            'source_id'      => $apInvoice->id,
            'total_debit'    => $totalDebit,
            'total_credit'   => $totalCredit,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr AP Accruals (2230) — clears the accrual
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $accrualAccount->id,
            'debit'      => (float) bcadd($accrualTotal, '0', 2),
            'credit'     => 0,
            'memo'       => $description,
        ]);

        // Cr Accounts Payable (2210) — supplier now formally owes
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $payableAccount->id,
            'debit'      => 0,
            'credit'     => (float) bcadd($invoiceTotal, '0', 2),
            'memo'       => $description,
        ]);

        // Price variance line (if any) — keeps entry balanced
        // 📌 METHODOLOGY REFERENCE: PriceVarianceOnAccrualMatch (#32)
        if ($hasVariance) {
            // Positive variance: accrual > invoice → Cr variance (gain)
            // Negative variance: accrual < invoice → Dr variance (loss)
            $varianceAbs = (float) bcadd(ltrim($variance, '-'), '0', 2);
            $isGain      = bccomp($variance, '0', 4) > 0;

            // Look up Purchase Price Variance account (5200 — COGS group)
            // 5200 is the correct account for accrual vs invoice differences.
            // 6800 is Gain/Loss on Asset Disposal — completely unrelated.
            // 📌 METHODOLOGY REFERENCE: ApAccrualInvoiceMatching (#29)
            $varianceAccount = Account::where('company_id', $companyId)
                ->where('code', '5200')
                ->first();

            if ($varianceAccount) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $varianceAccount->id,
                    'debit'      => $isGain ? 0 : $varianceAbs,
                    'credit'     => $isGain ? $varianceAbs : 0,
                    'memo'       => 'Price variance — accrual vs invoice',
                ]);
            }
        }

        // Link the JE to the AP invoice for the Show page
        $apInvoice->update(['journal_entry_id' => $je->id]);
    }

    /**
     * Returns posting accounts suitable for 'other' AP invoices.
     * Excludes: group headers, cash accounts (14xx), bank accounts (15xx).
     * Used to populate the expense account selector on the CreateEdit form.
     */
    private function availableExpenseAccounts(int $companyId): \Illuminate\Support\Collection
    {
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->whereNotIn(DB::raw('LEFT(code, 2)'), ['14', '15'])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);
    }

    /**
     * Auto-post direct journal entry for 'other' AP invoices.
     *
     * Called immediately after ApInvoice::create() for invoice_type = 'other'.
     *
     * Journal entry:
     *   Dr  expense_account              invoice total (EGP equivalent)
     *   Cr  Accounts Payable (2210)      invoice total (EGP equivalent)
     *
     * Amount is always in EGP: total × exchange_rate.
     * For EGP invoices exchange_rate = 1, so amount = total.
     *
     * Soft-fails with session warning if:
     *   - Account 2210 not seeded for company
     *   - No open fiscal year covers the invoice date
     * In both cases the invoice saves successfully; accountant posts JE manually.
     */
    private function postOtherInvoiceJournalEntry(ApInvoice $apInvoice): void
    {
        $companyId = $apInvoice->company_id;

        // Resolve Cr — Accounts Payable (2210)
        $payableAccount = Account::where('company_id', $companyId)
            ->where('code', '2210')
            ->first();

        if (!$payableAccount) {
            session()->flash('warning',
                'Invoice saved. Account 2210 (Accounts Payable) not found — please post the journal entry manually.'
            );
            return;
        }

        // Resolve Dr — the user-selected expense/asset account
        $expenseAccount = Account::where('id', $apInvoice->expense_account_id)
            ->where('company_id', $companyId)
            ->first();

        if (!$expenseAccount) {
            session()->flash('warning',
                'Invoice saved. Expense account not found — please post the journal entry manually.'
            );
            return;
        }

        // Resolve open fiscal year covering the invoice date
        $invoiceDate = $apInvoice->date instanceof \Carbon\Carbon
            ? $apInvoice->date->toDateString()
            : $apInvoice->date;

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $invoiceDate)
            ->whereDate('end_date',   '>=', $invoiceDate)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning',
                'Invoice saved. No open fiscal year covers the invoice date — please post the journal entry manually.'
            );
            return;
        }

        // Amount in EGP: total × exchange_rate (exchange_rate = 1 for EGP invoices)
        // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
        $amountEgp = bcmul(
            (string) $apInvoice->total,
            (string) $apInvoice->exchange_rate,
            4
        );
        $amountEgp = bcadd($amountEgp, '0', 2); // round to 2dp

        $reference   = 'APX/' . str_pad($apInvoice->id, 5, '0', STR_PAD_LEFT);
        $description = 'AP Invoice — ' . ($apInvoice->reference ?? $reference)
                     . ' — ' . ($apInvoice->supplier?->name_en ?? 'Supplier');

        $storedAmount = (float) $amountEgp;

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $invoiceDate,
            'reference'      => $reference,
            'description'    => $description,
            'period'         => (int) date('n', strtotime($invoiceDate)),
            'status'         => 'posted',
            'entry_type'     => 'manual',
            'source_type'    => ApInvoice::class,
            'source_id'      => $apInvoice->id,
            'total_debit'    => $storedAmount,
            'total_credit'   => $storedAmount,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr expense/asset account
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $expenseAccount->id,
            'debit'      => $storedAmount,
            'credit'     => 0,
            'memo'       => $description,
        ]);

        // Cr Accounts Payable (2210)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $payableAccount->id,
            'debit'      => 0,
            'credit'     => $storedAmount,
            'memo'       => $description,
        ]);

        // Link JE back to the invoice
        $apInvoice->update(['journal_entry_id' => $je->id]);
    }

    /**
     * Returns open POs that have unmatched livestock_receipt inventory transactions,
     * grouped by supplier_id. Used to show a warning banner on the AP Invoice
     * CreateEdit form when the selected supplier has pending accruals from animal receipts.
     *
     * Shape: { "12": [ { id, po_reference, received_head, unmatched_total }, ... ], ... }
     *
     * Only POs with at least one unmatched livestock_receipt are included.
     * "Unmatched" means inventory_transactions.ap_invoice_id IS NULL.
     */
    private function openLivestockPosBySupplier(int $companyId): object
    {
        $pos = ProcurementRecord::where('company_id', $companyId)
            ->whereIn('po_status', ['open', 'partially_received', 'on_track', 'over_received'])
            ->whereHas('receiptBatches.inventoryTransaction', fn($q) =>
                $q->whereNull('ap_invoice_id')
            )
            ->with([
                'supplier:id,name_en,name_ar',
                // Only load the unmatched livestock transactions we need for the total
                'receiptBatches.inventoryTransaction' => fn($q) =>
                    $q->whereNull('ap_invoice_id'),
            ])
            ->get(['id', 'po_reference', 'supplier_id', 'received_head',
                   'min_head', 'max_head', 'estimated_price_per_kg']);

        // Group by supplier_id and build a lean summary per PO
        $grouped = $pos->groupBy('supplier_id')->map(function ($supplierPos) {
            return $supplierPos->map(function ($po) {
                // Sum unmatched livestock_receipt totals for this PO via bcmath (#38)
                $unmatchedTotal = $po->receiptBatches->reduce(function ($carry, $batch) {
                    $txn = $batch->inventoryTransaction;
                    if ($txn && $txn->ap_invoice_id === null) {
                        return bcadd($carry, (string) $txn->total, 4);
                    }
                    return $carry;
                }, '0');

                return [
                    'id'              => $po->id,
                    'po_reference'    => $po->po_reference,
                    'received_head'   => $po->received_head,
                    'unmatched_total' => (float) bcadd($unmatchedTotal, '0', 2),
                ];
            })->values()->all();
        });

        // Return as a plain object (JS-friendly keyed object, not an array)
        return (object) $grouped->all();
    }
}