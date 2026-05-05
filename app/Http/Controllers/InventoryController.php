<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\InventoryLocation;
use App\Models\Farm;
use App\Models\FeedItem;
use App\Models\Supplier;
use App\Services\InventoryLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Controller
//
//  Manages master inventory items and their transactions.
//  Location fan-out logic lives in InventoryLocationService.
//
//  Master item = the item definition (name, category, unit)
//  Location-stock row = qty at a specific location (auto-created)
//
//  SESSION UPDATE:
//  recordTransaction() now accepts supplier_id and ref_no on
//  type='receipt' to support the GRNI / AP Accruals workflow.
//  📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
// ══════════════════════════════════════════════════════════════════

class InventoryController extends Controller
{
    public function __construct(private InventoryLocationService $service) {}

    // ── Index — master items with location stock breakdown ────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view inventory'),
            403
        );

        $filters = $request->only(['category', 'farm_id', 'location_id', 'low_stock', 'search']);

        if ($authUser->is_super_admin) {
            $filterCompanyId = $request->integer('company_id') ?: null;
            $items = $this->service->getStockSummary(null, $filters, true, $filterCompanyId);
        } else {
            $filterCompanyId = null;
            $items = $this->service->getStockSummary($authUser->company_id, $filters);
        }

        return Inertia::render('Inventory/Index', [
            'items'     => $items,
            'farms'     => $this->availableFarms($authUser),
            'locations' => $this->availableLocations($authUser),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id','name_en','name_ar'])
                : [],
            'filters'   => array_merge($filters, ['company_id' => $filterCompanyId ?? '']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create()
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create inventory'),
            403
        );

        return Inertia::render('Inventory/CreateEdit', [
            'mode'      => 'create',
            'item'      => null,
            'feedItems' => $this->availableFeedItems($authUser),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id','name_en','name_ar'])
                : [],
        ]);
    }

    // ── Store master item ─────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create inventory'),
            403
        );

        $this->sanitizeNullableFields($request);

        if ($authUser->is_super_admin) {
            $companyId = (int) $request->input('company_id');
            abort_unless($companyId > 0, 422, 'Super admin must select a company.');
            abort_unless(Company::where('id', $companyId)->exists(), 422, 'Invalid company.');
        } else {
            $companyId = $authUser->company_id;
        }

        $validated = $this->validateMasterItem($request, $companyId);

        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $service = $this->service;

        $master = DB::transaction(function () use ($validated, $companyId, $service) {
            $master = InventoryItem::create([
                ...$validated,
                'company_id'     => $companyId,
                'farm_id'        => null,
                'location_id'    => null,
                'master_item_id' => null,
                'is_master'      => true,
                'qty_on_hand'    => 0,
            ]);

            $service->fanOutNewItem($master);

            return $master;
        });

        return redirect()->route('inventory.index')
            ->with('success', __('messages.inventory_item_created'));
    }

    // ── Edit master item ──────────────────────────────────────────
    public function edit(InventoryItem $inventoryItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit inventory'),
            403
        );

        abort_unless($inventoryItem->is_master, 404);
        $this->authorizeCompany($authUser, $inventoryItem->company_id);

        return Inertia::render('Inventory/CreateEdit', [
            'mode'      => 'edit',
            'item'      => $this->formatMaster($inventoryItem),
            'feedItems' => $this->availableFeedItems($authUser),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id','name_en','name_ar'])
                : [],
        ]);
    }

    // ── Update master item ────────────────────────────────────────
    public function update(Request $request, InventoryItem $inventoryItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit inventory'),
            403
        );

        abort_unless($inventoryItem->is_master, 404);
        $this->authorizeCompany($authUser, $inventoryItem->company_id);
        $this->sanitizeNullableFields($request);

        $validated = $this->validateMasterItem(
            $request,
            $inventoryItem->company_id,
            $inventoryItem->id
        );

        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $service = $this->service;

        DB::transaction(function () use ($inventoryItem, $validated, $service) {
            $inventoryItem->update($validated);
            $service->syncMasterEdit($inventoryItem);
        });

        return redirect()->route('inventory.index')
            ->with('success', __('messages.inventory_item_updated'));
    }

    // ── Toggle active ─────────────────────────────────────────────
    public function toggleActive(InventoryItem $inventoryItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit inventory'),
            403
        );

        $inventoryItem->update(['is_active' => !$inventoryItem->is_active]);
        $this->service->syncMasterEdit($inventoryItem);

        return back()->with('success', __('messages.inventory_status_updated'));
    }

    // ── Destroy master item ───────────────────────────────────────
    public function destroy(InventoryItem $inventoryItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete inventory'),
            403
        );

        $hasStock = InventoryItem::where('master_item_id', $inventoryItem->id)
            ->where('qty_on_hand', '>', 0)
            ->exists();

        abort_if($hasStock, 422, __('messages.inventory_item_has_stock'));

        DB::transaction(function () use ($inventoryItem) {
            InventoryItem::where('master_item_id', $inventoryItem->id)->delete();
            $inventoryItem->delete();
        });

        return back()->with('success', __('messages.inventory_item_deleted'));
    }

    // ── Transactions ledger for one master item ───────────────────
    public function transactions(Request $request, InventoryItem $inventoryItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view inventory'),
            403
        );

        abort_unless($inventoryItem->is_master, 404);
        $this->authorizeCompany($authUser, $inventoryItem->company_id);

        $stockIds = InventoryItem::where('master_item_id', $inventoryItem->id)
            ->pluck('id');

        // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
        // Eager-load supplier so the transaction list can show supplier name
        // and highlight unmatched receipt accruals (ap_invoice_id IS NULL).
        $query = InventoryTransaction::with([
            'location:id,name_en,name_ar,farm_id',
            'location.farm:id,name_en,name_ar',
            'counterpartLocation:id,name_en,name_ar,farm_id',
            'recorder:id,name',
            'supplier:id,name_en,name_ar',     // ← NEW
        ])->whereIn('item_id', $stockIds);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }
        // New filter: show only unmatched accruals (receipts with no invoice)
        if ($request->boolean('unmatched_only')) {
            $query->where('type', 'receipt')
                  ->whereNotNull('supplier_id')
                  ->whereNull('ap_invoice_id');
        }

        $transactions = $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $locationStocks = InventoryItem::with('location.farm')
            ->where('master_item_id', $inventoryItem->id)
            ->get()
            ->map(fn($s) => [
                'stock_id'         => $s->id,
                'location_id'      => $s->location_id,
                'location_name_en' => $s->location?->name_en,
                'location_name_ar' => $s->location?->name_ar,
                'farm_id'          => $s->location?->farm_id,
                'farm_name_en'     => $s->location?->farm?->name_en,
                'farm_name_ar'     => $s->location?->farm?->name_ar,
                'is_central'       => $s->location?->farm_id === null,
                'qty_on_hand'      => (float) $s->qty_on_hand,
                'unit_cost'        => (float) $s->unit_cost,
            ]);

        // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual — accrual summary
        // Compute unmatched accrual total for this item scoped to company,
        // broken down per supplier so the UI can show a warning panel.
        $accrualSummary = $this->buildAccrualSummary($stockIds, $inventoryItem->company_id);

        // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
        // If navigated from a PO ([+ Receive Items] button), load the PO
        // with its open item lines so the Vue can show the PO banner and
        // quick-receive table. NULL when accessed normally.
        $po = null;
        if ($request->filled('po_id')) {
            $poRecord = \App\Models\ProcurementRecord::with([
                'supplier:id,name_en,name_ar',
                'itemLines.inventoryItem',
                'itemLines.location',
            ])->find((int) $request->po_id);

            if ($poRecord
                && $poRecord->po_type === 'inventory'
                && $poRecord->company_id === $inventoryItem->company_id
                && $poRecord->isOpenForReceipt()
            ) {
                $po = [
                    'id'           => $poRecord->id,
                    'po_reference' => $poRecord->po_reference,
                    'supplier_id'  => $poRecord->supplier_id,
                    'supplier'     => $poRecord->supplier ? [
                        'name_en' => $poRecord->supplier->name_en,
                        'name_ar' => $poRecord->supplier->name_ar,
                    ] : null,
                    'po_status'    => $poRecord->po_status,
                    'item_lines'   => $poRecord->itemLines->map(fn($l) => [
                        'id'                  => $l->id,
                        'inventory_item_id'   => $l->inventory_item_id,
                        'item_name_en'        => $l->inventoryItem?->name_en,
                        'item_name_ar'        => $l->inventoryItem?->name_ar,
                        'location_id'         => $l->location_id,
                        'location_name_en'    => $l->location?->name_en,
                        'location_name_ar'    => $l->location?->name_ar,
                        'qty_ordered'         => $l->qty_ordered,
                        'qty_received'        => $l->qty_received,
                        'remaining_qty'       => $l->remainingQty(),
                        'unit_cost_estimated' => $l->unit_cost_estimated,
                        'line_status'         => $l->line_status,
                    ])->values(),
                ];
            }
        }

        return Inertia::render('Inventory/Transactions', [
            'item'            => $this->formatMaster($inventoryItem),
            'po'              => $po,
            'locationStocks'  => $locationStocks,
            'transactions'    => $transactions->through(fn($t) => $this->formatTransaction($t)),
            'locations'       => $this->availableLocations($authUser),
            'suppliers'       => $this->availableSuppliers($authUser),
            'accrualSummary'  => $accrualSummary,
            'filters'         => $request->only([
                'location_id', 'type', 'date_from', 'date_to', 'unmatched_only',
            ]),
        ]);
    }

    // ── Record transaction (receipt, consumption, adjustment) ─────
    //
    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    //
    // receipt     → increments qty, recalculates WAC, optionally records
    //               supplier_id and ref_no for AP Accruals trail.
    // consumption → decrements qty (hard block if insufficient stock).
    // adjustment  → signed qty change (positive or negative).
    //
    // supplier_id and ref_no are accepted ONLY when type = 'receipt'.
    // For consumption and adjustment they are ignored — validation
    // enforces this so no bad data can enter the accruals trail.
    // ─────────────────────────────────────────────────────────────
    public function recordTransaction(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create inventory'),
            403
        );

        // 📌 Step 1 — Sanitize nullable fields before validation
        $this->sanitizeTransactionNullables($request);

        // 📌 Step 2 — Validate base fields (all transaction types)
        $validated = $request->validate([
            'stock_id'    => 'required|exists:inventory_items,id',
            'location_id' => 'required|exists:inventory_locations,id',
            'type'        => ['required', Rule::in(['receipt', 'consumption', 'adjustment'])],
            'date'        => 'required|date',
            'qty'         => 'required|numeric|min:0.001',
            'unit_cost'   => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string|max:500',

            // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
            // supplier_id: nullable on the receipt — if NULL no accrual is tracked.
            // Must belong to the same company (cross-field guard below).
            'supplier_id' => 'nullable|exists:suppliers,id',

            // ref_no: supplier delivery note / GRN / PO number for matching.
            'ref_no'      => 'nullable|string|max:100',
        ]);

        // 📌 Step 3 — supplier_id and ref_no only meaningful on receipt
        // Silently clear them for consumption / adjustment so the accruals
        // trail is never polluted with non-receipt rows.
        if ($validated['type'] !== 'receipt') {
            $validated['supplier_id'] = null;
            $validated['ref_no']      = null;
        }

        // 📌 Step 4 — Load and lock stock row, authorize company
        $stockRow = InventoryItem::lockForUpdate()->findOrFail($validated['stock_id']);
        $this->authorizeCompany($authUser, $stockRow->company_id);

        // 📌 Step 5 — Cross-field guard: supplier must belong to same company
        if (!empty($validated['supplier_id'])) {
            $supplier = Supplier::findOrFail($validated['supplier_id']);
            abort_unless(
                $supplier->company_id === $stockRow->company_id,
                422,
                __('validation.inventory.supplier_company_mismatch')
            );
        }

        DB::transaction(function () use ($validated, $stockRow, $authUser) {

            $qty       = (float) $validated['qty'];
            $unitCost  = (float) ($validated['unit_cost'] ?? $stockRow->unit_cost);
            $isInbound = $validated['type'] === 'receipt';
            $isOutbound= $validated['type'] === 'consumption';

            // 📌 Step 6 — Hard block: cannot consume more than on hand
            if ($isOutbound && $stockRow->qty_on_hand < $qty) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qty' => __('validation.inventory.insufficient_stock', [
                        'available' => $stockRow->qty_on_hand,
                    ]),
                ]);
            }

            // 📌 Step 7 — Update stock qty and WAC
            if ($isInbound) {
                // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (WAC sub-formula = WAC #6)
                // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
                // WAC_new = ((old_qty × old_wac) + (qty_in × unit_cost)) / (old_qty + qty_in)
                // All arithmetic in bcmath at scale=6 to prevent IEEE 754 drift on
                // repeated receipt accumulations across many stock movements.
                $oldQtyStr  = (string) $stockRow->qty_on_hand;
                $oldWacStr  = (string) $stockRow->unit_cost;
                $qtyInStr   = (string) $qty;
                $unitCostStr= (string) $unitCost;
                $newTotalQty = bcadd($oldQtyStr, $qtyInStr, 6);

                $newWac = bccomp($oldQtyStr, '0', 6) > 0
                    ? bcdiv(
                        bcadd(
                            bcmul($oldQtyStr, $oldWacStr,   6),
                            bcmul($qtyInStr,  $unitCostStr, 6),
                            6
                        ),
                        $newTotalQty,
                        6
                    )
                    : $unitCostStr;

                $stockRow->increment('qty_on_hand', $qty);
                $stockRow->update(['unit_cost' => (float) bcadd($newWac, '0', 4)]);
            } elseif ($isOutbound) {
                $stockRow->decrement('qty_on_hand', $qty);
            } else {
                // adjustment — signed qty (Vue sends positive or negative)
                $stockRow->increment('qty_on_hand', $qty);
            }

            $stockRow->refresh();

            // 📌 Step 8 — Persist transaction with supplier accrual columns
            InventoryTransaction::create([
                'company_id'  => $stockRow->company_id,
                'farm_id'     => $stockRow->farm_id,
                'location_id' => $validated['location_id'],
                'item_id'     => $stockRow->id,
                'type'        => $validated['type'],
                'date'        => $validated['date'],
                'qty'         => abs($qty),
                'unit_cost'   => $unitCost,
                // 📌 BcmathMonetaryPrecision (#38) — total is a monetary value
                'total'       => (float) bcadd(
                                    bcmul((string)abs($qty), (string)$unitCost, 4),
                                    '0', 2
                                 ),
                'qty_after'   => $stockRow->qty_on_hand,
                'wac_after'   => $stockRow->unit_cost,
                'supplier_id' => $validated['supplier_id'] ?? null,   // ← NEW
                'ref_no'      => $validated['ref_no']      ?? null,   // ← NEW
                'ap_invoice_id' => null,   // always NULL at receipt; set when invoice matched
                'recorded_by' => $authUser->id,
                'notes'       => $validated['notes'] ?? null,
            ]);

            // 📌 Phase 5 hook (not yet implemented):
            // if ($isInbound && !empty($validated['supplier_id'])) {
            //     event(new ReceiptAccrualEvent($transaction));
            //     // Accounting driver posts:
            //     //   Dr Inventory Asset account   = total
            //     //   Cr AP Accruals (system acct) = total
            // }
        });

        return back()->with('success', __('messages.transaction_recorded'));
    }

    // ── Record transfer (paired atomic) ──────────────────────────
    public function recordTransfer(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create inventory'),
            403
        );

        $validated = $request->validate([
            'master_item_id'     => 'required|exists:inventory_items,id',
            'source_location_id' => 'required|exists:inventory_locations,id',
            'dest_location_id'   => 'required|exists:inventory_locations,id|different:source_location_id',
            'qty'                => 'required|numeric|min:0.001',
            'date'               => 'required|date',
            'notes'              => 'nullable|string',
        ]);

        $this->service->recordTransfer(
            masterItemId:     $validated['master_item_id'],
            sourceLocationId: $validated['source_location_id'],
            destLocationId:   $validated['dest_location_id'],
            qty:              (float) $validated['qty'],
            date:             $validated['date'],
            recordedBy:       $authUser->id,
            notes:            $validated['notes'] ?? null,
        );

        return back()->with('success', __('messages.transfer_recorded'));
    }
    
    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    //
    // supplier_id and ref_no are metadata — they do NOT affect
    // qty_on_hand, unit_cost, or WAC. They can therefore be
    // corrected in-place WITHOUT a reversal, subject to two guards:
    //
    //   Guard 1 — only type='receipt' rows carry supplier metadata
    //   Guard 2 — once ap_invoice_id is set the accrual is cleared
    //             and the record is part of a posted journal entry;
    //             it becomes fully immutable at that point
    //
    // This is the ONLY field-level edit allowed on posted transactions.
    // Everything else (qty, date, location, cost) requires a reversal.
    // ─────────────────────────────────────────────────────────────
    public function patchSupplier(Request $request, InventoryTransaction $inventoryTransaction): \Illuminate\Http\RedirectResponse
    {
        $authUser = Auth::user();
 
        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit inventory'),
            403
        );
 
        // Guard 1 — only receipts have supplier accrual metadata
        abort_unless(
            $inventoryTransaction->type === 'receipt',
            422,
            __('validation.inventory.supplier_patch_receipt_only')
        );
 
        // Guard 2 — once matched to an invoice the record is immutable
        abort_if(
            $inventoryTransaction->ap_invoice_id !== null,
            422,
            __('validation.inventory.supplier_patch_already_matched')
        );
 
        // Guard 3 — company scope
        $this->authorizeCompany($authUser, $inventoryTransaction->company_id);
 
        // Sanitize: Inertia sends '' for cleared fields, Laravel nullable needs null
        $data = $request->all();
        foreach (['supplier_id', 'ref_no'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        $request->replace($data);
 
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'ref_no'      => 'nullable|string|max:100',
        ]);
 
        // Cross-field guard — supplier must belong to same company as the transaction
        if (!empty($validated['supplier_id'])) {
            $supplier = Supplier::findOrFail($validated['supplier_id']);
            abort_unless(
                $supplier->company_id === $inventoryTransaction->company_id,
                422,
                __('validation.inventory.supplier_company_mismatch')
            );
        }
 
        $inventoryTransaction->update([
            'supplier_id' => $validated['supplier_id'],
            'ref_no'      => $validated['ref_no'] ?? $inventoryTransaction->ref_no,
        ]);
 
        return back()->with('success', __('messages.supplier_updated'));
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
     *
     * Compute unmatched accrual balance per supplier for a given set
     * of stock item IDs and company. Returns an array of supplier rows
     * each showing total unmatched value and oldest receipt date —
     * used by the Transactions page warning panel.
     *
     * Query: type='receipt' AND supplier_id IS NOT NULL AND ap_invoice_id IS NULL
     */
    private function buildAccrualSummary(
        \Illuminate\Support\Collection $stockIds,
        int $companyId
    ): array {
        $rows = DB::table('inventory_transactions as t')
            ->join('suppliers as s', 's.id', '=', 't.supplier_id')
            ->whereIn('t.item_id', $stockIds)
            ->where('t.type', 'receipt')
            ->whereNotNull('t.supplier_id')
            ->whereNull('t.ap_invoice_id')
            ->where('t.company_id', $companyId)
            ->groupBy('t.supplier_id', 's.name_en', 's.name_ar')
            ->select([
                't.supplier_id',
                's.name_en as supplier_name_en',
                's.name_ar as supplier_name_ar',
                DB::raw('SUM(t.total) as total_accrual'),
                DB::raw('MIN(t.date) as oldest_receipt_date'),
                DB::raw('COUNT(*) as receipt_count'),
            ])
            ->get();

        return $rows->map(fn($r) => [
            'supplier_id'        => $r->supplier_id,
            'supplier_name_en'   => $r->supplier_name_en,
            'supplier_name_ar'   => $r->supplier_name_ar,
            'total_accrual'      => (float) $r->total_accrual,
            'oldest_receipt_date'=> $r->oldest_receipt_date,
            'receipt_count'      => (int) $r->receipt_count,
        ])->values()->all();
    }

    private function validateMasterItem(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        $nameEnUnique = Rule::unique('inventory_items', 'name_en')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->whereNotNull('name_en');

        $nameArUnique = Rule::unique('inventory_items', 'name_ar')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->whereNotNull('name_ar');

        $skuUnique = Rule::unique('inventory_items', 'sku')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->whereNotNull('sku');

        if ($ignoreId) {
            $nameEnUnique = $nameEnUnique->ignore($ignoreId);
            $nameArUnique = $nameArUnique->ignore($ignoreId);
            $skuUnique    = $skuUnique->ignore($ignoreId);
        }

        return $request->validate([
            'name_en'       => ['nullable', 'string', 'max:255', $nameEnUnique],
            'name_ar'       => ['nullable', 'string', 'max:255', $nameArUnique],
            'sku'           => ['nullable', 'string', 'max:255', $skuUnique],
            'category'      => ['required', Rule::in(['feed','medicine','vaccine','equipment','supply','other'])],
            'unit'          => 'required|string|max:50',
            'unit_cost'     => 'required|numeric|min:0',
            'reorder_point' => 'nullable|numeric|min:0',
            'feed_item_id'  => 'nullable|exists:feed_items,id',
            'is_active'     => 'boolean',
            'notes'         => 'nullable|string',
        ], [
            'name_en.unique' => __('validation.inventory.name_en_taken'),
            'name_ar.unique' => __('validation.inventory.name_ar_taken'),
            'sku.unique'     => __('validation.inventory.sku_taken'),
        ]);
    }

    private function formatMaster(InventoryItem $item): array
    {
        return [
            'id'            => $item->id,
            'name_en'       => $item->name_en,
            'name_ar'       => $item->name_ar,
            'sku'           => $item->sku,
            'category'      => $item->category,
            'unit'          => $item->unit,
            'unit_cost'     => $item->unit_cost,
            'reorder_point' => $item->reorder_point,
            'feed_item_id'  => $item->feed_item_id,
            'is_active'     => $item->is_active,
            'notes'         => $item->notes,
            'created_at'    => $item->created_at?->format('d M Y'),
        ];
    }

    private function formatTransaction(InventoryTransaction $t): array
    {
        return [
            'id'                      => $t->id,
            'type'                    => $t->type,
            'date'                    => $t->date->toDateString(),
            'qty'                     => $t->qty,
            'qty_signed'              => $t->qty_signed,
            'unit_cost'               => $t->unit_cost,
            'total'                   => $t->total,
            'qty_after'               => $t->qty_after,
            'wac_after'               => $t->wac_after,
            'location_id'             => $t->location_id,
            'location_name_en'        => $t->location?->name_en,
            'location_name_ar'        => $t->location?->name_ar,
            'counterpart_location_id' => $t->counterpart_location_id,
            'counterpart_name_en'     => $t->counterpartLocation?->name_en,
            'counterpart_name_ar'     => $t->counterpartLocation?->name_ar,
            'transfer_batch_ref'      => $t->transfer_batch_ref,

            // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
            // supplier columns — populated on receipts only
            'supplier_id'             => $t->supplier_id,
            'supplier_name_en'        => $t->supplier?->name_en,
            'supplier_name_ar'        => $t->supplier?->name_ar,
            'ref_no'                  => $t->ref_no,
            'ap_invoice_id'           => $t->ap_invoice_id,
            'is_accrued'              => $t->type === 'receipt'
                                         && $t->supplier_id !== null
                                         && $t->ap_invoice_id === null,

            'recorder'                => $t->recorder?->name,
            'notes'                   => $t->notes,
        ];
    }

    private function availableFarms($authUser)
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id','company_id','name_en','name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id','company_id','name_en','name_ar']);
    }

    private function availableLocations($authUser)
    {
        $q = InventoryLocation::with('farm:id,name_en,name_ar')
            ->where('is_active', true)
            ->orderByRaw('farm_id IS NULL DESC')
            ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $q->where('company_id', $authUser->company_id);
        }

        return $q->get(['id','farm_id','name_en','name_ar','type']);
    }

    private function availableFeedItems($authUser)
    {
        $q = FeedItem::where('is_active', true)->orderBy('name_en');
        if (!$authUser->is_super_admin) {
            $q->where('company_id', $authUser->company_id);
        }
        return $q->get(['id','name_en','name_ar']);
    }

    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    // Suppliers list for the receipt form — scoped to company.
    private function availableSuppliers($authUser)
    {
        $q = Supplier::where('is_active', true)->orderBy('name_en');
        if (!$authUser->is_super_admin) {
            $q->where('company_id', $authUser->company_id);
        }
        return $q->get(['id','name_en','name_ar']);
    }

    private function authorizeCompany($authUser, int $companyId): void
    {
        if ($authUser->is_super_admin) return;
        abort_unless($authUser->company_id === $companyId, 403);
    }

    

    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    // Sanitize the nullable transaction fields — supplier_id and ref_no
    // come from Vue as '' (empty string) when the user leaves them blank.
    // Laravel nullable rule requires actual null, not empty string.
    private function sanitizeTransactionNullables(Request $request): void
    {
        $nullables = ['unit_cost', 'notes', 'supplier_id', 'ref_no'];
        $data = $request->all();
        foreach ($nullables as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        $request->replace($data);
    }
}