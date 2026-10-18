<?php

namespace App\Http\Controllers;

use App\Models\SalesRecord;
use App\Models\SalesLine;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Animal;
use App\Models\SlaughterRecord;
use App\Models\Farm;
use App\Models\Company;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\LivestockIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Sales Controller
//
//  SESSION 22 FIX BLOCK — 5 fixes applied:
//
//  FIX 1 — Animal soft-lock on draft sale (Methodology #27)
//  FIX 2 — Auto AR Invoice on confirmation (Methodology #28)
//  FIX 3 — Slaughter delete rolls animal back to 'active'
//  FIX 4 — Auto invoice number (Methodology #27 sub-formula)
//  FIX 5 — Auto journal entry reference
//
//  EARLIER FIX BLOCK (retained):
//  FIX-A — store() never saved lines
//  FIX-B — update() never synced lines
//  FIX-C — edit() missing 'mode' => 'edit' prop
//  FIX-D — edit() loaded lines.animal with too few columns
//  FIX-E — formatRecord() lines missing weight_kg + animal fields
//
//  AUDIT FIX BLOCK:
//  SALE-F1 — cogs_per_head / gross_margin now written in syncLines()
//  SALE-F2 — confirm() race condition + idempotency guard
//  SALE-P5-1 — journal_entry_id write-back hook added to confirm()
//  SALE-S1 — syncLines() now blocks hard-delete on confirmed records
//  RISK-F1 — Float arithmetic in syncLines() line total → bcmath
//
//  SESSION CURRENT — Revenue Journal Entry Auto-Posting
//
//  SALE-JE-1 — confirm() now accepts revenue_account_id from request
//    The user selects the revenue account in a confirmation modal.
//    Available accounts are all posting accounts under type='revenue'
//    for the company. Passed to index() as 'revenueAccounts'.
//
//  SALE-JE-2 — confirm() posts T5a + T5b journal entries
//    📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
//    📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//    📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//
//    T5a — Revenue recognition:
//      Dr  Accounts Receivable (1600)     total_amount
//      Cr  [selected revenue account]     total_amount
//
//    T5b — COGS recognition (only if lines have cogs_per_head data):
//      Dr  COGS — Animal Cost of Sales (5100)   Σ cogs_per_head
//      Cr  Livestock WIP (1310)                  Σ cogs_per_head
//
//    Both entries soft-fail with session warning if required accounts
//    or fiscal year are missing — the sale still confirms cleanly.
//
//    Account resolution rule:
//      Revenue account  → user-selected via revenue_account_id
//      AR account       → exact code '1600', allow_direct_posting=true
//      COGS account     → exact code '5100', allow_direct_posting=true
//      WIP account      → exact code '1310', allow_direct_posting=true
//
// ══════════════════════════════════════════════════════════════════

class SalesController extends Controller
{
    // 📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11) / ROIperLot (#20)
    public function __construct(
        private readonly LivestockIntelligenceService $intelligenceService
    ) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view sales'),
            403
        );

        $query = SalesRecord::with([
            'customer:id,name_en,name_ar',
            'farm:id,name_en,name_ar',
        ])->withCount('lines');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))     $query->where('farm_id', $request->farm_id);
        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('date_from'))   $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('date', '<=', $request->date_to);

        $records = $query->orderBy('date', 'desc')->paginate(30)->withQueryString();

        // ── SALE-JE-1: Pass revenue accounts to Vue for confirm modal ──
        // All active posting accounts of type 'revenue' for this company.
        // Super Admin sees accounts for their resolved company.
        $companyId = $this->resolveCompanyId($request);

        $revenueAccounts = $this->availableRevenueAccounts($companyId);

        return Inertia::render('Sales/Index', [
            'records'         => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'           => $this->availableFarms($authUser),
            'customers'       => $this->availableCustomers($authUser),
            'filters'         => $request->only(['farm_id', 'customer_id', 'status', 'date_from', 'date_to']),
            'revenueAccounts' => $revenueAccounts,  // ← NEW: for confirm modal dropdown
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create sales'),
            403
        );

        return Inertia::render('Sales/CreateEdit', [
            'mode'      => 'create',
            'farms'     => $this->availableFarms($authUser),
            'customers' => $this->availableCustomers($authUser),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create sales'),
            403
        );

        $validated = $this->validateRecord($request);
        $this->authorizeFarm($authUser, $validated['farm_id']);

        $request->validate([
            'lines'                  => 'required|array|min:1',
            'lines.*.animal_id'      => 'required|exists:animals,id|distinct',
            'lines.*.slaughter_id'   => 'nullable|exists:slaughter_records,id',
            'lines.*.pricing_mode'   => 'required|in:per_kg,per_head',
            'lines.*.price_per_kg'   => 'nullable|numeric|min:0',
            'lines.*.price_per_head' => 'nullable|numeric|min:0',
            'lines.*.qty_kg'         => 'nullable|numeric|min:0',
            'lines.*.notes'          => 'nullable|string|max:500',
        ]);

        // QA-017: the header totals are CALCULATED here from the lines — whatever the browser sent is ignored.
        $totals = $this->computeSaleTotals($request->input('lines', []), $validated['discount'] ?? 0);
        $validated['subtotal']     = $totals['subtotal'];
        $validated['total_amount'] = $totals['total'];

        // QA-018: the customer must belong to the same company as the sale
        $saleCompanyId = $this->resolveCompanyId($request);
        abort_unless(
            \App\Models\Customer::where('id', $validated['customer_id'])->where('company_id', $saleCompanyId)->exists(),
            422, 'Customer does not belong to this company.'
        );

        $companyId = $this->resolveCompanyId($request);

        // 📌 METHODOLOGY REFERENCE: SaleInvoiceAutoRef (#27)
        $invoiceRef = !empty($validated['invoice_ref'])
            ? $validated['invoice_ref']
            : $this->generateInvoiceRef($companyId, $validated['farm_id'], $validated['date']);

        DB::transaction(function () use ($validated, $companyId, $invoiceRef, $request) {
            // QA-024: an animal already sold, or held by another sale, cannot be put on a new sale
            $this->assertAnimalsStillAvailable($request->input('lines', []), null);

            $record = SalesRecord::create([
                'company_id'         => $companyId,
                'farm_id'            => $validated['farm_id'],
                'customer_id'        => $validated['customer_id'],
                'date'               => $validated['date'],
                'invoice_ref'        => $invoiceRef,
                'subtotal'           => $validated['subtotal'] ?? 0,
                'discount'           => $validated['discount'] ?? 0,
                'total_amount'       => $validated['total_amount'],
                'currency'           => $validated['currency'] ?? 'EGP',
                'payment_terms_days' => $validated['payment_terms_days'] ?? 30,
                'due_date'           => $validated['due_date'] ?? null,
                'status'             => 'draft',
                'notes'              => $validated['notes'] ?? null,
            ]);

            $this->syncLines($record, $request->input('lines', []), $companyId);

            // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
            $this->reserveAnimals(
                collect($request->input('lines', []))->pluck('animal_id')->filter()->unique()->values()->all(),
                $record->date
            );
        });

        return redirect()->route('sales.index')
            ->with('success', __('messages.sale_created'));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);

        $salesRecord->load([
            'customer:id,name_en,name_ar',
            'farm:id,name_en,name_ar',
            'lines.animal:id,tag,breed,sex,lot_id',
            'lines.slaughterRecord:id,liveweight_kg,deadweight_kg,dressing_pct',
        ]);

        return Inertia::render('Sales/Show', [
            'sale' => $this->formatRecord($salesRecord),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);

        abort_if(
            in_array($salesRecord->status, ['paid', 'cancelled']),
            422,
            __('messages.sale_cannot_edit')
        );

        $salesRecord->load('lines.animal:id,tag,breed,sex,lot_id');

        return Inertia::render('Sales/CreateEdit', [
            'mode'      => 'edit',
            'sale'      => $this->formatRecord($salesRecord),
            'farms'     => $this->availableFarms($authUser),
            'customers' => $this->availableCustomers($authUser),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);

        abort_if(
            in_array($salesRecord->status, ['paid', 'cancelled']),
            422,
            __('messages.sale_cannot_edit')
        );

        $validated = $this->validateRecord($request, $salesRecord->id);

        $request->validate([
            'lines'                  => 'required|array|min:1',
            'lines.*.animal_id'      => 'required|exists:animals,id|distinct',
            'lines.*.slaughter_id'   => 'nullable|exists:slaughter_records,id',
            'lines.*.pricing_mode'   => 'required|in:per_kg,per_head',
            'lines.*.price_per_kg'   => 'nullable|numeric|min:0',
            'lines.*.price_per_head' => 'nullable|numeric|min:0',
            'lines.*.qty_kg'         => 'nullable|numeric|min:0',
            'lines.*.notes'          => 'nullable|string|max:500',
        ]);

        // QA-017: the header totals are CALCULATED here from the lines — whatever the browser sent is ignored.
        $totals = $this->computeSaleTotals($request->input('lines', []), $validated['discount'] ?? 0);
        $validated['subtotal']     = $totals['subtotal'];
        $validated['total_amount'] = $totals['total'];

        // QA-018: the (possibly changed) farm and customer must belong to this sale's company
        $this->authorizeFarm($authUser, (int) $validated['farm_id']);
        abort_unless(
            \App\Models\Customer::where('id', $validated['customer_id'])->where('company_id', $salesRecord->company_id)->exists(),
            422, 'Customer does not belong to this company.'
        );

        DB::transaction(function () use ($salesRecord, $validated, $request) {
            // QA-024: same protection when a draft sale is edited (its own animals are fine)
            $this->assertAnimalsStillAvailable($request->input('lines', []), $salesRecord);

            // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
            $oldAnimalIds = $salesRecord->lines()->pluck('animal_id')->filter()->unique()->values()->all();
            $newAnimalIds = collect($request->input('lines', []))->pluck('animal_id')->filter()->unique()->values()->all();

            $toRelease = array_diff($oldAnimalIds, $newAnimalIds);
            $toReserve = array_diff($newAnimalIds, $oldAnimalIds);

            if (!empty($toRelease)) $this->releaseAnimals($toRelease);
            if (!empty($toReserve)) $this->reserveAnimals($toReserve, $validated['date'] ?? $salesRecord->date->toDateString());

            $salesRecord->update($validated);
           $this->syncLines($salesRecord, $request->input('lines', []), $salesRecord->company_id);
        });

        return redirect()->route('sales.show', $salesRecord->id)
            ->with('success', __('messages.sale_updated'));
    }

    // ── Confirm sale ──────────────────────────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
    //
    // Now accepts revenue_account_id from the confirmation modal.
    // Posts T5a (revenue recognition) and T5b (COGS recognition).
    //
    public function confirm(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);

        // ── SALE-F2: Idempotency guard ────────────────────────────
        if ($salesRecord->ar_invoice_id) {
            return back()->with('info', __('messages.sale_already_confirmed'));
        }

        abort_if($salesRecord->status !== 'draft', 422, __('messages.sale_already_confirmed'));
        abort_if($salesRecord->lines()->count() === 0, 422, __('messages.sale_no_lines'));

        // ── SALE-JE-1: Validate revenue_account_id ────────────────
        // Required — user must select a revenue account in the modal.
        // Must belong to same company, be active, and allow direct posting.
        $companyId = $authUser->is_super_admin
            ? $salesRecord->company_id
            : $authUser->company_id;

        $request->validate([
            'revenue_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                          ->where('type', 'revenue')
                          ->where('allow_direct_posting', true)
                          ->where('is_active', true);
                }),
            ],
        ]);

        $revenueAccountId = (int) $request->input('revenue_account_id');

        // A sale must reach the books: refuse to confirm when no OPEN fiscal year
        // covers its date (otherwise the invoice exists but revenue is never posted).
        $saleDay = $salesRecord->date->toDateString();
        $hasOpenYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $saleDay)
            ->whereDate('end_date', '>=', $saleDay)
            ->exists();
        if (!$hasOpenYear) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date' => __('messages.fiscal_year_not_open'),
            ]);
        }

        $salesRecord->load('lines.animal:id,tag');

        DB::transaction(function () use ($salesRecord, $companyId, $revenueAccountId) {
            // ── SALE-F2: lockForUpdate re-fetch ───────────────────
            $fresh = SalesRecord::lockForUpdate()->findOrFail($salesRecord->id);
            if ($fresh->ar_invoice_id) {
                return; // concurrent request — exit silently
            }

            // ── FIX 2: Auto-create AR Invoice ─────────────────────
            // 📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
            $arInvoice = ArInvoice::create([
                'company_id'    => $fresh->company_id,
                'customer_id'   => $fresh->customer_id,
                'date'          => $fresh->date->toDateString(),
                'due_date'      => $fresh->due_date?->toDateString(),
                'reference'     => $fresh->invoice_ref,
                'description'   => 'Sale #' . $fresh->id . ' — ' . $salesRecord->lines->count() . ' animals',
                'total'         => $fresh->total_amount,
                'paid'          => 0.00,
                'balance'       => $fresh->total_amount,
                'currency'      => $fresh->currency ?? 'EGP',
                'exchange_rate' => 1.0,
                'status'        => 'open',
                'notes'         => $fresh->notes,
            ]);

            // ── Animals reserved → sold ───────────────────────────
            // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
            $animalIds = $salesRecord->lines->pluck('animal_id')->filter()->unique()->values()->all();
            if (!empty($animalIds)) {
                Animal::whereIn('id', $animalIds)
                    ->where('status', 'reserved')
                    ->update([
                        'status'      => 'sold',
                        'status_date' => $fresh->date->toDateString(),
                    ]);

                // The animals leave their lot on the sale date (closes their animal-days)
                app(\App\Services\AnimalLotHistoryService::class)
                    ->close($animalIds, $fresh->date->toDateString(), 'sold');
            }

            // Link AR invoice and advance status
            $fresh->update([
                'status'        => 'confirmed',
                'ar_invoice_id' => $arInvoice->id,
            ]);

            // ── SALE-JE-2: Post T5a + T5b journal entries ─────────
            // 📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
            $this->postSaleJournalEntries($fresh, $arInvoice, $companyId, $revenueAccountId);
        });

        return back()->with('success', __('messages.sale_confirmed'));
    }

    // ── Cancel / Reverse sale ─────────────────────────────────────
    //
    // Handles both draft and confirmed sales in one method.
    //
    // DRAFT cancellation:
    //   - Release reserved animals back to 'active'
    //   - Cancel AR invoice if it somehow exists (safety net)
    //   - Set sale status = 'cancelled'
    //
    // CONFIRMED cancellation (reversal):
    //   📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28) — reversal path
    //   📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    //
    //   Guard: AR invoice must have paid = 0.
    //   If any collection exists the cancellation is blocked — the
    //   accountant must issue a credit note manually.
    //
    //   Steps (inside DB::transaction):
    //     1. Re-fetch AR invoice with lockForUpdate — verify paid = 0
    //     2. Reverse T5a: Dr Revenue account / Cr AR (1600)
    //     3. Reverse T5b: Dr WIP (1310) / Cr COGS (5100) — if T5b existed
    //     4. Cancel AR invoice
    //     5. Animals: sold → active
    //     6. Sale: status = 'cancelled'
    //
    public function cancel(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);

        // Block already-cancelled and paid sales always
        abort_if(
            in_array($salesRecord->status, ['paid', 'partially_paid', 'cancelled']),
            422,
            __('messages.sale_cannot_cancel')
        );

        // ── Confirmed sale: check no collections exist ────────────
        if ($salesRecord->status === 'confirmed') {
            if ($salesRecord->ar_invoice_id) {
                $arInvoice = ArInvoice::find($salesRecord->ar_invoice_id);
                if ($arInvoice && bccomp((string) $arInvoice->paid, '0', 4) > 0) {
                    abort(422, __('messages.sale_cannot_cancel_has_payments'));
                }
            }
        }

        $companyId = $authUser->is_super_admin
            ? $salesRecord->company_id
            : $authUser->company_id;

        DB::transaction(function () use ($salesRecord, $companyId) {

            // ── DRAFT path ────────────────────────────────────────
            if ($salesRecord->status === 'draft') {
                // Release reserved animals
                // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
                $animalIds = $salesRecord->lines()->pluck('animal_id')->filter()->unique()->values()->all();
                if (!empty($animalIds)) {
                    $this->releaseAnimals($animalIds);
                }

                // Cancel AR invoice if it exists (safety net for edge cases)
                if ($salesRecord->ar_invoice_id) {
                    ArInvoice::where('id', $salesRecord->ar_invoice_id)
                        ->whereIn('status', ['open', 'overdue'])
                        ->where('paid', 0)
                        ->update(['status' => 'cancelled']);
                }

                $salesRecord->update(['status' => 'cancelled']);
                return;
            }

            // ── CONFIRMED path (reversal) ─────────────────────────

            // Re-fetch AR invoice with row lock — verify paid = 0 inside txn
            $arInvoice = $salesRecord->ar_invoice_id
                ? ArInvoice::lockForUpdate()->find($salesRecord->ar_invoice_id)
                : null;

            if ($arInvoice && bccomp((string) $arInvoice->paid, '0', 4) > 0) {
                // Concurrent payment arrived between the check above and the lock
                abort(422, __('messages.sale_cannot_cancel_has_payments'));
            }

            // ── Reverse journal entries ───────────────────────────
            $this->reverseSaleJournalEntries($salesRecord, $companyId);

            // ── Cancel AR invoice ─────────────────────────────────
            if ($arInvoice) {
                $arInvoice->update(['status' => 'cancelled']);
            }

            // ── Animals: sold → active ────────────────────────────
            // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
            $animalIds = $salesRecord->lines()->pluck('animal_id')->filter()->unique()->values()->all();
            if (!empty($animalIds)) {
                Animal::whereIn('id', $animalIds)
                    ->where('status', 'sold')
                    ->update(['status' => 'active', 'status_date' => null]);

                // Back in their lot as if never sold
                app(\App\Services\AnimalLotHistoryService::class)->reopen($animalIds);
            }

            $salesRecord->update(['status' => 'cancelled']);
        });

        return back()->with('success', __('messages.sale_cancelled'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, SalesRecord $salesRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete sales'),
            403
        );

        $this->authorizeFarm($authUser, $salesRecord->farm_id);
        abort_if($salesRecord->status !== 'draft', 422, __('messages.sale_cannot_delete'));

        DB::transaction(function () use ($salesRecord) {
            // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
            $animalIds = $salesRecord->lines()->pluck('animal_id')->filter()->unique()->values()->all();
            if (!empty($animalIds)) {
                $this->releaseAnimals($animalIds);
            }

            $salesRecord->lines()->delete();
            $salesRecord->delete();
        });

        return redirect()->route('sales.index')
            ->with('success', __('messages.sale_deleted'));
    }

    // ── Animals JSON endpoint ─────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: ROIperLot / BatchPriceDistribution (#20)
    // 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
    public function getAnimals(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view sales'),
            403
        );

        $farmId        = (int) $request->farm_id;
        $saleType      = $request->sale_type ?? 'live';
        $excludeSaleId = $request->exclude_sale_id ? (int) $request->exclude_sale_id : null;

        abort_unless($farmId > 0, 422);
        abort_unless(in_array($saleType, ['live', 'carcass']), 422);

        $this->authorizeFarm($authUser, $farmId);

        if ($saleType === 'live') {
            $query = Animal::where('farm_id', $farmId)
                ->where(function ($q) use ($excludeSaleId) {
                    $q->where('status', 'active');
                    if ($excludeSaleId) {
                        $q->orWhere(function ($inner) use ($excludeSaleId) {
                            $inner->where('status', 'reserved')
                                ->whereHas('salesLines', function ($sl) use ($excludeSaleId) {
                                    $sl->whereHas('sale', function ($s) use ($excludeSaleId) {
                                        $s->where('id', $excludeSaleId)->where('status', 'draft');
                                    });
                                });
                        });
                    }
                })
                ->orderBy('tag');

            if (!$authUser->is_super_admin) {
                $query->where('company_id', $authUser->company_id);
            }

            $animals   = $query->get(['id', 'tag', 'breed', 'sex', 'lot_id', 'status']);
            $animalIds = $animals->pluck('id');

            $latestWeights = DB::table('weight_records as wr')
                ->joinSub(
                    DB::table('weight_records')
                        ->select('animal_id', DB::raw('MAX(date) as max_date'))
                        ->whereIn('animal_id', $animalIds)
                        ->groupBy('animal_id'),
                    'latest',
                    fn($join) => $join
                        ->on('wr.animal_id', '=', 'latest.animal_id')
                        ->on('wr.date', '=', 'latest.max_date')
                )
                ->select('wr.animal_id', 'wr.weight_kg', 'wr.date')
                ->get()
                ->keyBy('animal_id');

            return response()->json(
                $animals->map(function ($a) use ($latestWeights) {
                    $w = $latestWeights->get($a->id);
                    return [
                        'id'          => $a->id,
                        'tag'         => $a->tag,
                        'breed'       => $a->breed,
                        'sex'         => $a->sex,
                        'lot_id'      => $a->lot_id,
                        'status'      => $a->status,
                        'weight_kg'   => $w ? (float) $w->weight_kg : null,
                        'weight_date' => $w ? $w->date : null,
                        'has_weight'  => $w !== null,
                        'sale_type'   => 'live',
                    ];
                })
            );
        }

        // ── Carcass ───────────────────────────────────────────────
        $query = Animal::where('farm_id', $farmId)
            ->where('status', 'slaughtered')
            ->orderBy('tag');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        $animals   = $query->get(['id', 'tag', 'breed', 'sex', 'lot_id']);
        $animalIds = $animals->pluck('id');

        $slaughterRecords = DB::table('slaughter_records as sr')
            ->joinSub(
                DB::table('slaughter_records')
                    ->select('animal_id', DB::raw('MAX(date) as max_date'))
                    ->whereIn('animal_id', $animalIds)
                    ->groupBy('animal_id'),
                'latest',
                fn($join) => $join
                    ->on('sr.animal_id', '=', 'latest.animal_id')
                    ->on('sr.date', '=', 'latest.max_date')
            )
            ->select('sr.id as slaughter_id', 'sr.animal_id', 'sr.deadweight_kg',
                     'sr.liveweight_kg', 'sr.dressing_pct', 'sr.date', 'sr.grade')
            ->whereNotNull('sr.deadweight_kg')
            ->get()
            ->keyBy('animal_id');

        return response()->json(
            $animals
                ->filter(fn($a) => $slaughterRecords->has($a->id))
                ->map(function ($a) use ($slaughterRecords) {
                    $sr = $slaughterRecords->get($a->id);
                    return [
                        'id'            => $a->id,
                        'tag'           => $a->tag,
                        'breed'         => $a->breed,
                        'sex'           => $a->sex,
                        'lot_id'        => $a->lot_id,
                        'weight_kg'     => (float) $sr->deadweight_kg,
                        'weight_date'   => $sr->date,
                        'has_weight'    => true,
                        'slaughter_id'  => $sr->slaughter_id,
                        'liveweight_kg' => $sr->liveweight_kg ? (float) $sr->liveweight_kg : null,
                        'dressing_pct'  => $sr->dressing_pct  ? (float) $sr->dressing_pct  : null,
                        'grade'         => $sr->grade,
                        'sale_type'     => 'carcass',
                    ];
                })
                ->values()
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE — Journal Entry Posting
    // ══════════════════════════════════════════════════════════════

    /**
     * Post T5a and T5b journal entries on sale confirmation.
     *
     * 📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
     * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
     *
     * T5a — Revenue recognition:
     *   Dr  Accounts Receivable (1600)   total_amount
     *   Cr  [selected revenue account]   total_amount
     *
     * T5b — COGS recognition (only if Σ cogs_per_head > 0):
     *   Dr  COGS — Animal Cost of Sales (5100)   Σ cogs_per_head
     *   Cr  Livestock WIP (1310)                  Σ cogs_per_head
     *
     * Runs inside confirm()'s DB::transaction — any exception rolls
     * back the entire confirmation including AR invoice creation.
     *
     * Soft-fails (sale confirms without JE) when:
     *   - Required account not found in Chart of Accounts
     *   - No open fiscal year covers the sale date
     * In both cases a session warning is flashed.
     *
     * journal_entry_id is written back to sales_records after T5a posts.
     */
    private function postSaleJournalEntries(
        SalesRecord $sale,
        ArInvoice   $arInvoice,
        int         $companyId,
        int         $revenueAccountId
    ): void {

        $saleDate = $sale->date->toDateString();

        // ── Resolve open fiscal year ──────────────────────────────
        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $saleDate)
            ->whereDate('end_date',   '>=', $saleDate)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning',
                'Sale confirmed. No open fiscal year covers ' . $saleDate .
                ' — please post the revenue journal entry manually.'
            );
            return;
        }

        // ── Resolve accounts ──────────────────────────────────────

        // Dr — Accounts Receivable (1600)
        $arAccount = Account::where('company_id', $companyId)
            ->where('code', '1600')
            ->where('allow_direct_posting', true)
            ->first();

        if (!$arAccount) {
            session()->flash('warning',
                'Sale confirmed. Account 1600 (Accounts Receivable) not found — ' .
                'please post the revenue journal entry manually.'
            );
            return;
        }

        // Cr — User-selected revenue account
        $revenueAccount = Account::where('company_id', $companyId)
            ->where('id', $revenueAccountId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->first();

        if (!$revenueAccount) {
            session()->flash('warning',
                'Sale confirmed. Selected revenue account not found or not available — ' .
                'please post the revenue journal entry manually.'
            );
            return;
        }

        // 📌 BcmathMonetaryPrecision (#38): all amounts via bcmath
        $totalEgp = \App\Support\Money::round((string) $sale->total_amount, 2);

        // ── Build T5a reference ───────────────────────────────────
        $period    = (int) $sale->date->format('n');
        $reference = 'SALE/' . str_pad($sale->id, 5, '0', STR_PAD_LEFT);
        $memo      = 'Sale ' . $sale->invoice_ref . ' — ' .
                     ($sale->customer?->name_en ?? 'Customer');

        // ── Post T5a — Revenue recognition ───────────────────────
        $t5a = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $saleDate,
            'period'         => $period,
            'reference'      => $reference,
            'description'    => $memo,
            'status'         => 'posted',
            'entry_type'     => 'sale',
            'source_type'    => SalesRecord::class,
            'source_id'      => $sale->id,
            'total_debit'    => (float) $totalEgp,
            'total_credit'   => (float) $totalEgp,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr Accounts Receivable
        JournalLine::create([
            'entry_id'   => $t5a->id,
            'account_id' => $arAccount->id,
            'debit'      => (float) $totalEgp,
            'credit'     => 0,
            'farm_id'    => $sale->farm_id,
            'memo'       => $memo,
        ]);

        // Cr Revenue account (user-selected)
        JournalLine::create([
            'entry_id'   => $t5a->id,
            'account_id' => $revenueAccount->id,
            'debit'      => 0,
            'credit'     => (float) $totalEgp,
            'farm_id'    => $sale->farm_id,
            'memo'       => $memo,
        ]);

        // Write JE id back to sales_records
        $sale->updateQuietly(['journal_entry_id' => $t5a->id]);

        // ── Post T5b — COGS recognition (if data available) ──────
        // Load lines with cogs_per_head to compute total COGS
        $sale->loadMissing('lines');

        $totalCogs = '0';
        foreach ($sale->lines as $line) {
            if ($line->cogs_per_head !== null) {
                $totalCogs = bcadd($totalCogs, (string) $line->cogs_per_head, 4);
            }
        }

        // Only post T5b if there is actual COGS data
        if (bccomp($totalCogs, '0', 4) <= 0) {
            return; // No COGS data — T5b skipped (not an error)
        }

        // Resolve COGS account (5100)
        $cogsAccount = Account::where('company_id', $companyId)
            ->where('code', '5100')
            ->where('allow_direct_posting', true)
            ->first();

        // Resolve WIP account (1310)
        $wipAccount = Account::where('company_id', $companyId)
            ->where('code', '1310')
            ->where('allow_direct_posting', true)
            ->first();

        if (!$cogsAccount || !$wipAccount) {
            session()->flash('warning',
                'Sale confirmed and T5a posted. Accounts 5100/1310 not found — ' .
                'COGS journal entry must be posted manually.'
            );
            return;
        }

        $cogsStored = (float) \App\Support\Money::round($totalCogs, 2);

        $t5b = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $saleDate,
            'period'         => $period,
            'reference'      => $reference . '-COGS',
            'description'    => 'COGS — ' . $memo,
            'status'         => 'posted',
            'entry_type'     => 'sale',
            'source_type'    => SalesRecord::class,
            'source_id'      => $sale->id,
            'total_debit'    => $cogsStored,
            'total_credit'   => $cogsStored,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr COGS — Animal Cost of Sales (5100)
        JournalLine::create([
            'entry_id'   => $t5b->id,
            'account_id' => $cogsAccount->id,
            'debit'      => $cogsStored,
            'credit'     => 0,
            'farm_id'    => $sale->farm_id,
            'memo'       => 'COGS — ' . $memo,
        ]);

        // Cr Livestock WIP (1310)
        JournalLine::create([
            'entry_id'   => $t5b->id,
            'account_id' => $wipAccount->id,
            'debit'      => 0,
            'credit'     => $cogsStored,
            'farm_id'    => $sale->farm_id,
            'memo'       => 'COGS — ' . $memo,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Reverse T5a and T5b journal entries when a confirmed sale is cancelled.
     *
     * 📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28) — reversal path
     * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
     *
     * Reversal rule (per foundational accounting rules):
     *   Mirror entry with debits ↔ credits swapped.
     *   reversal_of_id set on the new entry.
     *   Original entry status set to 'reversed'.
     *
     * T5a reversal:
     *   Dr  Revenue account (originally Cr)     total_amount
     *   Cr  Accounts Receivable (originally Dr) total_amount
     *
     * T5b reversal (only if T5b JE exists):
     *   Dr  Livestock WIP (originally Cr)  Σ cogs
     *   Cr  COGS (originally Dr)           Σ cogs
     *
     * Soft-fails with session warning if fiscal year is closed/locked.
     * The sale still cancels — accountant posts the reversal manually.
     */
    private function reverseSaleJournalEntries(SalesRecord $sale, int $companyId): void
    {
        if (!$sale->journal_entry_id) {
            // No JE was posted at confirmation (soft-fail path) — nothing to reverse
            return;
        }

        $today = now()->toDateString();

        // Resolve open fiscal year covering today (reversal date)
        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date',   '>=', $today)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning',
                'Sale cancelled. No open fiscal year covers today — ' .
                'please post the reversal journal entries manually.'
            );
            return;
        }

        // ── Reverse T5a (revenue recognition) ────────────────────
        $t5a = JournalEntry::with('lines')->find($sale->journal_entry_id);

        if ($t5a && $t5a->status === 'posted') {
            $revT5a = JournalEntry::create([
                'company_id'     => $companyId,
                'fiscal_year_id' => $fiscalYear->id,
                'date'           => $today,
                'period'         => (int) now()->format('n'),
                'reference'      => 'REV/' . $t5a->reference,
                'description'    => 'Reversal — ' . $t5a->description,
                'status'         => 'posted',
                'entry_type'     => 'sale',
                'source_type'    => SalesRecord::class,
                'source_id'      => $sale->id,
                'total_debit'    => $t5a->total_credit,
                'total_credit'   => $t5a->total_debit,
                'reversal_of_id' => $t5a->id,
                'posted_by'      => Auth::id(),
                'posted_at'      => now(),
            ]);

            // Mirror every line: debit ↔ credit swapped
            foreach ($t5a->lines as $line) {
                JournalLine::create([
                    'entry_id'   => $revT5a->id,
                    'account_id' => $line->account_id,
                    'debit'      => $line->credit,
                    'credit'     => $line->debit,
                    'farm_id'    => $line->farm_id,
                    'memo'       => 'Reversal — ' . $line->memo,
                ]);
            }

            // Mark original as reversed
            $t5a->update(['status' => 'reversed']);
        }

        // ── Reverse T5b (COGS recognition) ───────────────────────
        // T5b reference is always T5a reference + '-COGS'
        if ($t5a) {
            $t5b = JournalEntry::with('lines')
                ->where('company_id', $companyId)
                ->where('reference', $t5a->reference . '-COGS')
                ->where('source_type', SalesRecord::class)
                ->where('source_id', $sale->id)
                ->where('status', 'posted')
                ->first();

            if ($t5b) {
                $revT5b = JournalEntry::create([
                    'company_id'     => $companyId,
                    'fiscal_year_id' => $fiscalYear->id,
                    'date'           => $today,
                    'period'         => (int) now()->format('n'),
                    'reference'      => 'REV/' . $t5b->reference,
                    'description'    => 'Reversal — ' . $t5b->description,
                    'status'         => 'posted',
                    'entry_type'     => 'sale',
                    'source_type'    => SalesRecord::class,
                    'source_id'      => $sale->id,
                    'total_debit'    => $t5b->total_credit,
                    'total_credit'   => $t5b->total_debit,
                    'reversal_of_id' => $t5b->id,
                    'posted_by'      => Auth::id(),
                    'posted_at'      => now(),
                ]);

                foreach ($t5b->lines as $line) {
                    JournalLine::create([
                        'entry_id'   => $revT5b->id,
                        'account_id' => $line->account_id,
                        'debit'      => $line->credit,
                        'credit'     => $line->debit,
                        'farm_id'    => $line->farm_id,
                        'memo'       => 'Reversal — ' . $line->memo,
                    ]);
                }

                $t5b->update(['status' => 'reversed']);
            }
        }
    }

    /**
     * All active posting accounts of type 'revenue' for a company.
     * Used to populate the confirm modal dropdown.
     *
     * 📌 SALE-JE-1: Revenue account selection at confirmation.
     */
    private function availableRevenueAccounts(int $companyId): \Illuminate\Support\Collection
    {
        return Account::where('company_id', $companyId)
            ->where('type', 'revenue')
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar']);
    }

    /**
     * Delete all existing lines and re-insert from the request array.
     *
     * SALE-S1: hard-delete only safe on draft sales.
     * RISK-F1: bcmath for line totals.
     * SALE-F1: cogs_per_head and gross_margin populated.
     */
    /**
     * QA-017 — one line's total in cents, rounded half-up:
     *   per kg   → price per kg × kg      per head → price per head
     */
    /**
     * QA-024 — protect against selling the same animal twice (two people at the same time,
     * or a form left open). Conservative on purpose: only refuses an animal that is
     *   - already SOLD, or
     *   - RESERVED by ANOTHER sale.
     * Animals already on THIS sale are always fine. The rows are locked while checking, so two
     * simultaneous saves cannot both pass.
     */
    private function assertAnimalsStillAvailable(array $lines, ?SalesRecord $current): void
    {
        $ids = collect($lines)->pluck('animal_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $own = $current ? $current->lines()->pluck('animal_id')->map(fn ($v) => (int) $v)->all() : [];
        $toCheck = $ids->reject(fn ($id) => in_array($id, $own, true));
        if ($toCheck->isEmpty()) {
            return;
        }

        $animals = Animal::whereIn('id', $toCheck->all())->lockForUpdate()->get(['id', 'tag', 'status']);
        foreach ($animals as $a) {
            if (in_array($a->status, ['sold', 'reserved'], true)) {
                abort(422, 'Animal ' . $a->tag . ' is no longer available — it is already '
                    . ($a->status === 'sold' ? 'sold' : 'on another sale')
                    . '. Remove it from this sale and try again.');
            }
        }
    }

    private function saleLineTotal(array $line): string
    {
        $mode = $line['pricing_mode'] ?? 'per_head';
        $perKg = isset($line['price_per_kg'])   && $line['price_per_kg']   !== '' ? (string) $line['price_per_kg']   : null;
        $perHd = isset($line['price_per_head']) && $line['price_per_head'] !== '' ? (string) $line['price_per_head'] : null;
        $qty   = isset($line['qty_kg'])         && $line['qty_kg']         !== '' ? (string) $line['qty_kg']         : null;

        if ($mode === 'per_kg' && $perKg !== null && $qty !== null) {
            return \App\Support\Money::round(bcmul($perKg, $qty, 6), 2);
        }
        return \App\Support\Money::round($perHd ?? '0', 2);
    }

    /**
     * QA-017 — header totals from the lines:  subtotal = Σ line totals,  total = subtotal − discount (never below 0).
     * @return array{subtotal: string, total: string}
     */
    private function computeSaleTotals(array $lines, $discount): array
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $this->saleLineTotal($line), 2);
        }
        $total = bcsub($subtotal, \App\Support\Money::round((string) ($discount ?: 0), 2), 2);
        if (bccomp($total, '0', 2) < 0) {
            $total = '0.00';
        }
        return ['subtotal' => $subtotal, 'total' => $total];
    }

    private function syncLines(SalesRecord $record, array $lines, int $companyId): void
    {
        abort_unless(
            $record->status === 'draft',
            422,
            __('messages.sale_lines_immutable_after_confirmation')
        );

        $record->lines()->delete();

        foreach ($lines as $line) {
            $pricingMode = $line['pricing_mode'] ?? 'per_head';
            $pricePerKg  = isset($line['price_per_kg'])   && $line['price_per_kg']   !== '' ? (float) $line['price_per_kg']   : null;
            $pricePerHd  = isset($line['price_per_head']) && $line['price_per_head'] !== '' ? (float) $line['price_per_head'] : null;
            $qtyKg       = isset($line['qty_kg'])         && $line['qty_kg']         !== '' ? (float) $line['qty_kg']         : null;

            // 📌 RISK-F1: bcmath line total — rounded to cents (QA-017), same rule as the header total
            $lineTotal = (float) $this->saleLineTotal($line);

            // 📌 SALE-F1: cogs_per_head and gross_margin
            $cogsPerHead = null;
            $grossMargin = null;
            $animalId    = !empty($line['animal_id']) ? (int) $line['animal_id'] : null;

           if ($animalId) {
                try {
                    $costing = $this->intelligenceService->costingForAnimal($animalId, $companyId);
                    $totalCogs = $costing['costs']['total_cogs'] ?? null;
                    if ($totalCogs !== null && (float) $totalCogs > 0) {
                        $cogsPerHead = (float) bcadd((string)$totalCogs, '0', 4);
                        $grossMargin = (float) bcsub((string)$lineTotal, (string)$cogsPerHead, 4);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $record->lines()->create([
                'animal_id'      => $animalId,
                'slaughter_id'   => isset($line['slaughter_id']) && $line['slaughter_id'] !== '' ? (int) $line['slaughter_id'] : null,
                'price_per_kg'   => $pricePerKg,
                'price_per_head' => $pricePerHd,
                'qty_kg'         => $qtyKg,
                'total'          => $lineTotal,
                'cogs_per_head'  => $cogsPerHead,
                'gross_margin'   => $grossMargin,
                'notes'          => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * Set status='reserved' on the given animal IDs.
     * 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
     */
    private function reserveAnimals(array $animalIds, string $date): void
    {
        if (empty($animalIds)) return;
        Animal::whereIn('id', $animalIds)
            ->where('status', 'active')
            ->update(['status' => 'reserved', 'status_date' => $date]);
    }

    /**
     * Release animals back to 'active'.
     * 📌 METHODOLOGY REFERENCE: AnimalSoftLock (#27)
     */
    private function releaseAnimals(array $animalIds): void
    {
        if (empty($animalIds)) return;
        Animal::whereIn('id', $animalIds)
            ->where('status', 'reserved')
            ->update(['status' => 'active', 'status_date' => null]);
    }

    /**
     * Auto invoice reference number generator.
     * 📌 METHODOLOGY REFERENCE: SaleInvoiceAutoRef (#27)
     * Format: INV/[CO3]/[FM3]/YYYY/MM/NNN
     */
    private function generateInvoiceRef(int $companyId, int $farmId, string $date): string
    {
        $company = Company::find($companyId);
        $farm    = Farm::find($farmId);

        $co3 = strtoupper(substr(preg_replace('/\s+/', '', $company?->name_en ?? 'CO'), 0, 3));
        $fm3 = strtoupper(substr(preg_replace('/\s+/', '', $farm?->name_en    ?? 'FM'), 0, 3));

        $year  = date('Y', strtotime($date));
        $month = date('m', strtotime($date));

        $prefix = "INV/{$co3}/{$fm3}/{$year}/{$month}/";

        $count = SalesRecord::where('company_id', $companyId)
            ->where('farm_id', $farmId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        return $prefix . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    }

    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'farm_id'            => 'required|exists:farms,id',
            'customer_id'        => 'required|exists:customers,id',
            'date'               => 'required|date',
            'invoice_ref'        => 'nullable|string|max:255',
            'subtotal'           => 'nullable|numeric|min:0',
            'discount'           => 'nullable|numeric|min:0',
            'total_amount'       => 'required|numeric|min:0',
            'currency'           => 'nullable|string|size:3',
            'payment_terms_days' => 'nullable|integer|min:0',
            'due_date'           => 'nullable|date',
            'notes'              => 'nullable|string',
        ]);
    }

    private function formatRecord(SalesRecord $record): array
    {
        return [
            'id'                 => $record->id,
            'company_id'         => $record->company_id,
            'farm_id'            => $record->farm_id,
            'farm'               => $record->farm ? [
                'id'      => $record->farm->id,
                'name_en' => $record->farm->name_en,
                'name_ar' => $record->farm->name_ar,
            ] : null,
            'customer_id'        => $record->customer_id,
            'customer'           => $record->customer ? [
                'id'      => $record->customer->id,
                'name_en' => $record->customer->name_en,
                'name_ar' => $record->customer->name_ar,
            ] : null,
            'date'               => $record->date->toDateString(),
            'invoice_ref'        => $record->invoice_ref,
            'subtotal'           => $record->subtotal,
            'discount'           => $record->discount,
            'total_amount'       => $record->total_amount,
            'currency'           => $record->currency,
            'payment_terms_days' => $record->payment_terms_days,
            'due_date'           => $record->due_date?->toDateString(),
            'status'             => $record->status,
            'ar_invoice_id'      => $record->ar_invoice_id,
            'notes'              => $record->notes,
            'lines_count'        => $record->lines_count ?? 0,
            'lines'              => $record->relationLoaded('lines')
                ? $record->lines->map(fn($l) => [
                    'id'             => $l->id,
                    'animal_id'      => $l->animal_id,
                    'animal_tag'     => $l->animal?->tag,
                    'animal_breed'   => $l->animal?->breed,
                    'animal_sex'     => $l->animal?->sex,
                    'animal_lot_id'  => $l->animal?->lot_id,
                    'slaughter_id'   => $l->slaughter_id,
                    'price_per_kg'   => $l->price_per_kg,
                    'price_per_head' => $l->price_per_head,
                    'qty_kg'         => $l->qty_kg,
                    'weight_kg'      => $l->qty_kg,
                    'total'          => $l->total,
                    'cogs_per_head'  => $l->cogs_per_head,
                    'gross_margin'   => $l->gross_margin,
                ])
                : [],
            'created_at'         => $record->created_at?->format('d M Y'),
        ];
    }

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function availableCustomers($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Customer::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Customer::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'name_en', 'name_ar']);
    }

    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless((int)$farm->company_id === (int)$authUser->company_id, 403);
    }
}