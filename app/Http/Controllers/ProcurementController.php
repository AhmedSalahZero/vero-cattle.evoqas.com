<?php

namespace App\Http\Controllers;

use App\Models\AnimalReceiptBatch;
use App\Models\Company;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Lot;
use App\Models\ProcurementItemLine;
use App\Models\ProcurementLine;
use App\Models\ProcurementRecord;
use App\Models\Supplier;
use App\Services\InventoryReceiptService;
use App\Services\LivestockReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Procurement Controller
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//  📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//
//  SESSION 45 REWORK — procurement_records is a pure Purchase Order.
//  SESSION 55 ADDITION — Inventory Items PO flow added (po_type='inventory').
//
//  Cattle PO flow (po_type='cattle') — UNCHANGED:
//    store()                    → creates cattle PO in 'draft'
//    confirmPo()                → draft → open
//    receive()                  → renders Animals/CreateEdit.vue
//    confirmReceipt()           → delegates to LivestockReceiptService
//    forceClose()               → closes cattle PO with reason
//
//  Inventory PO flow (po_type='inventory') — NEW:
//    storeInventoryPo()         → creates inventory PO in 'draft'
//    confirmInventoryPo()       → draft → open
//    receiveInventory()         → renders Procurement/InventoryReceive.vue
//    confirmInventoryReceipt()  → delegates to InventoryReceiptService
//    forceCloseInventory()      → closes inventory PO with reason
//
//  create() routes via ?type= query param to the correct form.
//  formatRecord() includes po_type in all responses.
//
//  PRESERVED FROM SESSION 31:
//  ✅ PROC-F2 — lockForUpdate() in confirmPo()
//  ✅ RISK-F5 — syncLines() still called in store() + update()
//  ✅ store() + update() wrapped in DB::transaction
// ══════════════════════════════════════════════════════════════════

class ProcurementController extends Controller
{
    public function __construct(
        private LivestockReceiptService  $receiptService,
        private InventoryReceiptService  $inventoryReceiptService
    ) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view procurement'),
            403
        );

        $query = ProcurementRecord::with([
            'supplier:id,name_en,name_ar',
            'farm:id,name_en,name_ar',
            'lot:id,name',
        ])->withCount(['lines', 'receiptBatches'])
          // Inventory PO receipt progress — aggregate item line qty totals
          // Used by the index receipt progress bar for po_type='inventory'
          ->withSum('itemLines as ordered_qty_sum', 'qty_ordered')
          ->withSum('itemLines as received_qty_sum', 'qty_received');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))     $query->where('farm_id', $request->farm_id);
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->supplier_id);
        if ($request->filled('po_status'))   $query->where('po_status', $request->po_status);
        if ($request->filled('po_type'))     $query->where('po_type', $request->po_type);
        if ($request->filled('date_from'))   $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('date', '<=', $request->date_to);

        $records = $query->orderBy('date', 'desc')->paginate(30)->withQueryString();

        return Inertia::render('Procurement/Index', [
            'records'   => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'     => $this->availableFarms($authUser),
            'suppliers' => $this->availableSuppliers($authUser),
            'filters'   => $request->only(['farm_id', 'supplier_id', 'po_status', 'po_type', 'date_from', 'date_to']),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    // Routes to the correct form based on ?type= query parameter.
    // type=cattle (default) → existing cattle PO form (unchanged)
    // type=inventory        → new inventory items PO form
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create procurement'),
            403
        );

        $poType = $request->input('type', 'cattle');
        abort_unless(in_array($poType, ['cattle', 'inventory']), 422, 'Invalid PO type.');

        $baseProps = [
            'mode'      => 'create',
            'po_type'   => $poType,
            'farms'     => $this->availableFarms($authUser),
            'lots'      => $this->availableLots($authUser),
            'suppliers' => $this->availableSuppliers($authUser),
            'companies' => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ];

        // Inventory POs also need master items and locations for the line repeater
        if ($poType === 'inventory') {
            $baseProps['inventoryItems'] = $this->availableMasterItems($authUser);
            $baseProps['locations']   = $this->availableLocations($authUser);
        }

        return Inertia::render('Procurement/CreateEdit', $baseProps);
    }

    // ── Store (create PO) ─────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create procurement'),
            403
        );

       $this->sanitizeNullableFields($request);

        $poType = $request->input('po_type', 'cattle');
        abort_unless(in_array($poType, ['cattle', 'inventory']), 422, 'Invalid PO type.');

        if ($poType === 'inventory') {

            $validated = $this->validateInventoryPoRecord($request);
            $this->authorizeFarm($authUser, $validated['farm_id']);
            $companyId = $this->resolveCompanyId($authUser, $validated['farm_id'], $request);

            DB::transaction(function () use ($validated, $companyId, $request) {
                $record = ProcurementRecord::create([
                    'company_id'          => $companyId,
                    'po_type'             => 'inventory',
                    'farm_id'             => $validated['farm_id'],
                    'supplier_id'         => $validated['supplier_id'],
                    'lot_id'              => null,
                    'date'                => $validated['date'],
                    'po_reference'        => null,
                    'invoice_ref'         => $validated['invoice_ref'] ?? null,
                    'min_head'            => 0,
                    'max_head'            => 0,
                    'head_count'          => 0,
                    'received_head'       => 0,
                    'subtotal'            => $validated['subtotal'] ?? 0,
                    'freight_cost'        => $validated['freight_cost'] ?? 0,
                    'other_costs'         => $validated['other_costs'] ?? 0,
                    'total_amount'        => $validated['total_amount'] ?? 0,
                    'currency'            => $validated['currency'] ?? 'EGP',
                    'payment_terms_days'  => $validated['payment_terms_days'] ?? 30,
                    'due_date'            => $validated['due_date'] ?? null,
                    'status'              => 'draft',
                    'po_status'           => 'draft',
                    'notes'               => $validated['notes'] ?? null,
                ]);

                $this->syncItemLines($record, $request->input('item_lines', []));
            });

        } else {

            $validated = $this->validateRecord($request);
            $this->authorizeFarm($authUser, $validated['farm_id'] ?? null);
            $companyId = $this->resolveCompanyId($authUser, $validated['farm_id'] ?? null, $request);

            DB::transaction(function () use ($validated, $companyId, $request) {
                $record = ProcurementRecord::create([
                    'company_id'               => $companyId,
                    'po_type'                  => 'cattle',
                    'farm_id'                  => $validated['farm_id'],
                    'supplier_id'              => $validated['supplier_id'],
                    'lot_id'                   => $validated['lot_id'] ?? null,
                    'date'                     => $validated['date'],
                    'po_reference'             => null,
                    'invoice_ref'              => $validated['invoice_ref'] ?? null,
                    'min_head'                 => $validated['min_head'],
                    'max_head'                 => $validated['max_head'],
                    'head_count'               => $validated['max_head'],
                    'received_head'            => 0,
                    'estimated_price_per_kg'   => $validated['estimated_price_per_kg'] ?? null,
                    'estimated_avg_weight_kg'  => $validated['estimated_avg_weight_kg'] ?? null,
                    'subtotal'                 => $validated['subtotal'] ?? 0,
                    'freight_cost'             => $validated['freight_cost'] ?? 0,
                    'other_costs'              => $validated['other_costs'] ?? 0,
                    'total_amount'             => $validated['total_amount'] ?? 0,
                    'currency'                 => $validated['currency'] ?? 'EGP',
                    'payment_terms_days'       => $validated['payment_terms_days'] ?? 30,
                    'due_date'                 => $validated['due_date'] ?? null,
                    'status'                   => 'draft',
                    'po_status'                => 'draft',
                    'notes'                    => $validated['notes'] ?? null,
                ]);

                $this->syncLines($record, $request->input('lines', []));
            });

        }

        return redirect()->route('procurement.index')
            ->with('success', __('messages.procurement_created'));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view procurement'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        $procurementRecord->load([
            'supplier:id,name_en,name_ar',
            'farm:id,name_en,name_ar',
            'lot:id,name',
            'lines',
            'receiptBatches.inventoryTransaction',
            'receiptBatches.createdBy:id,name',
            'itemLines.inventoryItem',
            'itemLines.location',
            'inventoryReceiptBatches.receiptLines.inventoryItem',
            'inventoryReceiptBatches.receiptLines.location',
            'inventoryReceiptBatches.createdBy:id,name',
            'inventoryReceiptBatches.journalEntry:id,reference,status',
        ]);

        // Linked AP invoices — cattle POs via animal_receipt_batches chain
        $linkedInvoicesCattle = DB::table('ap_invoices as ai')
            ->join('inventory_transactions as it', 'it.ap_invoice_id', '=', 'ai.id')
            ->join('animal_receipt_batches as arb', 'arb.inventory_transaction_id', '=', 'it.id')
            ->where('arb.procurement_id', $procurementRecord->id)
            ->select('ai.id', 'ai.reference', 'ai.date', 'ai.total', 'ai.paid', 'ai.balance', 'ai.status', 'ai.currency')
            ->distinct()
            ->get();

        // Linked AP invoices — inventory POs via inventory_receipt_lines chain
        $linkedInvoicesInventory = DB::table('ap_invoices as ai')
            ->join('inventory_transactions as it', 'it.ap_invoice_id', '=', 'ai.id')
            ->join('inventory_receipt_lines as irl', 'irl.inventory_transaction_id', '=', 'it.id')
            ->join('inventory_receipt_batches as irb', 'irb.id', '=', 'irl.receipt_batch_id')
            ->where('irb.procurement_id', $procurementRecord->id)
            ->select('ai.id', 'ai.reference', 'ai.date', 'ai.total', 'ai.paid', 'ai.balance', 'ai.status', 'ai.currency')
            ->distinct()
            ->get();

        $linkedInvoices = $linkedInvoicesCattle->merge($linkedInvoicesInventory)->unique('id')->values();

        return Inertia::render('Procurement/Show', [
            'procurement'   => $this->formatRecord($procurementRecord),
            'linkedInvoices'=> $linkedInvoices,
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        abort_if(
            !in_array($procurementRecord->po_status, ['draft']),
            422,
            __('messages.po_cannot_edit_after_open')
        );

        if ($procurementRecord->po_type === 'inventory') {
            $procurementRecord->load(['itemLines.inventoryItem', 'itemLines.location', 'supplier', 'farm']);
        } else {
            $procurementRecord->load(['lines', 'supplier', 'farm', 'lot']);
        }

        $props = [
            'mode'        => 'edit',
            'procurement' => $this->formatRecord($procurementRecord),
            'farms'       => $this->availableFarms($authUser),
            'lots'        => $this->availableLots($authUser),
            'suppliers'   => $this->availableSuppliers($authUser),
            'companies'   => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ];

        if ($procurementRecord->po_type === 'inventory') {
            $props['inventoryItems'] = $this->availableMasterItems($authUser);
            $props['locations']      = $this->availableLocations($authUser);
        }

        return Inertia::render('Procurement/CreateEdit', $props);
        
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        // Only draft POs can be edited — once open, the PO is locked
        abort_if(
            $procurementRecord->po_status !== 'draft',
            422,
            __('messages.po_cannot_edit_after_open')
        );

        $this->sanitizeNullableFields($request);

        if ($procurementRecord->po_type === 'inventory') {

            $validated = $this->validateInventoryPoRecord($request);

            DB::transaction(function () use ($procurementRecord, $validated, $request) {
                $procurementRecord->update([
                    'supplier_id'         => $validated['supplier_id'],
                    'date'                => $validated['date'],
                    'invoice_ref'         => $validated['invoice_ref'] ?? null,
                    'subtotal'            => $validated['subtotal'] ?? 0,
                    'freight_cost'        => $validated['freight_cost'] ?? 0,
                    'other_costs'         => $validated['other_costs'] ?? 0,
                    'total_amount'        => $validated['total_amount'] ?? 0,
                    'currency'            => $validated['currency'] ?? $procurementRecord->currency,
                    'payment_terms_days'  => $validated['payment_terms_days'] ?? 30,
                    'due_date'            => $validated['due_date'] ?? null,
                    'notes'               => $validated['notes'] ?? null,
                ]);

                $this->syncItemLines($procurementRecord, $request->input('item_lines', []));
            });

        } else {

            $validated = $this->validateRecord($request, $procurementRecord->id);

            DB::transaction(function () use ($procurementRecord, $validated, $request) {
                $procurementRecord->update([
                    'farm_id'                  => $validated['farm_id'],
                    'supplier_id'              => $validated['supplier_id'],
                    'lot_id'                   => $validated['lot_id'] ?? null,
                    'date'                     => $validated['date'],
                    'invoice_ref'              => $validated['invoice_ref'] ?? null,
                    'min_head'                 => $validated['min_head'],
                    'max_head'                 => $validated['max_head'],
                    'head_count'               => $validated['max_head'],
                    'estimated_price_per_kg'   => $validated['estimated_price_per_kg'] ?? null,
                    'estimated_avg_weight_kg'  => $validated['estimated_avg_weight_kg'] ?? null,
                    'subtotal'                 => $validated['subtotal'] ?? 0,
                    'freight_cost'             => $validated['freight_cost'] ?? 0,
                    'other_costs'              => $validated['other_costs'] ?? 0,
                    'total_amount'             => $validated['total_amount'] ?? 0,
                    'currency'                 => $validated['currency'] ?? $procurementRecord->currency,
                    'payment_terms_days'       => $validated['payment_terms_days'] ?? 30,
                    'due_date'                 => $validated['due_date'] ?? null,
                    'notes'                    => $validated['notes'] ?? null,
                ]);

                $this->syncLines($procurementRecord, $request->input('lines', []));
            });

        }

        return redirect()->route('procurement.show', $procurementRecord->id)
            ->with('success', __('messages.procurement_updated'));
    }

    // ── Confirm PO (draft → open) ─────────────────────────────────
    // Transitions the PO from draft to open so animals can be received.
    // Does NOT create any animals or accounting entries.
    // Generates the auto-reference number (PO/CO3/YYYY/MM/NNN).
   public function confirmPo(Request $request, ProcurementRecord $procurementRecord)
    {
    $authUser = Auth::user();

    abort_unless(
        $authUser->is_super_admin ||
        $authUser->hasPermissionTo('edit procurement'),
        403
    );

    // Guard: this method is for cattle POs only.
    // Inventory POs must use confirmInventoryPo().
    abort_unless(
        $procurementRecord->po_type === 'cattle',
        422,
        'This is an inventory PO. Use the Confirm Inventory PO action.'
    );

    $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        DB::transaction(function () use ($procurementRecord) {
            $fresh = ProcurementRecord::lockForUpdate()->findOrFail($procurementRecord->id);

            abort_if(
                $fresh->po_status !== 'draft',
                422,
                __('messages.po_already_confirmed')
            );

            // Validate PO has minimum required fields before opening
            abort_if(
                $fresh->min_head < 1,
                422,
                __('messages.po_min_head_required')
            );

            abort_if(
                $fresh->max_head < $fresh->min_head,
                422,
                __('messages.po_max_head_invalid')
            );

            abort_if(
                empty($fresh->estimated_price_per_kg) || $fresh->estimated_price_per_kg <= 0,
                422,
                __('messages.po_price_required')
            );

            $fresh->update([
                'po_status'    => 'open',
                'status'       => 'confirmed',                  // legacy field
                'po_reference' => $this->generatePoReference($fresh),
            ]);
        });

        return back()->with('success', __('messages.po_confirmed'));
    }

    // ── Receive Animals (renders Animals/CreateEdit.vue with PO) ──
    // This is the route hit by the [+ Receive Animals] button on Index.vue.
    // No form submission happens here — it just renders the Vue page
    // with the PO injected as a prop.
    public function receive(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create animals'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        // Guard: PO must be in an open-for-receipt status
        abort_unless(
            in_array($procurementRecord->po_status, [
                'open', 'partially_received', 'on_track', 'over_received'
            ]),
            422,
            __('messages.po_not_open_for_receipt')
        );

        $procurementRecord->load(['supplier:id,name_en,name_ar', 'farm:id,name_en,name_ar', 'lot:id,name']);

        // Renders Animals/CreateEdit.vue with the PO injected.
        // The Vue page detects the `po` prop and renders in purchase mode.
        return Inertia::render('Animals/CreateEdit', [
            'mode'         => 'purchase',
            'po'           => $this->formatPoForReceive($procurementRecord),
            'farms'        => collect(),    // locked from PO — no selector needed
            'lots'         => collect(),    // locked from PO — no selector needed
            'suppliers'    => collect(),    // locked from PO — no selector needed
        ]);
    }

    // ── Confirm Receipt (animals submitted from Animals/CreateEdit) ─
    // Called when the user saves from the purchase-mode form.
    // Delegates entirely to LivestockReceiptService::confirmReceipt().
    public function confirmReceipt(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create animals'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        // Validate animals array
        $request->validate([
            'animals'                    => 'required|array|min:1|max:5000',
            'animals.*.tag'              => [
                'required', 'string', 'max:255',
                Rule::unique('animals', 'tag')
                    ->where('company_id', $procurementRecord->company_id),
            ],
            'animals.*.sex'              => ['required', Rule::in(['male', 'female', 'castrated'])],
            'animals.*.weight_kg'        => 'required|numeric|min:0.1',
            'animals.*.rfid'             => 'nullable|string|max:255',
            'animals.*.breed'            => 'nullable|string|max:255',
            'animals.*.color'            => 'nullable|string|max:255',
            'animals.*.dob'              => 'nullable|date',
            'animals.*.dob_estimated'    => 'nullable|boolean',
            'animals.*.notes'            => 'nullable|string|max:500',
            'batch_date'                 => 'required|date',
            'receipt_mode'               => ['required', Rule::in(['bulk_avg', 'bulk_range', 'individual'])],
            'freight_allocated'          => 'nullable|numeric|min:0',
            'batch_notes'                => 'nullable|string|max:1000',
        ]);

        $batch = $this->receiptService->confirmReceipt(
            procurementId: $procurementRecord->id,
            animalsData:   $request->input('animals'),
            batchMeta: [
                'date'              => $request->input('batch_date'),
                'receipt_mode'      => $request->input('receipt_mode'),
                'freight_allocated' => $request->input('freight_allocated', 0),
                'notes'             => $request->input('batch_notes'),
            ]
        );

        // Reload PO to get fresh po_status for the redirect message
        $po = $procurementRecord->fresh();

        $message = match($po->po_status) {
            'fully_received' => __('messages.receipt_confirmed_po_complete'),
            'over_received'  => __('messages.receipt_confirmed_po_over_received'),
            default          => __('messages.receipt_confirmed', ['count' => $batch->received_head]),
        };

        return redirect()
            ->route('procurement.show', $procurementRecord->id)
            ->with('success', $message);
    }

    // ── Force Close ───────────────────────────────────────────────
    public function forceClose(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        $request->validate([
            'closure_reason' => 'required|string|min:10|max:1000',
        ]);

        $this->receiptService->forceClose(
            $procurementRecord,
            $request->input('closure_reason')
        );

        return back()->with('success', __('messages.po_force_closed'));
    }

    // ══════════════════════════════════════════════════════════════
    //  INVENTORY PO FLOW — po_type = 'inventory'
    //  📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
    // ══════════════════════════════════════════════════════════════

    // ── Store Inventory PO ────────────────────────────────────────
    public function storeInventoryPo(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create procurement'),
            403
        );

        $this->sanitizeNullableFields($request);
        $validated = $this->validateInventoryPoRecord($request);
        $this->authorizeFarm($authUser, $validated['farm_id']);

        $companyId = $this->resolveCompanyId($authUser, $validated['farm_id'], $request);

        DB::transaction(function () use ($validated, $companyId, $request) {

            $record = ProcurementRecord::create([
                'company_id'          => $companyId,
                'po_type'             => 'inventory',
                'farm_id'             => $validated['farm_id'],
                'supplier_id'         => $validated['supplier_id'],
                'lot_id'              => null,               // inventory POs have no lot
                'date'                => $validated['date'],
                'po_reference'        => null,               // generated on confirmInventoryPo()
                'invoice_ref'         => $validated['invoice_ref'] ?? null,
                'subtotal'            => $validated['subtotal'] ?? 0,
                'freight_cost'        => $validated['freight_cost'] ?? 0,
                'other_costs'         => $validated['other_costs'] ?? 0,
                'total_amount'        => $validated['total_amount'] ?? 0,
                'currency'            => $validated['currency'] ?? 'EGP',
                'payment_terms_days'  => $validated['payment_terms_days'] ?? 30,
                'due_date'            => $validated['due_date'] ?? null,
                'status'              => 'draft',
                'po_status'           => 'draft',
                // cattle-specific fields — neutral defaults for inventory POs
                'min_head'            => 0,
                'max_head'            => 0,
                'head_count'          => 0,
                'received_head'       => 0,
                'notes'               => $validated['notes'] ?? null,
            ]);

            $this->syncItemLines($record, $request->input('item_lines', []));
        });

        return redirect()->route('procurement.index')
            ->with('success', __('messages.procurement_created'));
    }

    // ── Edit Inventory PO ─────────────────────────────────────────
    public function editInventoryPo(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        abort_if(
            $procurementRecord->po_status !== 'draft',
            422,
            __('messages.po_cannot_edit_after_open')
        );

        $procurementRecord->load([
            'itemLines.inventoryItem',
            'itemLines.location',
            'supplier',
            'farm',
        ]);

        return Inertia::render('Procurement/CreateEdit', [
            'mode'        => 'edit',
            'po_type'     => 'inventory',
            'procurement' => $this->formatRecord($procurementRecord),
            'farms'       => $this->availableFarms($authUser),
            'suppliers'   => $this->availableSuppliers($authUser),
            'inventoryItems' => $this->availableMasterItems($authUser),
            'locations'   => $this->availableLocations($authUser),
            'companies'   => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
        ]);
    }

    // ── Update Inventory PO ───────────────────────────────────────
    public function updateInventoryPo(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        abort_if(
            $procurementRecord->po_status !== 'draft',
            422,
            __('messages.po_cannot_edit_after_open')
        );

        $this->sanitizeNullableFields($request);
        $validated = $this->validateInventoryPoRecord($request);

        DB::transaction(function () use ($procurementRecord, $validated, $request) {
            $procurementRecord->update([
                'supplier_id'         => $validated['supplier_id'],
                'date'                => $validated['date'],
                'invoice_ref'         => $validated['invoice_ref'] ?? null,
                'subtotal'            => $validated['subtotal'] ?? 0,
                'freight_cost'        => $validated['freight_cost'] ?? 0,
                'other_costs'         => $validated['other_costs'] ?? 0,
                'total_amount'        => $validated['total_amount'] ?? 0,
                'currency'            => $validated['currency'] ?? $procurementRecord->currency,
                'payment_terms_days'  => $validated['payment_terms_days'] ?? 30,
                'due_date'            => $validated['due_date'] ?? null,
                'notes'               => $validated['notes'] ?? null,
            ]);

            $this->syncItemLines($procurementRecord, $request->input('item_lines', []));
        });

        return redirect()->route('procurement.show', $procurementRecord->id)
            ->with('success', __('messages.procurement_updated'));
    }

    // ── Confirm Inventory PO (draft → open) ───────────────────────
    public function confirmInventoryPo(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        DB::transaction(function () use ($procurementRecord) {
            $fresh = ProcurementRecord::lockForUpdate()->findOrFail($procurementRecord->id);

            abort_if(
                $fresh->po_status !== 'draft',
                422,
                __('messages.po_already_confirmed')
            );

            // Must have at least one item line before opening
            abort_if(
                $fresh->itemLines()->count() === 0,
                422,
                'Inventory PO must have at least one item line before it can be confirmed.'
            );

            $fresh->update([
                'po_status'    => 'open',
                'status'       => 'confirmed',
                'po_reference' => $this->generatePoReference($fresh),
            ]);
        });

        return back()->with('success', __('messages.po_confirmed'));
    }

    // ── Receive Inventory (renders InventoryReceive.vue) ──────────
    public function receiveInventory(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        abort_unless(
            in_array($procurementRecord->po_status, [
                'open', 'partially_received', 'on_track', 'over_received'
            ]),
            422,
            __('messages.po_not_open_for_receipt')
        );

        $procurementRecord->load([
            'supplier:id,name_en,name_ar',
            'farm:id,name_en,name_ar',
            'itemLines.inventoryItem',
            'itemLines.location',
        ]);

        return Inertia::render('Procurement/InventoryReceive', [
            'procurement' => $this->formatRecord($procurementRecord),
        ]);
    }

    // ── Confirm Inventory Receipt ─────────────────────────────────
    // Called when the InventoryReceive.vue form is submitted.
    // Delegates entirely to InventoryReceiptService::confirmReceipt().
    public function confirmInventoryReceipt(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        $request->validate([
            'batch_date'                              => 'required|date',
            'batch_notes'                             => 'nullable|string|max:1000',
            'lines'                                   => 'required|array|min:1',
            'lines.*.procurement_item_line_id'        => [
                'required',
                'integer',
                Rule::exists('procurement_item_lines', 'id')
                    ->where('procurement_id', $procurementRecord->id),
            ],
            'lines.*.qty_received'                    => 'required|numeric|min:0.001',
            'lines.*.unit_cost_actual'                => 'required|numeric|min:0',
            'lines.*.location_id'                     => 'required|exists:inventory_locations,id',
            'lines.*.notes'                           => 'nullable|string|max:500',
        ]);

        $batch = $this->inventoryReceiptService->confirmReceipt(
            procurementId: $procurementRecord->id,
            lines:         $request->input('lines'),
            batchMeta: [
                'date'  => $request->input('batch_date'),
                'notes' => $request->input('batch_notes'),
            ]
        );

        $po = $procurementRecord->fresh();

        $message = match($po->po_status) {
            'fully_received' => __('messages.receipt_confirmed_po_complete'),
            'over_received'  => __('messages.receipt_confirmed_po_over_received'),
            default          => 'Inventory receipt confirmed. Journal entry posted.',
        };

        return redirect()
            ->route('procurement.show', $procurementRecord->id)
            ->with('success', $message);
    }

    // ── Force Close Inventory PO ──────────────────────────────────
    public function forceCloseInventory(Request $request, ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit procurement'),
            403
        );

        abort_unless($procurementRecord->po_type === 'inventory', 422, 'Not an inventory PO.');
        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        $request->validate([
            'closure_reason' => 'required|string|min:10|max:1000',
        ]);

        $this->inventoryReceiptService->forceClose(
            $procurementRecord,
            $request->input('closure_reason')
        );

        return back()->with('success', __('messages.po_force_closed'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(ProcurementRecord $procurementRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete procurement'),
            403
        );

        $this->authorizeFarm($authUser, $procurementRecord->farm_id);

        // Only draft POs can be deleted — once confirmed (open), must be force-closed
        abort_if(
            $procurementRecord->po_status !== 'draft',
            422,
            __('messages.po_cannot_delete_after_open')
        );

        $procurementRecord->lines()->delete();
        $procurementRecord->itemLines()->delete();
        $procurementRecord->delete();

        return redirect()->route('procurement.index')
            ->with('success', __('messages.procurement_deleted'));
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Sync PO lines (estimate rows — breed/sex/weight per category).
     * Lines are optional and never create animals.
     * Preserves the existing syncLines() logic from Session 31 (RISK-F5 fix).
     */
    private function syncLines(ProcurementRecord $record, array $lines): void
    {
        $record->lines()->delete();

        foreach ($lines as $line) {
            $pricePerHead = isset($line['price_per_head']) && $line['price_per_head'] !== ''
                ? (string) $line['price_per_head'] : null;
            $pricePerKg   = isset($line['price_per_kg']) && $line['price_per_kg'] !== ''
                ? (string) $line['price_per_kg'] : null;
            $weightKg     = isset($line['weight_kg']) && $line['weight_kg'] !== ''
                ? (string) $line['weight_kg'] : null;
            $freight      = isset($line['freight_allocated']) && $line['freight_allocated'] !== ''
                ? (string) $line['freight_allocated'] : '0';

            // 📌 METHODOLOGY REFERENCE: BulkAnimalPriceAllocation (#12)
            if ($pricePerHead !== null) {
                $basePrice = $pricePerHead;
            } elseif ($pricePerKg !== null && $weightKg !== null) {
                $basePrice = bcmul($pricePerKg, $weightKg, 4);
            } else {
                $basePrice = '0';
            }

            $totalCost = bcadd($basePrice, $freight, 2);

            $record->lines()->create([
                'weight_kg'         => $weightKg !== null ? (float) $weightKg : null,
                'price_per_head'    => $pricePerHead !== null ? (float) $pricePerHead : null,
                'price_per_kg'      => $pricePerKg !== null ? (float) $pricePerKg : null,
                'freight_allocated' => (float) $freight,
                'total_cost'        => (float) $totalCost,
                'breed'             => $line['breed'] ?? null,
                'sex'               => $line['sex'] ?? null,
                'estimated_weight_kg' => isset($line['estimated_weight_kg']) ? (float) $line['estimated_weight_kg'] : null,
                'notes'             => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * Validate PO header and lines.
     * Lines are optional estimates — not per-animal data.
     */
    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'farm_id'                   => 'required|exists:farms,id',
            'supplier_id'               => 'required|exists:suppliers,id',
            'lot_id'                    => 'nullable|exists:lots,id',
            'date'                      => 'required|date',
            'invoice_ref'               => 'nullable|string|max:255',
            'min_head'                  => 'required|integer|min:1',
            'max_head'                  => 'required|integer|min:1|gte:min_head',
            'estimated_price_per_kg'    => 'required|numeric|min:0.01',
            'estimated_avg_weight_kg'   => 'nullable|numeric|min:0',
            'subtotal'                  => 'nullable|numeric|min:0',
            'freight_cost'              => 'nullable|numeric|min:0',
            'other_costs'               => 'nullable|numeric|min:0',
            'total_amount'              => 'nullable|numeric|min:0',
            'currency'                  => 'nullable|string|size:3',
            'payment_terms_days'        => 'nullable|integer|min:0',
            'due_date'                  => 'nullable|date',
            'notes'                     => 'nullable|string',

            // PO lines (optional estimate rows)
            'lines'                       => 'nullable|array',
            'lines.*.breed'               => 'nullable|string|max:255',
            'lines.*.sex'                 => ['nullable', Rule::in(['male', 'female', 'castrated', 'mixed'])],
            'lines.*.estimated_weight_kg' => 'nullable|numeric|min:0',
            'lines.*.price_per_head'      => 'nullable|numeric|min:0',
            'lines.*.price_per_kg'        => 'nullable|numeric|min:0',
            'lines.*.freight_allocated'   => 'nullable|numeric|min:0',
            'lines.*.notes'               => 'nullable|string|max:500',
        ]);
    }

    /**
     * Generate PO auto-reference: PO/{CO3}/{YYYY}/{MM}/{NNN}
     */
    private function generatePoReference(ProcurementRecord $po): string
    {
        $company = \App\Models\Company::find($po->company_id);
        $co3 = strtoupper(substr(str_replace(' ', '', $company?->name_en ?? 'VRO'), 0, 3));
        $year = date('Y', strtotime($po->date));
        $month = date('m', strtotime($po->date));

        $count = ProcurementRecord::where('company_id', $po->company_id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('po_status', '!=', 'draft')
            ->count();

        $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return "PO/{$co3}/{$year}/{$month}/{$seq}";
    }

    /**
     * Format PO for the receive() route (Animals/CreateEdit.vue purchase mode).
     * Contains everything the Vue form needs to pre-fill and lock fields.
     */
    private function formatPoForReceive(ProcurementRecord $po): array
    {
        return [
            'id'                      => $po->id,
            'po_reference'            => $po->po_reference,
            'date'                    => $po->getRawOriginal('date'),
            'supplier_id'             => $po->supplier_id,
            'supplier'                => $po->supplier ? [
                'id'      => $po->supplier->id,
                'name_en' => $po->supplier->name_en,
                'name_ar' => $po->supplier->name_ar,
            ] : null,
            'farm_id'                 => $po->farm_id,
            'farm'                    => $po->farm ? [
                'id'      => $po->farm->id,
                'name_en' => $po->farm->name_en,
                'name_ar' => $po->farm->name_ar,
            ] : null,
            'lot_id'                  => $po->lot_id,
            'lot'                     => $po->lot ? [
                'id'   => $po->lot->id,
                'name' => $po->lot->name,
            ] : null,
            'min_head'                => $po->min_head,
            'max_head'                => $po->max_head,
            'received_head'           => $po->received_head,
            'remaining_head'          => max(0, $po->max_head - $po->received_head),
            'estimated_price_per_kg'  => $po->estimated_price_per_kg,
            'estimated_avg_weight_kg' => $po->estimated_avg_weight_kg,
            'currency'                => $po->currency,
            'po_status'               => $po->po_status,
            'is_over_received'        => $po->received_head > $po->max_head,
        ];
    }

    /**
     * Full record format for Index/Show/CreateEdit pages.
     */
    private function formatRecord(ProcurementRecord $record): array
    {
        $canReceive = in_array($record->po_status, [
            'open', 'partially_received', 'on_track', 'over_received'
        ]);

        return [
            'id'                      => $record->id,
            'company_id'              => $record->company_id,
            'po_type'                 => $record->po_type,      // 'cattle' or 'inventory'
            'farm_id'                 => $record->farm_id,
            'farm'                    => $record->farm ? [
                'id'      => $record->farm->id,
                'name_en' => $record->farm->name_en,
                'name_ar' => $record->farm->name_ar,
            ] : null,
            'supplier_id'             => $record->supplier_id,
            'supplier'                => $record->supplier ? [
                'id'      => $record->supplier->id,
                'name_en' => $record->supplier->name_en,
                'name_ar' => $record->supplier->name_ar,
            ] : null,
            'lot_id'                  => $record->lot_id,
            'lot'                     => $record->lot ? [
                'id'   => $record->lot->id,
                'name' => $record->lot->name,
            ] : null,
            'date'                    => $record->getRawOriginal('date'),
            'po_reference'            => $record->po_reference,
            'invoice_ref'             => $record->invoice_ref,
            'min_head'                => $record->min_head,
            'max_head'                => $record->max_head,
            'received_head'           => $record->received_head,
            'remaining_head'          => max(0, $record->max_head - $record->received_head),
            // Inventory PO receipt progress — summed from item lines via withSum() in index()
            // Falls back to 0 on show/edit pages where withSum is not applied
            'ordered_qty'             => (float) ($record->ordered_qty_sum ?? 0),
            'received_qty'            => (float) ($record->received_qty_sum ?? 0),
            'estimated_price_per_kg'  => $record->estimated_price_per_kg,
            'estimated_avg_weight_kg' => $record->estimated_avg_weight_kg,
            'subtotal'                => $record->subtotal,
            'freight_cost'            => $record->freight_cost,
            'other_costs'             => $record->other_costs,
            'total_amount'            => $record->total_amount,
            'currency'                => $record->currency,
            'payment_terms_days'      => $record->payment_terms_days,
            'due_date'                => $record->getRawOriginal('due_date'),
            'status'                  => $record->status,
            'po_status'               => $record->po_status,
            'closure_reason'          => $record->closure_reason,
            'closed_at'               => $record->closed_at?->format('d M Y H:i'),
            'is_over_received'        => $record->received_head > $record->max_head,
            'can_receive'             => $canReceive,
            'can_edit'                => $record->po_status === 'draft',
            'can_delete'              => $record->po_status === 'draft',
            'can_force_close'         => in_array($record->po_status, [
                'open', 'partially_received', 'on_track', 'over_received'
            ]) && $record->received_head > 0,
            'notes'                   => $record->notes,
            'lines_count'             => $record->lines_count ?? ($record->relationLoaded('lines') ? $record->lines->count() : 0),
            'receipt_batches_count'   => $record->receipt_batches_count ?? 0,
            'lines'                   => $record->relationLoaded('lines')
                ? $record->lines->map(fn($l) => [
                    'id'                   => $l->id,
                    'breed'                => $l->breed,
                    'sex'                  => $l->sex,
                    'estimated_weight_kg'  => $l->estimated_weight_kg,
                    'price_per_head'       => $l->price_per_head,
                    'price_per_kg'         => $l->price_per_kg,
                    'freight_allocated'    => $l->freight_allocated,
                    'total_cost'           => $l->total_cost,
                    'notes'                => $l->notes,
                ])
                : [],
            'receipt_batches'         => $record->relationLoaded('receiptBatches')
                ? $record->receiptBatches->map(fn($b) => [
                    'id'                       => $b->id,
                    'date'                     => $b->getRawOriginal('date'),
                    'received_head'            => $b->received_head,
                    'subtotal'                 => $b->subtotal,
                    'receipt_mode'             => $b->receipt_mode,
                    'receipt_mode_label'       => $b->getReceiptModeLabel(),
                    'status'                   => $b->status,
                    'is_matched'               => $b->isMatched(),
                    'inventory_transaction_id' => $b->inventory_transaction_id,
                    'created_by'               => $b->createdBy?->name,
                    'notes'                    => $b->notes,
                ])
                : [],
            // Inventory PO receipt batches
            'inventory_receipt_batches' => $record->relationLoaded('inventoryReceiptBatches')
                ? $record->inventoryReceiptBatches->map(fn($b) => [
                    'id'               => $b->id,
                    'date'             => $b->getRawOriginal('date'),
                    'subtotal'         => $b->subtotal,
                    'status'           => $b->status,
                    'is_matched'       => $b->isMatched(),
                    'journal_entry'    => $b->journalEntry ? [
                        'id'        => $b->journalEntry->id,
                        'reference' => $b->journalEntry->reference,
                        'status'    => $b->journalEntry->status,
                    ] : null,
                    'created_by'       => $b->createdBy?->name,
                    'notes'            => $b->notes,
                    'lines'            => $b->relationLoaded('receiptLines')
                        ? $b->receiptLines->map(fn($l) => [
                            'id'               => $l->id,
                            'item_name_en'     => $l->inventoryItem?->name_en,
                            'item_name_ar'     => $l->inventoryItem?->name_ar,
                            'location_name_en' => $l->location?->name_en,
                            'location_name_ar' => $l->location?->name_ar,
                            'qty_received'     => $l->qty_received,
                            'unit_cost_actual' => $l->unit_cost_actual,
                            'total_actual'     => $l->total_actual,
                            'wac_before'       => $l->wac_before,
                            'wac_after'        => $l->wac_after,
                            'is_matched'       => $l->isMatched(),
                        ])
                        : [],
                ])
                : [],
            // Inventory PO item lines (ordered items)
            'item_lines'              => $record->relationLoaded('itemLines')
                ? $record->itemLines->map(fn($l) => [
                    'id'                   => $l->id,
                    'inventory_item_id'    => $l->inventory_item_id,
                    'item_name_en'         => $l->inventoryItem?->name_en,
                    'item_name_ar'         => $l->inventoryItem?->name_ar,
                    'item_category'        => $l->inventoryItem?->category,
                    'item_unit'            => $l->inventoryItem?->unit,
                    'location_id'          => $l->location_id,
                    'location_name_en'     => $l->location?->name_en,
                    'location_name_ar'     => $l->location?->name_ar,
                    'qty_ordered'          => $l->qty_ordered,
                    'qty_received'         => $l->qty_received,
                    'remaining_qty'        => $l->remainingQty(),
                    'unit_cost_estimated'  => $l->unit_cost_estimated,
                    'unit_cost_actual'     => $l->unit_cost_actual,
                    'total_estimated'      => $l->total_estimated,
                    'total_actual'         => $l->total_actual,
                    'line_status'          => $l->line_status,
                    'is_over_received'     => $l->isOverReceived(),
                    'notes'                => $l->notes,
                ])
                : [],
            'created_at'              => $record->created_at?->format('d M Y'),
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────

    /**
     * Sync item lines for an inventory PO.
     * Only deletes open lines — received/force_closed lines are immutable.
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    private function syncItemLines(ProcurementRecord $record, array $lines): void
    {
        $record->itemLines()->where('line_status', 'open')->delete();

        foreach ($lines as $line) {
            $qtyOrdered        = (string) ($line['qty_ordered'] ?? 0);
            $unitCostEstimated = (string) ($line['unit_cost_estimated'] ?? 0);
            $totalEstimated    = bcmul($qtyOrdered, $unitCostEstimated, 2);

            ProcurementItemLine::create([
                'procurement_id'      => $record->id,
                'inventory_item_id'   => $line['inventory_item_id'],
                'location_id'         => $line['location_id'],
                'qty_ordered'         => (float) $qtyOrdered,
                'qty_received'        => 0,
                'unit_cost_estimated' => (float) $unitCostEstimated,
                'unit_cost_actual'    => null,
                'total_estimated'     => (float) $totalEstimated,
                'total_actual'        => 0,
                'line_status'         => 'open',
                'notes'               => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * Validate inventory PO header fields.
     * No cattle-specific fields (min_head, max_head, estimated_price_per_kg).
     */
    private function validateInventoryPoRecord(Request $request): array
    {
        return $request->validate([
            'farm_id'             => 'required|exists:farms,id',
            'supplier_id'         => 'required|exists:suppliers,id',
            'date'                => 'required|date',
            'invoice_ref'         => 'nullable|string|max:255',
            'subtotal'            => 'nullable|numeric|min:0',
            'freight_cost'        => 'nullable|numeric|min:0',
            'other_costs'         => 'nullable|numeric|min:0',
            'total_amount'        => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|size:3',
            'payment_terms_days'  => 'nullable|integer|min:0',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',

            'item_lines'                           => 'nullable|array',
            'item_lines.*.inventory_item_id'       => 'required|exists:inventory_items,id',
            'item_lines.*.location_id'             => 'nullable|exists:inventory_locations,id',
            'item_lines.*.qty_ordered'             => 'required|numeric|min:0.001',
            'item_lines.*.unit_cost_estimated'     => 'required|numeric|min:0',
            'item_lines.*.notes'                   => 'nullable|string|max:500',
        ]);
    }

    /**
     * Master inventory items with location stock breakdown.
     * Used by the inventory PO item line repeater.
     */
    private function availableMasterItems($authUser): \Illuminate\Support\Collection
    {
        $query = InventoryItem::with('locationStocks')
            ->where('is_master', true)
            ->where('is_active', true)
            ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return $query->get()->map(fn($item) => [
            'id'              => $item->id,
            'name_en'         => $item->name_en,
            'name_ar'         => $item->name_ar,
            'sku'             => $item->sku,
            'category'        => $item->category,
            'unit'            => $item->unit,
            'location_stocks' => $item->locationStocks->map(fn($s) => [
                'stock_id'    => $s->id,
                'location_id' => $s->location_id,
                'qty_on_hand' => (float) $s->qty_on_hand,
                'unit_cost'   => (float) $s->unit_cost,
            ])->values(),
        ]);
    }

    /**
     * Active inventory locations for the item line location selector.
     */
    private function availableLocations($authUser): \Illuminate\Support\Collection
    {
        $query = InventoryLocation::with('farm:id,name_en,name_ar')
            ->where('is_active', true)
            ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return $query->get()->map(fn($l) => [
            'id'           => $l->id,
            'name_en'      => $l->name_en,
            'name_ar'      => $l->name_ar,
            'farm_id'      => $l->farm_id,
            'farm_name_en' => $l->farm?->name_en,
            'farm_name_ar' => $l->farm?->name_ar,
            'is_central'   => $l->farm_id === null,
        ]);
    }

    private function resolveCompanyId($authUser, ?int $farmId, Request $request): int
    {
        if ($authUser->is_super_admin) {
            $companyId = $farmId
                ? Farm::findOrFail($farmId)->company_id
                : (int) $request->input('company_id');
            abort_unless((int) $companyId > 0, 422, 'Company required for Super Admin');
            return $companyId;
        }
        return $authUser->company_id;
    }

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function availableLots($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Lot::where('status', 'active')->orderBy('name')->get(['id', 'farm_id', 'name'])
            : Lot::whereHas('farm', fn($q) => $q->where('company_id', $authUser->company_id))
                ->where('status', 'active')->orderBy('name')->get(['id', 'farm_id', 'name']);
    }

    private function availableSuppliers($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Supplier::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Supplier::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'name_en', 'name_ar']);
    }

    private function authorizeFarm($authUser, ?int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        if ($farmId === null) abort(422, 'farm_id is required');
        $farm = Farm::findOrFail($farmId);
        abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
    }
}