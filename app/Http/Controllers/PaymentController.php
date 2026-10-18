<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Payment Controller
//
//  📌 METHODOLOGY REFERENCE: InvoiceBalanceCalc            (#2)
//  📌 METHODOLOGY REFERENCE: CheckCollectionLifecycle       (#30)
//  📌 METHODOLOGY REFERENCE: NonPartnerPaymentClassification (#31)
//  📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation  (#46)
//
//  MULTI-INVOICE ALLOCATION (Migration 064):
//    One payment can settle multiple invoices simultaneously.
//    Each invoice gets its own allocation row in payment_allocations.
//    Σ allocations must equal payment.amount (±0.005 bcmath tolerance).
//    Each allocation independently calls applyPaymentToInvoice() with
//    its specific allocated_amount — not the total payment amount.
//
//  BACKWARD COMPATIBILITY:
//    payments.ar_invoice_id and payments.ap_invoice_id are deprecated
//    but kept for existing records. New payments use payment_allocations
//    exclusively. formatPayment() reads from allocations first, falls
//    back to the old FK columns for legacy display.
//
//  THREE TABS on the Vue page:
//    1. Collections  — incoming money (customer / non-partner)
//    2. Payments     — outgoing money (supplier / non-partner / expense)
//    3. Check Register — all checks grouped by bank with lifecycle
// ══════════════════════════════════════════════════════════════════

class PaymentController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolvePaymentCompanyId($authUser, $request);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view payments'),
            403
        );

        // ── Collections ───────────────────────────────────────────
        $colQuery = Payment::with([
            'allocations.arInvoice.customer:id,name_en,name_ar',
            'bankAccount:id,code,name_en,name_ar',
        ])->where('type', 'incoming');

        if (!$authUser->is_super_admin) $colQuery->where('company_id', $authUser->company_id);
        else if ($companyId)            $colQuery->where('company_id', $companyId);

        $this->applyCommonFilters($colQuery, $request, 'collection');

        $collections = $colQuery->orderByDesc('date')->paginate(50, ['*'], 'col_page')
            ->withQueryString();

        // ── Payments ──────────────────────────────────────────────
        $payQuery = Payment::with([
            'allocations.apInvoice.supplier:id,name_en,name_ar',
            'bankAccount:id,code,name_en,name_ar',
            'expenseAccount:id,code,name_en,name_ar',
        ])->where('type', 'outgoing');

        if (!$authUser->is_super_admin) $payQuery->where('company_id', $authUser->company_id);
        else if ($companyId)            $payQuery->where('company_id', $companyId);

        $this->applyCommonFilters($payQuery, $request, 'payment');

        $payments = $payQuery->orderByDesc('date')->paginate(50, ['*'], 'pay_page')
            ->withQueryString();

        // ── Check Register ────────────────────────────────────────
        $checkSummary = $this->buildCheckSummary($companyId ?? ($authUser->company_id ?? null));

        // ── Rejected checks alert ─────────────────────────────────
        $rejectedChecks = Payment::where('type', 'incoming')
            ->where('check_status', 'rejected')
            ->when(!$authUser->is_super_admin, fn($q) => $q->where('company_id', $authUser->company_id))
            ->when($companyId && $authUser->is_super_admin, fn($q) => $q->where('company_id', $companyId))
            ->with(['allocations.arInvoice.customer:id,name_en,name_ar'])
            ->orderBy('check_rejection_date')
            ->get()
            ->map(fn($p) => $this->formatPayment($p));

        // ── KPIs ──────────────────────────────────────────────────
        $kpis = $this->buildKpis($companyId ?? ($authUser->company_id ?? null));

        return Inertia::render('Accounting/Payments/Index', [
            'collections'    => $collections->through(fn($p) => $this->formatPayment($p)),
            'payments'       => $payments->through(fn($p) => $this->formatPayment($p)),
            'rejectedChecks' => $rejectedChecks,
            'checkSummary'   => $checkSummary,
            'kpis'           => $kpis,
            'filters'        => $request->only([
                'company_id','date_from','date_to','method',
                'payment_category','check_status',
            ]),
            'companies'       => [],   // QA-011: no company dropdown — Super Admin works in the "Entered" company
            'suppliers'       => $this->availableSuppliers($companyId ?? ($authUser->company_id ?? null)),
            'customers'       => $this->availableCustomers($companyId ?? ($authUser->company_id ?? null)),
            'bankAccounts'    => $this->availableBankAccounts($companyId ?? ($authUser->company_id ?? null)),
            'expenseAccounts'        => $this->availableExpenseAccounts($companyId ?? ($authUser->company_id ?? null)),
            'equityPostingAccounts'      => $this->availableEquityPostingAccounts($companyId ?? ($authUser->company_id ?? null)),
            'liabilityPostingAccounts' => $this->availableLiabilityPostingAccounts($companyId ?? ($authUser->company_id ?? null)),
            'assetPostingAccounts'    => $this->availableAssetPostingAccounts($companyId ?? ($authUser->company_id ?? null)),
            'employeeCollectionAccounts1700' => $this->availablePostingAccountsByRootCode($companyId ?? ($authUser->company_id ?? null), '1700'),
            'otherCreditorAccounts2250'      => $this->availablePostingAccountsByRootCode($companyId ?? ($authUser->company_id ?? null), '2250'),
            'fiscalYears'             => $this->availableFiscalYears($companyId ?? ($authUser->company_id ?? null)),
            'openApInvoices'  => $this->openApInvoices($companyId ?? ($authUser->company_id ?? null)),
            'openArInvoices'  => $this->openArInvoices($companyId ?? ($authUser->company_id ?? null)),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // STORE — create a new collection or payment
    // 📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation (#46)
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser  = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create payments'),
            403
        );

        $companyId = $this->resolvePaymentCompanyId($authUser, $request, true);

        $this->sanitizePaymentNullables($request);
        $validated = $this->validatePaymentStore($request);
        $this->assertDateWithinFiscalYears($validated['date'], $companyId);

        // 📌 InvoiceForeignCurrencyBalance — EGP rate always 1.0
        if (($validated['currency'] ?? 'EGP') === 'EGP') {
            $validated['exchange_rate'] = 1.0;
        }

        // QA-014 — the exchange rate must really be applied.
        // amount is in the payment's own currency; the books are in EGP.
        $payCurrency = $validated['currency'] ?? 'EGP';
        if ($payCurrency !== 'EGP' && ! ((float) ($validated['exchange_rate'] ?? 0) > 0)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'exchange_rate' => __('Exchange rate is required for foreign currency payments.'),
            ]);
        }
        $payRate   = $payCurrency === 'EGP' ? '1' : sprintf('%.6F', (float) $validated['exchange_rate']);
        $amountEgp = \App\Support\Money::round(bcmul((string) $validated['amount'], $payRate, 6), 2);

        $this->assertBankNameIfRequired($validated);
        $this->assertExpenseAccountMatchesCategory($validated, $companyId);

        // ── bank_account_id required for ALL methods ──────────────
        // Cash → must be a 14xx safe account
        // Bank Transfer / Deposit / Check → must be a 15xx bank account
        $this->assertAccountSelectedAndCorrectType($validated, $companyId);

        // ── Allocation validation ─────────────────────────────────
        // 📌 MultiInvoicePaymentAllocation
        // allocations[] is required for customer_collection and supplier_payment.
        // Each element: { invoice_id: int, allocated_amount: numeric }
        // Σ allocated_amount must equal payment amount (±0.005 tolerance).
        $allocations = $validated['allocations'] ?? [];
        $category    = $validated['payment_category'];

        if (in_array($category, ['customer_collection', 'supplier_payment']) && empty($allocations)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'allocations' => __('At least one invoice must be selected.'),
            ]);
        }

        if (!empty($allocations)) {
            $this->assertAllocationsBalance(
                $allocations, (float) $validated['amount'], $category, $payCurrency, $payRate, (int) $companyId
            );
        }

        // ── Balance sufficiency check ─────────────────────────────
        // Always enforced now — bank_account_id is required for all methods
        $this->assertSufficientAccountBalance(
            (int) $validated['bank_account_id'],
            (float) $amountEgp,          // QA-014: the bank/cash account is kept in EGP
            $companyId
        );

        DB::transaction(function () use ($validated, $companyId, $allocations) {

            $category = $validated['payment_category'];
            $subType  = $validated['sub_type'] ?? null;

            // 📌 METHODOLOGY REFERENCE: NonPartnerPaymentClassification (#31) — Fix #1
            // General rule: categories ending in '_payment' and expense/other payments are outgoing.
            // Exception: funding_from_shareholders is a sub_type of shareholder_collection (INCOMING).
            // Money received from shareholders as capital injection or shareholder loan is cash IN.
            // Before the fix it was incorrectly classified as outgoing because 'shareholder_payment'
            // ends with '_payment'. The sub_type check overrides the category-level derivation.
            $type = str_ends_with($category, '_payment') || in_array($category, ['expense_payment','other_payment'])
                        ? 'outgoing' : 'incoming';

            if ($subType === 'funding_from_shareholders') {
                $type = 'incoming';
            }

            // Create the payment header
            $payment = Payment::create([
                'company_id'              => $companyId,
                'type'                    => $type,
                'payment_category'        => $category,
                'sub_type'                => $validated['sub_type'] ?? null,
                'sub_type_comment'        => $validated['sub_type_comment'] ?? null,
                'payee_name'              => $validated['payee_name'] ?? null,
                // Legacy FK columns — left null for new payments (use allocations table)
                'ap_invoice_id'           => null,
                'ar_invoice_id'           => null,
                'date'                    => $validated['date'],
                'amount'                  => $validated['amount'],
                'currency'                => $validated['currency'] ?? 'EGP',
                'exchange_rate'           => ($validated['currency'] ?? 'EGP') === 'EGP' ? 1.0 : (float)($validated['exchange_rate'] ?? 1.0),
                'method'                  => $validated['method'],
                'bank_name'               => $validated['bank_name'] ?? null,
                'bank_account_id'         => $validated['bank_account_id'] ?? null,
                'check_number'            => $validated['check_number'] ?? null,
                'check_bank'              => $validated['check_bank'] ?? null,
                'check_drawn_on_acct'     => $validated['check_drawn_on_acct'] ?? null,
                'check_status'            => $validated['method'] === 'cheque' ? 'in_safe' : null,
                'check_received_date'     => $validated['method'] === 'cheque' ? $validated['date'] : null,
                'expense_account_id'      => $validated['expense_account_id'] ?? null,
                'reference'               => $validated['reference'] ?? null,
                'notes'                   => $validated['notes'] ?? null,
            ]);

            // ── Process each invoice allocation ───────────────────
            // 📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation (#46)
            // Each invoice is locked, checked for overpayment, updated,
            // and an allocation row is written — all inside this transaction.
            foreach ($allocations as $alloc) {
                $invoiceId      = (int) $alloc['invoice_id'];
                $allocatedAmount = (string) $alloc['allocated_amount'];

                if ($category === 'customer_collection') {
                    // 📌 RISK-F2 FIX: lockForUpdate() prevents concurrent double-payment
                    $arInv = ArInvoice::lockForUpdate()->findOrFail($invoiceId);
                    $this->applyPaymentToInvoice($arInv, (float)$allocatedAmount, $companyId);

                    PaymentAllocation::create([
                        'payment_id'       => $payment->id,
                        'ar_invoice_id'    => $invoiceId,
                        'ap_invoice_id'    => null,
                        'allocated_amount' => $allocatedAmount,
                    ]);
                } else {
                    // supplier_payment
                    $apInv = ApInvoice::lockForUpdate()->findOrFail($invoiceId);
                    $this->applyPaymentToInvoice($apInv, (float)$allocatedAmount, $companyId);

                    PaymentAllocation::create([
                        'payment_id'       => $payment->id,
                        'ar_invoice_id'    => null,
                        'ap_invoice_id'    => $invoiceId,
                        'allocated_amount' => $allocatedAmount,
                    ]);
                }
            }

            // ── Post journal entry ────────────────────────────────
            // 📌 METHODOLOGY REFERENCE: PaymentJournalEntry (#47)
            $this->postPaymentJournalEntry($payment, $companyId);
        });

        return back()->with('success', __('messages.payment_recorded'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE CHECK STATUS
    // ─────────────────────────────────────────────────────────────
    public function updateCheckStatus(Request $request, Payment $payment)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit payments') &&
             (int) $authUser->company_id === (int) $payment->company_id),
            403
        );

        abort_unless($payment->method === 'cheque', 422, __('messages.not_a_cheque'));

        $validated = $request->validate([
            'new_status'              => ['required', Rule::in(['under_collection', 'collected', 'rejected'])],
            'bank_name'               => 'nullable|string|max:100',
            'bank_account_id'         => 'nullable|integer|exists:accounts,id',
            'check_collection_date'   => 'nullable|date',
            'check_rejection_date'    => 'nullable|date',
            'check_sent_to_bank_date' => 'nullable|date',
        ]);

        // 📌 CheckCollectionLifecycle — valid transitions only
        $allowed = [
            'in_safe'          => ['under_collection'],
            'under_collection' => ['collected', 'rejected'],
            'rejected'         => ['under_collection'],
        ];

        $current = $payment->check_status;
        abort_unless(
            isset($allowed[$current]) && in_array($validated['new_status'], $allowed[$current]),
            422,
            __('messages.invalid_check_transition', [
                'from' => $current,
                'to'   => $validated['new_status'],
            ])
        );

        $updates = ['check_status' => $validated['new_status']];

        if ($validated['new_status'] === 'under_collection') {
            abort_unless(!empty($validated['bank_name']), 422, __('messages.bank_name_required'));
            $updates['bank_name']               = $validated['bank_name'];
            $updates['bank_account_id']         = $validated['bank_account_id'] ?? $payment->bank_account_id;
            $updates['check_sent_to_bank_date'] = $validated['check_sent_to_bank_date'] ?? now()->toDateString();
            if ($current === 'rejected') {
                $updates['recollection_bank_name'] = $validated['bank_name'];
            }
        }

        if ($validated['new_status'] === 'collected') {
            $updates['check_collection_date'] = $validated['check_collection_date'] ?? now()->toDateString();
        }

        if ($validated['new_status'] === 'rejected') {
            $updates['check_rejection_date'] = $validated['check_rejection_date'] ?? now()->toDateString();
        }

        $payment->update($updates);

        return back()->with('success', __('messages.check_status_updated', ['status' => $validated['new_status']]));
    }

    // ─────────────────────────────────────────────────────────────
    // RETURN CHECK TO CUSTOMER
    // 📌 CheckCollectionLifecycle
    // ─────────────────────────────────────────────────────────────
    public function returnCheck(Request $request, Payment $payment)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit payments') &&
             (int) $authUser->company_id === (int) $payment->company_id),
            403
        );

        abort_unless($payment->method === 'cheque', 422, __('messages.not_a_cheque'));
        abort_unless($payment->check_status === 'rejected', 422, __('messages.check_not_rejected'));

        DB::transaction(function () use ($payment) {
            // Reverse all AR invoice allocations
            // 📌 RISK-F2 FIX: lockForUpdate() on each invoice before reversing
            $allocations = $payment->allocations()->with('arInvoice')->get();

            if ($allocations->isNotEmpty()) {
                // New pattern: reverse each allocation
                foreach ($allocations as $alloc) {
                    if ($alloc->ar_invoice_id) {
                        $invoice = ArInvoice::lockForUpdate()->findOrFail($alloc->ar_invoice_id);
                        $this->reversePaymentOnInvoice($invoice, (float)$alloc->allocated_amount);
                    }
                }
            } elseif ($payment->ar_invoice_id) {
                // Legacy fallback: single FK column
                $invoice = ArInvoice::lockForUpdate()->findOrFail($payment->ar_invoice_id);
                $this->reversePaymentOnInvoice($invoice, $payment->amount);
            }

            $payment->update(['check_status' => 'returned_to_customer']);

            // Phase 5: dispatch CheckReturnedEvent for journal reversal entry
            // event(new \App\Events\CheckReturned($payment));
        });

        return back()->with('success', __('messages.check_returned_to_customer'));
    }

    // ─────────────────────────────────────────────────────────────
    // GET CHECKS — JSON endpoint for Check Register tab
    // ─────────────────────────────────────────────────────────────
    public function getChecks(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolvePaymentCompanyId($authUser, $request);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view payments'),
            403
        );

        $query = Payment::where('method', 'cheque')
            ->with([
                'allocations.arInvoice.customer:id,name_en,name_ar',
                'allocations.apInvoice.supplier:id,name_en,name_ar',
            ])
            ->when(!$authUser->is_super_admin, fn($q) => $q->where('company_id', $authUser->company_id))
            ->when($companyId && $authUser->is_super_admin, fn($q) => $q->where('company_id', $companyId));

        if ($request->filled('check_bank'))   $query->where('check_bank', $request->check_bank);
        if ($request->filled('check_status')) $query->where('check_status', $request->check_status);
        if ($request->filled('date_from'))    $query->whereDate('check_received_date', '>=', $request->date_from);
        if ($request->filled('date_to'))      $query->whereDate('check_received_date', '<=', $request->date_to);

        $checks = $query->orderByDesc('check_received_date')->get();

        $grouped = $checks->groupBy(fn($c) => $c->check_bank ?? $c->bank_name ?? __('messages.unknown_bank'))
            ->map(fn($group, $bank) => [
                'bank'             => $bank,
                'total_count'      => $group->count(),
                'total_amount'     => $group->sum('amount'),
                'in_safe'          => $group->where('check_status', 'in_safe')->count(),
                'under_collection' => $group->where('check_status', 'under_collection')->count(),
                'collected'        => $group->where('check_status', 'collected')->count(),
                'rejected'         => $group->where('check_status', 'rejected')->count(),
                'checks'           => $group->map(fn($c) => $this->formatPayment($c))->values(),
            ])->values();

        return response()->json(['grouped' => $grouped]);
    }

    // ─────────────────────────────────────────────────────────────
    // DATA FOR COMPANY — SA cascade
    // ─────────────────────────────────────────────────────────────
    public function dataForCompany(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin, 403);

        $companyId = (int) $request->input('company_id');
        abort_unless($companyId > 0, 422, 'company_id required');

        return response()->json([
            'suppliers'              => $this->availableSuppliers($companyId),
            'customers'              => $this->availableCustomers($companyId),
            'bankAccounts'           => $this->availableBankAccounts($companyId),
            'expenseAccounts'        => $this->availableExpenseAccounts($companyId),
            'equityPostingAccounts'    => $this->availableEquityPostingAccounts($companyId),
            'liabilityPostingAccounts' => $this->availableLiabilityPostingAccounts($companyId),
            'assetPostingAccounts'      => $this->availableAssetPostingAccounts($companyId),
            'employeeCollectionAccounts1700' => $this->availablePostingAccountsByRootCode($companyId, '1700'),
            'otherCreditorAccounts2250'      => $this->availablePostingAccountsByRootCode($companyId, '2250'),
            'fiscalYears'               => $this->availableFiscalYears($companyId),
            'openApInvoices'         => $this->openApInvoices($companyId),
            'openArInvoices'         => $this->openArInvoices($companyId),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY — reverse / delete a payment
    // ─────────────────────────────────────────────────────────────
    public function destroy(Payment $payment)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete payments') &&
             (int) $authUser->company_id === (int) $payment->company_id),
            403
        );

        abort_if(
            $payment->journal_entry_id &&
            $payment->journalEntry?->status === 'posted',
            422,
            __('messages.payment_has_posted_entry')
        );

        abort_if(
            $payment->check_status === 'collected',
            422,
            __('messages.collected_check_cannot_delete')
        );

        DB::transaction(function () use ($payment) {
            // Reverse all allocations
            $allocations = $payment->allocations()->get();

            if ($allocations->isNotEmpty()) {
                foreach ($allocations as $alloc) {
                    if ($alloc->ar_invoice_id) {
                        $inv = ArInvoice::lockForUpdate()->find($alloc->ar_invoice_id);
                        if ($inv) $this->reversePaymentOnInvoice($inv, (float)$alloc->allocated_amount);
                    }
                    if ($alloc->ap_invoice_id) {
                        $inv = ApInvoice::lockForUpdate()->find($alloc->ap_invoice_id);
                        if ($inv) $this->reversePaymentOnInvoice($inv, (float)$alloc->allocated_amount);
                    }
                }
            } else {
                // Legacy fallback
                if ($payment->ap_invoice_id) {
                    $inv = ApInvoice::lockForUpdate()->find($payment->ap_invoice_id);
                    if ($inv) $this->reversePaymentOnInvoice($inv, $payment->amount);
                }
                if ($payment->ar_invoice_id) {
                    $inv = ArInvoice::lockForUpdate()->find($payment->ar_invoice_id);
                    if ($inv) $this->reversePaymentOnInvoice($inv, $payment->amount);
                }
            }

            $payment->delete(); // cascades to payment_allocations via FK
        });

        return back()->with('success', __('messages.payment_deleted'));
    }

    // ═════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════

    /**
     * 📌 MultiInvoicePaymentAllocation (#46)
     *
     * Validates that the sum of all per-invoice allocations equals the
     * total payment amount within bcmath tolerance (±0.005).
     *
     * Uses bcmath throughout — float summation of many small decimals
     * accumulates IEEE 754 error that can falsely fail this guard.
     */
    /**
     * QA-014 — Σ allocations must equal the payment amount, in the PAYMENT's currency.
     * Each allocated_amount is entered in the INVOICE's currency (that is what settles the invoice).
     *   same currency      → used as is
     *   different currency → allocated × invoice rate ÷ payment rate   (rate = EGP per 1 unit; EGP = 1)
     * Example: invoice USD 1,000 (rate 49.50) paid in EGP → 1,000 × 49.50 ÷ 1 = EGP 49,500 expected.
     */
    private function assertAllocationsBalance(
        array $allocations, float $paymentAmount, string $category,
        string $paymentCurrency, string $paymentRate, int $companyId
    ): void {
        $ids  = collect($allocations)->pluck('invoice_id')->map(fn ($v) => (int) $v)->all();
        $model = $category === 'customer_collection' ? ArInvoice::class : ApInvoice::class;
        $invoices = $model::where('company_id', $companyId)->whereIn('id', $ids)
            ->get(['id', 'currency', 'exchange_rate'])->keyBy('id');

        $sum = '0';
        foreach ($allocations as $alloc) {
            $inv = $invoices->get((int) ($alloc['invoice_id'] ?? 0));
            if (! $inv) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'allocations' => __('One of the selected invoices was not found for this company.'),
                ]);
            }
            $amt = (string) ($alloc['allocated_amount'] ?? 0);
            $invCurrency = $inv->currency ?: 'EGP';
            if ($invCurrency !== $paymentCurrency) {
                $invRate = $invCurrency === 'EGP' ? '1' : sprintf('%.6F', (float) ($inv->exchange_rate ?: 1));
                $amt = bcdiv(bcmul($amt, $invRate, 8), $paymentRate, 6);
            }
            $sum = bcadd($sum, $amt, 6);
        }

        $absDiff   = ltrim(bcsub($sum, sprintf('%.6F', $paymentAmount), 6), '-');
        $tolerance = $paymentCurrency === 'EGP' && $invoices->every(fn ($i) => ($i->currency ?: 'EGP') === 'EGP')
            ? '0.0050' : '0.0100';   // a little more room when two currencies are converted

        if (bccomp($absDiff, $tolerance, 6) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'allocations' => __('The sum of allocations (:sum) does not match the payment amount (:amount).', [
                    'sum'    => number_format((float) $sum, 2),
                    'amount' => number_format($paymentAmount, 2),
                ]),
            ]);
        }
    }

    /**
     * 📌 InvoiceBalanceCalc — apply payment to invoice
     * 📌 RISK-F2 FIX: caller must pass a lockForUpdate() row
     * 📌 RISK-F1 FIX: bcmath throughout
     */
    private function applyPaymentToInvoice($invoice, float $amount, int $companyId): void
    {
        abort_unless((int) $invoice->company_id === (int) $companyId, 403);
        abort_if($invoice->status === 'cancelled', 422, __('messages.invoice_cancelled'));

        $amountStr  = (string) $amount;
        $balanceStr = (string) $invoice->balance;
        $tolerance  = '0.0050';

        abort_if(
            bccomp(bcsub($amountStr, $balanceStr, 4), $tolerance, 4) > 0,
            422,
            __('messages.payment_exceeds_balance', [
                'balance' => $invoice->balance,
                'amount'  => $amount,
            ])
        );

        $newPaid    = bcadd((string)$invoice->paid, $amountStr, 4);
        $newBalance = bcsub((string)$invoice->total, $newPaid, 4);
        if (bccomp($newBalance, '0', 4) < 0) $newBalance = '0.0000';

        $invoice->update([
            'paid'    => (float) bcadd($newPaid,    '0', 4),
            'balance' => (float) bcadd($newBalance, '0', 4),
            'status'  => $this->resolveInvoiceStatus($newBalance, (string)$invoice->total, $invoice->due_date),
        ]);
    }

    /**
     * 📌 InvoiceBalanceCalc — reverse payment from invoice
     * 📌 RISK-F2 FIX: caller must pass a lockForUpdate() row
     * 📌 RISK-F1 FIX: bcmath throughout
     */
    private function reversePaymentOnInvoice($invoice, float $amount): void
    {
        $newPaid = bcsub((string)$invoice->paid, (string)$amount, 4);
        if (bccomp($newPaid, '0', 4) < 0) $newPaid = '0.0000';

        $newBalance = bcsub((string)$invoice->total, $newPaid, 4);
        if (bccomp($newBalance, '0', 4) < 0) $newBalance = '0.0000';

        $invoice->update([
            'paid'    => (float) bcadd($newPaid,    '0', 4),
            'balance' => (float) bcadd($newBalance, '0', 4),
            'status'  => $this->resolveInvoiceStatus($newBalance, (string)$invoice->total, $invoice->due_date),
        ]);
    }

    /**
     * 📌 InvoiceBalanceCalc — resolve status from balance
     * 📌 RISK-F1 FIX: bcmath comparisons, string parameters
     */
    private function resolveInvoiceStatus(string $balance, string $total, ?string $dueDate): string
    {
        if (bccomp($balance, '0', 4) <= 0) return 'paid';

        if (
            bccomp($balance, $total, 4) < 0 &&
            (!$dueDate || $dueDate >= now()->toDateString())
        ) {
            return 'partially_paid';
        }

        if ($dueDate && $dueDate < now()->toDateString() && bccomp($balance, '0', 4) > 0) {
            return 'overdue';
        }

        return 'open';
    }

    private function assertBankNameIfRequired(array $validated): void
    {
        $bankMethods = ['bank_transfer', 'cheque', 'bank_deposit'];
        if (in_array($validated['method'], $bankMethods)) {
            abort_unless(
                !empty($validated['bank_name']) || !empty($validated['check_bank']),
                422,
                __('messages.bank_name_required')
            );
        }
    }

    private function sanitizePaymentNullables(Request $request): void
    {
        $nullables = [
            'sub_type','sub_type_comment','payee_name','bank_name',
            'bank_account_id','check_number','check_bank','check_drawn_on_acct',
            'expense_account_id','exchange_rate','reference','notes',
        ];
        foreach ($nullables as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }
    }

    private function validatePaymentStore(Request $request): array
    {
        return $request->validate([
            'payment_category'    => ['required', Rule::in([
                'customer_collection','supplier_payment',
                'employee_collection','employee_payment',
                'shareholder_collection','shareholder_payment',
                'expense_payment','other_collection','other_payment',
            ])],
            'sub_type'            => [
                'nullable',
                'string',
                'max:50',
                Rule::requiredIf(fn () => $request->input('payment_category') === 'shareholder_collection'),
                Rule::when(
                    $request->input('payment_category') === 'shareholder_collection',
                    [Rule::in(['capital_injection', 'shareholder_loan'])],
                    []
                ),
            ],
            'sub_type_comment'    => 'nullable|string|max:255',
            'payee_name'          => 'nullable|string|max:255',
            'date'                => 'required|date',
            'amount'              => 'required|numeric|min:0.01',
            'currency'            => 'nullable|string|size:3',
            'exchange_rate'       => 'nullable|numeric|min:0.000001',
            'method'              => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'bank_deposit'])],
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_id'     => 'nullable|integer|exists:accounts,id',
            'check_number'        => 'nullable|string|max:50',
            'check_bank'          => 'nullable|string|max:100',
            'check_drawn_on_acct' => 'nullable|string|max:50',
            'expense_account_id'  => [
                'nullable',
                'integer',
                'exists:accounts,id',
                Rule::requiredIf(fn () => in_array($request->input('payment_category'), [
                    'shareholder_collection', 'employee_collection',
                    'employee_payment', 'shareholder_payment',
                ], true)),
            ],
            'reference'           => 'nullable|string|max:100',
            'notes'               => 'nullable|string|max:1000',
            // Multi-invoice allocations array
            // Required for categories that settle invoices.
            'allocations'                       => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => in_array($request->input('payment_category'), ['customer_collection', 'supplier_payment'], true)),
                'min:1',
            ],
            'allocations.*.invoice_id'          => 'required|integer',
            'allocations.*.allocated_amount'    => 'required|numeric|min:0.01',
        ]);
    }

    private function applyCommonFilters($query, Request $request, string $tab): void
    {
        if ($request->filled('method'))  $query->where('method', $request->input('method'));
        if ($request->filled('payment_category')) $query->where('payment_category', $request->payment_category);
        if ($request->filled('check_status'))     $query->where('check_status', $request->check_status);
        if ($request->filled('date_from'))        $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))          $query->whereDate('date', '<=', $request->date_to);
    }

    /**
     * 📌 METHODOLOGY REFERENCE: AccountBalanceSufficiencyCheck (#32)
     * 📌 RISK-F1 FIX: bcmath comparison
     */
    private function assertSufficientAccountBalance(int $accountId, float $amount, int $companyId): void
    {
		$moneyType = Request()->input('payment_category');
		if($moneyType == 'customer_collection' || $moneyType == 'employee_collection' || $moneyType == 'shareholder_collection' || $moneyType == 'other_collection'){
			return  ;
		}
        $account = Account::where('id', $accountId)
            ->where('company_id', $companyId)
            ->first(['id', 'code', 'name_en', 'name_ar']);

        if (!$account) return;

        $prefix = substr($account->code, 0, 2);
        if (!in_array($prefix, ['14', '15'])) return;

        $debits = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('jl.account_id', $accountId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.company_id', $companyId)
            ->sum('jl.debit');

        $credits = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('jl.account_id', $accountId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.company_id', $companyId)
            ->sum('jl.credit');

        $rawBalance = bcsub((string)($debits ?? 0), (string)($credits ?? 0), 4);

        $tolerance = '0.0050';
        $shortfall = bcsub((string)$amount, (string)$rawBalance, 4);

        if (bccomp($shortfall, $tolerance, 4) > 0) {
            $name = $account->name_en ?: $account->name_ar ?: $account->code;
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => __('messages.insufficient_account_balance', [
                    'account'   => $name,
                    'available' => number_format((float)$rawBalance, 2),
                    'required'  => number_format($amount, 2),
                ]),
            ]);
        }
    }

    private function buildCheckSummary(?int $companyId): array
    {
        if (!$companyId) return [];

        return Payment::where('method', 'cheque')
            ->where('company_id', $companyId)
            ->selectRaw('
                COALESCE(check_bank, bank_name, "Unknown") as bank,
                COUNT(*) as total,
                SUM(amount) as total_amount,
                SUM(CASE WHEN check_status = "in_safe"           THEN 1 ELSE 0 END) as in_safe,
                SUM(CASE WHEN check_status = "under_collection"  THEN 1 ELSE 0 END) as under_collection,
                SUM(CASE WHEN check_status = "collected"         THEN 1 ELSE 0 END) as collected,
                SUM(CASE WHEN check_status = "rejected"          THEN 1 ELSE 0 END) as rejected
            ')
            ->groupByRaw('COALESCE(check_bank, bank_name, "Unknown")')
            ->orderByDesc('total_amount')
            ->get()
            ->toArray();
    }

    private function buildKpis(?int $companyId): array
    {
        if (!$companyId) return [];

        $base = Payment::where('company_id', $companyId);

        return [
            'total_collections'       => (float) \App\Support\Money::round((string)((clone $base)->where('type', 'incoming')->sum(DB::raw('amount * COALESCE(exchange_rate, 1)')) ?? 0), 2),
            'total_payments'          => (float) \App\Support\Money::round((string)((clone $base)->where('type', 'outgoing')->sum(DB::raw('amount * COALESCE(exchange_rate, 1)')) ?? 0), 2),
            'checks_in_safe'          => (clone $base)->where('check_status', 'in_safe')->count(),
            'checks_under_collection' => (clone $base)->where('check_status', 'under_collection')->count(),
            'checks_rejected'         => (clone $base)->where('check_status', 'rejected')->count(),
        ];
    }

    private function resolvePaymentCompanyId($authUser, Request $request, bool $required = false): ?int
    {
        if ($authUser->is_super_admin) {
            $id = (int) $request->session()->get('selected_company_id', 0);
            if ($required) {
                abort_unless($id > 0, 422, __('messages.company_required_for_super_admin'));
            }
            return $id > 0 ? $id : null;
        }
        return $authUser->company_id;
    }

    private function availableSuppliers(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return \App\Models\Supplier::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id','name_en','name_ar']);
    }

    private function availableCustomers(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return \App\Models\Customer::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id','name_en','name_ar']);
    }

    private function availableBankAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->whereRaw("LEFT(code,2) IN ('14','15')")
            ->orderBy('code')
            ->get(['id','code','name_en','name_ar','currency']);
    }

    private function availableExpenseAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->whereRaw("LEFT(code,1) IN ('3','6')")
            ->orderBy('code')
            ->get(['id','code','name_en','name_ar']);
    }

    /** Postable equity accounts (capital, retained earnings, etc.) for shareholder_collection contra. */
    private function availableEquityPostingAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->where('type', 'equity')
            ->orderBy('code')
            ->get(['id','code','name_en','name_ar']);
    }

    /** Postable liability accounts (e.g. due to shareholder) for shareholder_collection + shareholder_loan. */
    private function availableLiabilityPostingAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->where('type', 'liability')
            ->orderBy('code')
            ->get(['id','code','name_en','name_ar']);
    }

    /** Postable asset accounts for employee_collection contra (e.g. custody / employee receivable). */
    private function availableAssetPostingAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->where('type', 'asset')
            ->orderBy('code')
            ->get(['id','code','name_en','name_ar']);
    }

    /** Root account (exact code) plus every active descendant — all selectable like the tree in COA. */
    private function availablePostingAccountsByRootCode(?int $companyId, string $rootCode): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();

        $all = Account::where('company_id', $companyId)
            ->get(['id', 'parent_id', 'code', 'name_en', 'name_ar', 'is_active']);

        $rootCodeNorm = trim($rootCode);
        $root         = $all->first(fn($a) => trim((string) $a->code) === $rootCodeNorm);
        if (!$root) return collect();

        $descendantIds = [$root->id];
        $queue         = [$root->id];

        while (!empty($queue)) {
            $parentId = array_shift($queue);
            $children = $all->where('parent_id', $parentId)->pluck('id')->all();
            foreach ($children as $childId) {
                if (!in_array($childId, $descendantIds, true)) {
                    $descendantIds[] = $childId;
                    $queue[]         = $childId;
                }
            }
        }

        return $all
            ->whereIn('id', $descendantIds)
            ->filter(fn($a) => (bool) $a->is_active)
            ->sortBy(fn($a) => trim((string) $a->code))
            ->values()
            ->map(fn($a) => [
                'id'      => $a->id,
                'code'    => $a->code,
                'name_en' => $a->name_en,
                'name_ar' => $a->name_ar,
            ]);
    }

    /** All configured fiscal-year ranges for the selected company. */
    private function availableFiscalYears(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return FiscalYear::where('company_id', $companyId)
            ->orderBy('start_date')
            ->get(['id', 'year', 'start_date', 'end_date', 'status'])
            ->map(fn($fy) => [
                'id'         => $fy->id,
                'year'       => $fy->year,
                'start_date' => $fy->start_date?->format('Y-m-d'),
                'end_date'   => $fy->end_date?->format('Y-m-d'),
                'status'     => $fy->status,
            ]);
    }

    private function assertDateWithinFiscalYears(string $date, int $companyId): void
    {
        $exists = FiscalYear::where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();

        if (!$exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date' => __('The date must be within a configured fiscal year.'),
            ]);
        }
    }

    private function assertExpenseAccountMatchesCategory(array $validated, int $companyId): void
    {
        $category = $validated['payment_category'] ?? null;

        $type    = null;
        $message = null;
        $rootCode = null;

        if ($category === 'shareholder_collection') {
            $sub = $validated['sub_type'] ?? null;
            if ($sub === 'capital_injection') {
                $type    = 'equity';
                $message = __('The selected account must be an equity account.');
            } elseif ($sub === 'shareholder_loan') {
                $type    = 'liability';
                $message = __('The selected account must be a liability account.');
            } else {
                return;
            }
        } elseif ($category === 'employee_collection') {
            $message  = __('The selected account must be under account 1700.');
            $rootCode = '1700';
        } elseif (in_array($category, ['employee_payment', 'shareholder_payment'], true)) {
            $message  = __('The selected account must be under account 2250.');
            $rootCode = '2250';
        } else {
            return;
        }

        $id = isset($validated['expense_account_id']) ? (int) $validated['expense_account_id'] : 0;
        if ($id < 1) {
            return;
        }
        $base = Account::where('id', $id)
            ->where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true);

        $ok = $type
            ? (clone $base)->where('type', $type)->exists()
            : ((bool)$rootCode && $this->isAccountUnderRootCode($id, $companyId, $rootCode));

        if (!$ok) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'expense_account_id' => $message,
            ]);
        }
    }

    private function isAccountUnderRootCode(int $accountId, int $companyId, string $rootCode): bool
    {
        $rootNorm = trim($rootCode);

        $accounts = Account::where('company_id', $companyId)
            ->get(['id', 'parent_id', 'code', 'is_active'])
            ->keyBy('id');

        $start = $accounts->get($accountId);
        if (!$start || !(bool) $start->is_active) {
            return false;
        }

        $cursorId = $accountId;
        while ($cursorId) {
            $acc = $accounts->get($cursorId);
            if (!$acc) break;
            if (trim((string) $acc->code) === $rootNorm) return true;
            $cursorId = $acc->parent_id;
        }

        return false;
    }

    private function openApInvoices(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return ApInvoice::where('company_id', $companyId)
            ->whereIn('status', ['open','partially_paid','overdue'])
            ->with('supplier:id,name_en,name_ar')
            ->orderByDesc('date')
            ->get(['id','reference','date','due_date','balance','currency','exchange_rate','supplier_id'])
            ->map(fn($i) => [
                'id'        => $i->id,
                'reference' => $i->reference,
                'date'      => $this->ymd($i->date),
                'due_date'  => $this->ymd($i->due_date),
                'balance'   => $i->balance,
                'currency'  => $i->currency,
                'exchange_rate' => $i->exchange_rate,
                'supplier'  => $i->supplier?->only(['id','name_en','name_ar']),
            ]);
    }

    private function openArInvoices(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();
        return ArInvoice::where('company_id', $companyId)
            ->whereIn('status', ['open','partially_paid','overdue'])
            ->with('customer:id,name_en,name_ar')
            ->orderByDesc('date')
            ->get(['id','reference','date','due_date','balance','currency','exchange_rate','customer_id'])
            ->map(fn($i) => [
                'id'        => $i->id,
                'reference' => $i->reference,
                'date'      => $this->ymd($i->date),
                'due_date'  => $this->ymd($i->due_date),
                'balance'   => $i->balance,
                'currency'  => $i->currency,
                'exchange_rate' => $i->exchange_rate,
                'customer'  => $i->customer?->only(['id','name_en','name_ar']),
            ]);
    }

    /** Date-only string for API/UI (YYYY-MM-DD). */
    private function toDateYmd(null|string|\DateTimeInterface $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d');
    }

    /**
     * 📌 CheckCollectionLifecycle + NonPartnerPaymentClassification + MultiInvoicePaymentAllocation
     * Shape a Payment model into a consistent Vue-ready array.
     * Reads allocations first; falls back to legacy FK columns for old records.
     */
    private function formatPayment(Payment $p): array
    {
        // Build allocations array from pivot table
        $allocations = $p->allocations->map(fn($a) => [
            'id'               => $a->id,
            'invoice_id'       => $a->ar_invoice_id ?? $a->ap_invoice_id,
            'ar_invoice_id'    => $a->ar_invoice_id,
            'ap_invoice_id'    => $a->ap_invoice_id,
            'allocated_amount' => $a->allocated_amount,
            'ar_invoice'       => $a->arInvoice ? [
                'id'        => $a->arInvoice->id,
                'reference' => $a->arInvoice->reference,
                'customer'  => $a->arInvoice->customer?->only(['id','name_en','name_ar']),
            ] : null,
            'ap_invoice'       => $a->apInvoice ? [
                'id'        => $a->apInvoice->id,
                'reference' => $a->apInvoice->reference,
                'supplier'  => $a->apInvoice->supplier?->only(['id','name_en','name_ar']),
            ] : null,
        ])->values()->toArray();

        // Legacy display: if no allocations row but old FK columns exist
        $legacyArInvoice = null;
        $legacyApInvoice = null;
        if (empty($allocations)) {
            if ($p->ar_invoice_id && isset($p->arInvoice)) {
                $legacyArInvoice = [
                    'id'        => $p->arInvoice->id,
                    'reference' => $p->arInvoice->reference,
                    'customer'  => $p->arInvoice->customer?->only(['id','name_en','name_ar']),
                ];
            }
            if ($p->ap_invoice_id && isset($p->apInvoice)) {
                $legacyApInvoice = [
                    'id'        => $p->apInvoice->id,
                    'reference' => $p->apInvoice->reference,
                    'supplier'  => $p->apInvoice->supplier?->only(['id','name_en','name_ar']),
                ];
            }
        }

        return [
            'id'                      => $p->id,
            'type'                    => $p->type,
            'payment_category'        => $p->payment_category,
            'sub_type'                => $p->sub_type,
            'sub_type_comment'        => $p->sub_type_comment,
            'payee_name'              => $p->payee_name,
            'date'                    => $this->toDateYmd($p->date),
            'amount'                  => $p->amount,
            'currency'                => $p->currency ?? 'EGP',
            'exchange_rate'           => $p->exchange_rate ?? 1.0,
            'method'                  => $p->method,
            'bank_name'               => $p->bank_name,
            'bank_account'            => $p->bankAccount?->only(['id','code','name_en','name_ar','currency']),
            'check_number'            => $p->check_number,
            'check_bank'              => $p->check_bank,
            'check_drawn_on_acct'     => $p->check_drawn_on_acct,
            'check_status'            => $p->check_status,
            'check_received_date'     => $this->toDateYmd($p->check_received_date),
            'check_sent_to_bank_date' => $this->toDateYmd($p->check_sent_to_bank_date),
            'check_collection_date'   => $this->toDateYmd($p->check_collection_date),
            'check_rejection_date'    => $this->toDateYmd($p->check_rejection_date),
            'recollection_bank_name'  => $p->recollection_bank_name,
            'reference'               => $p->reference,
            'notes'                   => $p->notes,
            // Multi-invoice allocations (new pattern)
            'allocations'             => $allocations,
            // Legacy single-invoice display (old pattern — read-only)
            'ar_invoice'              => $legacyArInvoice,
            'ap_invoice'              => $legacyApInvoice,
            'expense_account'         => $p->expenseAccount?->only(['id','code','name_en','name_ar']),
        ];
    }

    /**
     * 📌 PaymentJournalEntry (#47)
     * bank_account_id is required for ALL methods.
     * Cash → account code must start with 14 (safe accounts).
     * Bank Transfer / Deposit / Check → must start with 15 (bank accounts).
     */
    private function assertAccountSelectedAndCorrectType(array $validated, int $companyId): void
    {
        if (empty($validated['bank_account_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => __('Please select a cash/bank account for this transaction.'),
            ]);
        }

        $account = Account::where('id', (int)$validated['bank_account_id'])
            ->where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->first(['id','code','name_en','name_ar']);

        abort_unless($account, 422, 'Invalid account selected.');

        $prefix = substr($account->code, 0, 2);
        $method = $validated['method'];

        if ($method === 'cash' && $prefix !== '14') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => __('Cash payments must use a Cash Safe account (14xx). Please select the correct account.'),
            ]);
        }

        if ($method !== 'cash' && $prefix !== '15') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => __('Bank Transfer / Deposit / Check payments must use a Bank account (15xx). Please select the correct account.'),
            ]);
        }
    }

    /**
     * 📌 METHODOLOGY REFERENCE: PaymentJournalEntry (#47)
     *
     * Auto-posts the double-entry journal for every payment/collection.
     *
     * OUTGOING (supplier_payment, expense_payment, etc.):
     *   Dr  AP Payable (2210)  OR  Expense Account   → reduces liability / records expense
     *   Cr  Cash/Bank Account                        → reduces cash
     *
     * INCOMING (customer_collection, etc.):
     *   Dr  Cash/Bank Account                        → increases cash
     *   Cr  AR Receivable (1600)                     → reduces receivable
     *
     * Soft-fails with session warning if fiscal year or contra account missing.
     * All arithmetic uses bcmath (📌 BcmathMonetaryPrecision #38).
     */
    private function postPaymentJournalEntry(Payment $payment, int $companyId): void
    {
        $fy = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $payment->date)
            ->whereDate('end_date',   '>=', $payment->date)
            ->first();

        if (!$fy) {
            session()->flash('warning', 'Payment saved but no open fiscal year covers this date — journal entry not posted.');
            return;
        }

        $cashBankAccount = Account::find($payment->bank_account_id);
        if (!$cashBankAccount) {
            session()->flash('warning', 'Payment saved but cash/bank account not found — journal entry not posted.');
            return;
        }

        // QA-014: payment->amount is in the payment's own currency; the journal is in EGP.
        // amount × exchange rate (rate is 1 for EGP), rounded half-up to 2 decimals.
        $amount     = \App\Support\Money::round(
            bcmul((string) $payment->amount, (string) (($payment->currency ?? 'EGP') === 'EGP' ? 1 : ($payment->exchange_rate ?: 1)), 6), 2
        );
        $isOutgoing = $payment->type === 'outgoing';

        // ── Resolve contra account ────────────────────────────────
        $contraAccount = null;
        if ($payment->payment_category === 'supplier_payment') {
            $contraAccount = Account::where('company_id', $companyId)
                ->where('allow_direct_posting', true)
                ->whereRaw("LEFT(code,4) = '2210'")
                ->first();
        } elseif ($payment->payment_category === 'customer_collection') {
            $contraAccount = Account::where('company_id', $companyId)
                ->where('allow_direct_posting', true)
                ->whereRaw("LEFT(code,4) = '1600'")
                ->first();
        } elseif ($payment->expense_account_id) {
            $contraAccount = Account::find($payment->expense_account_id);
        }

        if (!$contraAccount) {
            session()->flash('warning', 'Payment saved but contra account not found — journal entry not posted.');
            return;
        }

        $prefix    = $isOutgoing ? 'PAY' : 'COL';
        $reference = $prefix . '/' . str_pad($payment->id, 6, '0', STR_PAD_LEFT);

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fy->id,
            'entry_type'     => 'payment',
            'reference'      => $reference,
            'description'    => $reference,
            'date'           => $payment->date,
            'period'         => (int) \Carbon\Carbon::parse($payment->date)->format('n'),
            'notes'          => $payment->notes,
            'status'         => 'posted',
            'posted_at'      => now(),
            'posted_by'      => auth()->id(),
            'total_debit'    => (float) \App\Support\Money::round($amount, 2),
            'total_credit'   => (float) \App\Support\Money::round($amount, 2),
        ]);

        if ($isOutgoing) {
            // Dr contra (AP / expense) — Cr cash/bank
            // Line 1 — outgoing Dr
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $contraAccount->id,
                'debit'      => (float) \App\Support\Money::round($amount, 2),
                'credit'     => 0,
                'memo'       => $reference,
            ]);
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $cashBankAccount->id,
                'debit'      => 0,
                'credit'     => (float) \App\Support\Money::round($amount, 2),
                'memo'       => $reference,
            ]);
        } else {
            // Dr cash/bank — Cr contra (AR)
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $cashBankAccount->id,
                'debit'      => (float) \App\Support\Money::round($amount, 2),
                'credit'     => 0,
                'memo'       => $reference,
            ]);
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $contraAccount->id,
                'debit'      => 0,
                'credit'     => (float) \App\Support\Money::round($amount, 2),
                'memo'       => $reference,
            ]);
        }

        $payment->update(['journal_entry_id' => $je->id]);
    }
}
