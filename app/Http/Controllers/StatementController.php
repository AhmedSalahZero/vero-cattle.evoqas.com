<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Statement Controller (Session 66)
//
//  Four statement report types, all on one unified Vue page:
//    account-ledger      → GL ledger for any CoA account
//    customer-statement  → AR invoices + collections per customer
//    supplier-statement  → AP invoices + payments per supplier
//    inventory-statement → Qty + value movements per inventory item
//
//  📌 METHODOLOGY REFERENCE: AccountLedgerStatement   (#49)
//  📌 METHODOLOGY REFERENCE: PartnerStatement          (#50)
//  📌 METHODOLOGY REFERENCE: InventoryMovementStatement (#51)
//
//  Routes:
//    GET /statements                        → index (landing, no data)
//    GET /statements/account-ledger         → accountLedger()
//    GET /statements/customer-statement     → customerStatement()
//    GET /statements/supplier-statement     → supplierStatement()
//    GET /statements/inventory-statement    → inventoryStatement()
//
//  All routes require 'view reports' permission.
//  Calculations live here or in FinancialReportService — never in Vue.
//  Super Admin: company_id from request; regular user: from auth record.
// ══════════════════════════════════════════════════════════════════

class StatementController extends Controller
{
    // ── INDEX — landing page, no statement data yet ───────────────
    public function index(Request $request)
    {
        $this->authorise('view reports');
        $companyId = $this->resolveCompany($request);

        return Inertia::render('Accounting/Statements/Index', array_merge(
            $this->sharedProps($companyId),
            [
                'statement' => null,
                'filters'   => [],
                'activeTab' => $request->get('tab', 'account-ledger'),
            ]
        ));
    }

    // ── ACCOUNT LEDGER ────────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: AccountLedgerStatement (#49)
    // GET /statements/account-ledger?account_id=&date_from=&date_to=
    public function accountLedger(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'date_from'  => 'required|date',
            'date_to'    => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = $this->resolveCompany($request);

        // Load account — verify ownership
        $account = Account::where('id', $request->account_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $normalBalance = $account->normal_balance;

        // ── Step 1: Opening balance (all posted lines BEFORE date_from) ──
        $openingRow = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('jl.account_id', $request->account_id)
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereDate('je.date', '<', $request->date_from)
            ->whereNull('jl.deleted_at')
            ->whereNull('je.deleted_at')
            ->selectRaw('SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit')
            ->first();

        $obD = (string)($openingRow->total_debit  ?? '0');
        $obC = (string)($openingRow->total_credit ?? '0');

        // 📌 BcmathMonetaryPrecision (#38)
        $opening = ($normalBalance === 'debit')
            ? bcsub($obD, $obC, 2)
            : bcsub($obC, $obD, 2);

        // ── Step 2: Period lines ──────────────────────────────────
        $periodRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('jl.account_id', $request->account_id)
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereBetween('je.date', [$request->date_from, $request->date_to])
            ->whereNull('jl.deleted_at')
            ->whereNull('je.deleted_at')
            ->orderBy('je.date')
            ->orderBy('je.id')
            ->orderBy('jl.id')
            ->select([
                'je.date',
                'je.reference',
                'je.description as entry_description',
                'je.entry_type',
                'jl.memo',
                'jl.debit',
                'jl.credit',
                'jl.foreign_currency',
                'jl.foreign_amount',
                'jl.exchange_rate',
            ])
            ->get();

        // ── Step 3: Running balance in PHP ────────────────────────
        $running     = $opening;
        $totalDebit  = '0';
        $totalCredit = '0';
        $lines       = [];

        foreach ($periodRows as $row) {
            $d = (string)($row->debit  ?? '0');
            $c = (string)($row->credit ?? '0');

            // 📌 BcmathMonetaryPrecision (#38)
            if ($normalBalance === 'debit') {
                $running = bcadd(bcsub($running, $c, 2), $d, 2);
            } else {
                $running = bcadd(bcsub($running, $d, 2), $c, 2);
            }

            $totalDebit  = bcadd($totalDebit,  $d, 2);
            $totalCredit = bcadd($totalCredit, $c, 2);

            $lines[] = [
                'date'              => substr($row->date, 0, 10),
                'reference'         => $row->reference,
                'entry_description' => $row->entry_description,
                'entry_type'        => $row->entry_type,
                'memo'              => $row->memo,
                'debit'             => (float) \App\Support\Money::round($d, 2),
                'credit'            => (float) \App\Support\Money::round($c, 2),
                'running_balance'   => (float) \App\Support\Money::round($running, 2),
                'foreign_currency'  => $row->foreign_currency,
                'foreign_amount'    => $row->foreign_amount !== null ? (float) $row->foreign_amount : null,
                'exchange_rate'     => $row->exchange_rate  !== null ? (float) $row->exchange_rate  : null,
            ];
        }

        $closing = empty($lines)
            ? (float) \App\Support\Money::round($opening, 2)
            : end($lines)['running_balance'];

        $statement = [
            'type'         => 'account-ledger',
            'account'      => [
                'id'             => $account->id,
                'code'           => $account->code,
                'name_en'        => $account->name_en,
                'name_ar'        => $account->name_ar,
                'type'           => $account->type,
                'normal_balance' => $account->normal_balance,
            ],
            'date_from'    => $request->date_from,
            'date_to'      => $request->date_to,
            'opening'      => (float) \App\Support\Money::round($opening, 2),
            'lines'        => $lines,
            'total_debit'  => (float) \App\Support\Money::round($totalDebit, 2),
            'total_credit' => (float) \App\Support\Money::round($totalCredit, 2),
            'closing'      => $closing,
        ];

        return Inertia::render('Accounting/Statements/Index', array_merge(
            $this->sharedProps($companyId),
            [
                'statement' => $statement,
                'filters'   => $request->only(['account_id', 'date_from', 'date_to', 'company_id']),
                'activeTab' => 'account-ledger',
            ]
        ));
    }

    // ── CUSTOMER STATEMENT ────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: PartnerStatement (#50)
    // GET /statements/customer-statement?customer_id=&date_from=&date_to=
    public function customerStatement(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = $this->resolveCompany($request);

        $customer = DB::table('customers')
            ->where('id', $request->customer_id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        abort_if(! $customer, 404);

        // ── Opening: net unpaid before date_from ──────────────────
        $openingInvoiced = (string)(DB::table('ar_invoices')
            ->where('customer_id', $request->customer_id)
            ->where('company_id', $companyId)
            ->whereDate('date', '<', $request->date_from)
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('deleted_at')
            ->sum('total') ?? '0');

        $openingPaid = (string)(DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('ar_invoices as ai', 'ai.id', '=', 'pa.ar_invoice_id')
            ->whereNotNull('pa.ar_invoice_id')
            ->where('ai.customer_id', $request->customer_id)
            ->where('ai.company_id', $companyId)
            ->whereDate('p.date', '<', $request->date_from)
            ->whereNull('p.deleted_at')
            ->whereNull('ai.deleted_at')
            ->sum('pa.allocated_amount') ?? '0');

        // 📌 BcmathMonetaryPrecision (#38)
        $opening = (float) bcsub($openingInvoiced, $openingPaid, 2);

        // ── Period invoices ───────────────────────────────────────
        $invoiceRows = DB::table('ar_invoices')
            ->where('customer_id', $request->customer_id)
            ->where('company_id', $companyId)
            ->whereBetween('date', [$request->date_from, $request->date_to])
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('deleted_at')
            ->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'reference', 'description', 'total', 'status', 'due_date', 'currency'])
            ->map(fn($r) => [
                'row_type'       => 'invoice',
                'date'           => substr($r->date, 0, 10),
                'reference'      => $r->reference,
                'memo'           => $r->description,
                'invoice_amount' => (float) $r->total,
                'payment_amount' => 0.0,
                'status'         => $r->status,
                'due_date'       => $r->due_date ? substr($r->due_date, 0, 10) : null,
                'currency'       => $r->currency,
                'method'         => null,
                'source_id'      => $r->id,
            ])
            ->all();

        // ── Period payments allocated to this customer ────────────
        $paymentRows = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('ar_invoices as ai', 'ai.id', '=', 'pa.ar_invoice_id')
            ->whereNotNull('pa.ar_invoice_id')
            ->where('ai.customer_id', $request->customer_id)
            ->where('ai.company_id', $companyId)
            ->whereBetween('p.date', [$request->date_from, $request->date_to])
            ->whereNull('p.deleted_at')
            ->whereNull('ai.deleted_at')
            ->orderBy('p.date')->orderBy('p.id')
            ->get(['p.id', 'p.date', 'p.reference', 'p.notes', 'pa.allocated_amount', 'p.method'])
            ->map(fn($r) => [
                'row_type'       => 'payment',
                'date'           => substr($r->date, 0, 10),
                'reference'      => $r->reference,
                'memo'           => $r->notes,
                'invoice_amount' => 0.0,
                'payment_amount' => (float) $r->allocated_amount,
                'status'         => null,
                'due_date'       => null,
                'currency'       => null,
                'method'         => $r->method,
                'source_id'      => $r->id,
            ])
            ->all();

        $statement = $this->buildPartnerStatement(
            partner:     (array) $customer,
            partnerType: 'customer',
            dateFrom:    $request->date_from,
            dateTo:      $request->date_to,
            opening:     $opening,
            invoiceRows: $invoiceRows,
            paymentRows: $paymentRows,
        );

        return Inertia::render('Accounting/Statements/Index', array_merge(
            $this->sharedProps($companyId),
            [
                'statement' => $statement,
                'filters'   => $request->only(['customer_id', 'date_from', 'date_to', 'company_id']),
                'activeTab' => 'customer-statement',
            ]
        ));
    }

    // ── SUPPLIER STATEMENT ────────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: PartnerStatement (#50)
    // GET /statements/supplier-statement?supplier_id=&date_from=&date_to=
    public function supplierStatement(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = $this->resolveCompany($request);

        $supplier = DB::table('suppliers')
            ->where('id', $request->supplier_id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        abort_if(! $supplier, 404);

        // ── Opening: net unpaid before date_from ──────────────────
        $openingInvoiced = (string)(DB::table('ap_invoices')
            ->where('supplier_id', $request->supplier_id)
            ->where('company_id', $companyId)
            ->whereDate('date', '<', $request->date_from)
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('deleted_at')
            ->sum('total') ?? '0');

        $openingPaid = (string)(DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('ap_invoices as ai', 'ai.id', '=', 'pa.ap_invoice_id')
            ->whereNotNull('pa.ap_invoice_id')
            ->where('ai.supplier_id', $request->supplier_id)
            ->where('ai.company_id', $companyId)
            ->whereDate('p.date', '<', $request->date_from)
            ->whereNull('p.deleted_at')
            ->whereNull('ai.deleted_at')
            ->sum('pa.allocated_amount') ?? '0');

        // 📌 BcmathMonetaryPrecision (#38)
        $opening = (float) bcsub($openingInvoiced, $openingPaid, 2);

        // ── Period invoices ───────────────────────────────────────
        $invoiceRows = DB::table('ap_invoices')
            ->where('supplier_id', $request->supplier_id)
            ->where('company_id', $companyId)
            ->whereBetween('date', [$request->date_from, $request->date_to])
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('deleted_at')
            ->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'reference', 'description', 'total', 'status', 'due_date', 'currency'])
            ->map(fn($r) => [
                'row_type'       => 'invoice',
                'date'           => substr($r->date, 0, 10),
                'reference'      => $r->reference,
                'memo'           => $r->description,
                'invoice_amount' => (float) $r->total,
                'payment_amount' => 0.0,
                'status'         => $r->status,
                'due_date'       => $r->due_date ? substr($r->due_date, 0, 10) : null,
                'currency'       => $r->currency,
                'method'         => null,
                'source_id'      => $r->id,
            ])
            ->all();

        // ── Period payments made to this supplier ─────────────────
        $paymentRows = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('ap_invoices as ai', 'ai.id', '=', 'pa.ap_invoice_id')
            ->whereNotNull('pa.ap_invoice_id')
            ->where('ai.supplier_id', $request->supplier_id)
            ->where('ai.company_id', $companyId)
            ->whereBetween('p.date', [$request->date_from, $request->date_to])
            ->whereNull('p.deleted_at')
            ->whereNull('ai.deleted_at')
            ->orderBy('p.date')->orderBy('p.id')
            ->get(['p.id', 'p.date', 'p.reference', 'p.notes', 'pa.allocated_amount', 'p.method'])
            ->map(fn($r) => [
                'row_type'       => 'payment',
                'date'           => substr($r->date, 0, 10),
                'reference'      => $r->reference,
                'memo'           => $r->notes,
                'invoice_amount' => 0.0,
                'payment_amount' => (float) $r->allocated_amount,
                'status'         => null,
                'due_date'       => null,
                'currency'       => null,
                'method'         => $r->method,
                'source_id'      => $r->id,
            ])
            ->all();

        $statement = $this->buildPartnerStatement(
            partner:     (array) $supplier,
            partnerType: 'supplier',
            dateFrom:    $request->date_from,
            dateTo:      $request->date_to,
            opening:     $opening,
            invoiceRows: $invoiceRows,
            paymentRows: $paymentRows,
        );

        return Inertia::render('Accounting/Statements/Index', array_merge(
            $this->sharedProps($companyId),
            [
                'statement' => $statement,
                'filters'   => $request->only(['supplier_id', 'date_from', 'date_to', 'company_id']),
                'activeTab' => 'supplier-statement',
            ]
        ));
    }

    // ── INVENTORY STATEMENT ───────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: InventoryMovementStatement (#51)
    // GET /statements/inventory-statement?item_id=&location_id=&date_from=&date_to=
    public function inventoryStatement(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'item_id'     => 'required|integer|exists:inventory_items,id',
            'location_id' => 'nullable|integer|exists:inventory_locations,id',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = $this->resolveCompany($request);

        // Load master item — must be is_master = true and belong to company
        $masterItem = InventoryItem::where('id', $request->item_id)
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->firstOrFail();

        // Resolve location filter
        $location = null;
        if ($request->filled('location_id')) {
            $location = DB::table('inventory_locations')
                ->where('id', $request->location_id)
                ->where('company_id', $companyId)
                ->first(['id', 'name_en', 'name_ar', 'farm_id']);
            abort_if(! $location, 404);
        }

        // Resolve stock row IDs for this master item (all, or single location)
        $stockIds = InventoryItem::where('master_item_id', $masterItem->id)
            ->when($request->filled('location_id'), fn($q) => $q->where('location_id', $request->location_id))
            ->pluck('id');

        // ── Step 1: Opening qty + value (before date_from) ────────
        // qty_signed is a model accessor — NOT a DB column.
        // Derive sign from type in SQL using CASE expression.
        $signedExpr = "CASE
            WHEN type IN ('receipt','transfer_in','mix_in') THEN qty
            WHEN type IN ('consumption','transfer_out','mix_out') THEN -qty
            ELSE qty
        END";

        $openingRow = DB::table('inventory_transactions')
            ->whereIn('item_id', $stockIds)
            ->where('company_id', $companyId)
            ->whereDate('date', '<', $request->date_from)
            ->selectRaw("SUM({$signedExpr}) as opening_qty, SUM(({$signedExpr}) * unit_cost) as opening_value")
            ->first();

        $openingQty   = (string)($openingRow->opening_qty   ?? '0');
        $openingValue = (string)($openingRow->opening_value ?? '0');

        // ── Step 2: Period transactions ───────────────────────────
        // Excludes livestock_receipt rows (item_id = NULL, Methodology #41)
        $periodRows = DB::table('inventory_transactions as it')
            ->leftJoin('inventory_locations as il', 'il.id', '=', 'it.location_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'it.supplier_id')
            ->whereIn('it.item_id', $stockIds)
            ->where('it.company_id', $companyId)
            ->whereBetween('it.date', [$request->date_from, $request->date_to])
            ->orderBy('it.date')
            ->orderBy('it.id')
            ->select([
                'it.id',
                'it.date',
                'it.type',
                'it.ref_type',
                'it.ref_no',
                'it.qty',
                'it.unit_cost',
                'it.total',
                'it.transfer_batch_ref',
                'it.notes',
                'il.name_en as location_name_en',
                'il.name_ar as location_name_ar',
                's.name_en as supplier_name_en',
                's.name_ar as supplier_name_ar',
            ])
            ->get();

        // ── Step 3: Running qty and value in PHP ──────────────────
        $runningQty   = $openingQty;
        $runningValue = $openingValue;
        $totalQtyIn   = '0';
        $totalQtyOut  = '0';
        $totalValIn   = '0';
        $totalValOut  = '0';
        $lines        = [];

        foreach ($periodRows as $row) {
            $qty      = (string)($row->qty       ?? '0');
            $cost     = (string)($row->unit_cost ?? '0');
            $total    = (string)($row->total     ?? '0');

            // Derive sign from type — receipt/transfer_in = inbound, consumption/transfer_out = outbound
            $isInbound = in_array($row->type, ['receipt', 'transfer_in', 'adjustment', 'mix_in']);
            $signed    = $isInbound ? $qty : bcsub('0', $qty, 3);

            // 📌 BcmathMonetaryPrecision (#38) — qty at scale=3, value at scale=2
            $runningQty   = bcadd($runningQty,   $signed, 3);
            $runningValue = bcadd($runningValue, bcmul($signed, $cost, 4), 2);

            if ($isInbound) {
                $totalQtyIn  = bcadd($totalQtyIn,  $qty,   3);
                $totalValIn  = bcadd($totalValIn,  $total, 2);
            } else {
                $totalQtyOut = bcadd($totalQtyOut, $qty,   3);
                $totalValOut = bcadd($totalValOut, $total, 2);
            }

            $lines[] = [
                'date'               => substr($row->date, 0, 10),
                'type'               => $row->type,
                'ref_no'             => $row->ref_no,
                'notes'              => $row->notes,
                'transfer_batch_ref' => $row->transfer_batch_ref,
                'qty_in'             => $isInbound  ? (float) bcadd($qty, '0', 3) : 0.0,
                'qty_out'            => !$isInbound ? (float) bcadd($qty, '0', 3) : 0.0,
                'unit_cost'          => (float) bcadd($cost, '0', 4),
                'total'              => (float) \App\Support\Money::round($total, 2),
                'running_qty'        => (float) bcadd($runningQty,   '0', 3),
                'running_value'      => (float) \App\Support\Money::round($runningValue, 2),
                'location_name_en'   => $row->location_name_en,
                'location_name_ar'   => $row->location_name_ar,
                'supplier_name_en'   => $row->supplier_name_en,
                'supplier_name_ar'   => $row->supplier_name_ar,
            ];
        }

        $closingQty   = empty($lines) ? (float) bcadd($openingQty,   '0', 3) : end($lines)['running_qty'];
        $closingValue = empty($lines) ? (float) \App\Support\Money::round($openingValue, 2) : end($lines)['running_value'];

        $statement = [
            'type'           => 'inventory-statement',
            'item'           => [
                'id'       => $masterItem->id,
                'name_en'  => $masterItem->name_en,
                'name_ar'  => $masterItem->name_ar,
                'sku'      => $masterItem->sku,
                'category' => $masterItem->category,
                'unit'     => $masterItem->unit,
            ],
            'location'       => $location ? [
                'id'      => $location->id,
                'name_en' => $location->name_en,
                'name_ar' => $location->name_ar,
            ] : null,
            'date_from'      => $request->date_from,
            'date_to'        => $request->date_to,
            'opening_qty'    => (float) bcadd($openingQty,   '0', 3),
            'opening_value'  => (float) \App\Support\Money::round($openingValue, 2),
            'lines'          => $lines,
            'total_qty_in'   => (float) bcadd($totalQtyIn,  '0', 3),
            'total_qty_out'  => (float) bcadd($totalQtyOut, '0', 3),
            'total_value_in' => (float) \App\Support\Money::round($totalValIn, 2),
            'total_val_out'  => (float) \App\Support\Money::round($totalValOut, 2),
            'closing_qty'    => $closingQty,
            'closing_value'  => $closingValue,
        ];

        return Inertia::render('Accounting/Statements/Index', array_merge(
            $this->sharedProps($companyId),
            [
                'statement' => $statement,
                'filters'   => $request->only(['item_id', 'location_id', 'date_from', 'date_to', 'company_id']),
                'activeTab' => 'inventory-statement',
            ]
        ));
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * 📌 METHODOLOGY REFERENCE: PartnerStatement (#50)
     * Shared builder for customer and supplier statements.
     * Merges invoice rows + payment rows, sorts by date, computes running balance.
     */
    private function buildPartnerStatement(
        array  $partner,
        string $partnerType,
        string $dateFrom,
        string $dateTo,
        float  $opening,
        array  $invoiceRows,
        array  $paymentRows,
    ): array {
        // Merge and sort by date then source_id
        $allRows = array_merge($invoiceRows, $paymentRows);
        usort($allRows, fn($a, $b) =>
            strcmp($a['date'], $b['date']) ?: ($a['source_id'] <=> $b['source_id'])
        );

        // Running balance — invoice rows add, payment rows subtract
        $running         = (string) $opening;
        $totalInvoiced   = '0';
        $totalPaid       = '0';

        foreach ($allRows as &$row) {
            // 📌 BcmathMonetaryPrecision (#38)
            $running       = bcadd($running,       (string) $row['invoice_amount'], 2);
            $running       = bcsub($running,       (string) $row['payment_amount'], 2);
            $totalInvoiced = bcadd($totalInvoiced, (string) $row['invoice_amount'], 2);
            $totalPaid     = bcadd($totalPaid,     (string) $row['payment_amount'], 2);
            $row['running_balance'] = (float) \App\Support\Money::round($running, 2);
        }
        unset($row);

        return [
            'type'            => $partnerType === 'customer' ? 'customer-statement' : 'supplier-statement',
            'partner'         => [
                'id'                  => $partner['id'],
                'name_en'             => $partner['name_en'],
                'name_ar'             => $partner['name_ar'] ?? null,
                'code'                => $partner['code'] ?? null,
                'phone'               => $partner['phone'] ?? null,
                'email'               => $partner['email'] ?? null,
                'payment_terms_days'  => $partner['payment_terms_days'] ?? null,
            ],
            'partner_type'    => $partnerType,
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'opening'         => $opening,
            'rows'            => $allRows,
            'total_invoiced'  => (float) \App\Support\Money::round($totalInvoiced, 2),
            'total_paid'      => (float) \App\Support\Money::round($totalPaid, 2),
            'closing'         => empty($allRows) ? $opening : end($allRows)['running_balance'],
        ];
    }

    /**
     * Shared props for every Inertia render — populates all filter dropdowns.
     */
    private function sharedProps(int $companyId): array
    {
        $user = Auth::user();

        return [
            'companies'     => $user->is_super_admin
                ? \App\Models\Company::select('id', 'name_en', 'name_ar')->orderBy('name_en')->get()
                : [],
            'accounts'      => $this->availableAccounts($companyId),
            'customers'     => $this->availableCustomers($companyId),
            'suppliers'     => $this->availableSuppliers($companyId),
            'inventoryItems'=> $this->availableInventoryItems($companyId),
            'locations'     => $this->availableLocations($companyId),
        ];
    }

    /** Posting accounts for the company — for account ledger picker */
    private function availableAccounts(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);
    }

    /** Active customers for the company */
    private function availableCustomers(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('customers')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'code', 'phone', 'payment_terms_days']);
    }

    /** Active suppliers for the company */
    private function availableSuppliers(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('suppliers')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'code', 'phone', 'payment_terms_days']);
    }

    /** Master inventory items for the company */
    private function availableInventoryItems(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('inventory_items')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'sku', 'category', 'unit']);
    }

    /** All active locations for the company — for inventory location filter */
    private function availableLocations(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('inventory_locations as il')
            ->leftJoin('farms as f', 'f.id', '=', 'il.farm_id')
            ->where('il.company_id', $companyId)
            ->where('il.is_active', true)
            ->orderBy('il.name_en')
            ->get([
                'il.id',
                'il.name_en',
                'il.name_ar',
                'il.farm_id',
                'f.name_en as farm_name_en',
                'f.name_ar as farm_name_ar',
            ]);
    }

    /**
     * Resolve company_id — SA from request, regular user from auth record.
     * Never returns 0.
     */
    private function resolveCompany(Request $request): int
    {
        $user = Auth::user();

        if (! $user->is_super_admin) {
            return (int) $user->company_id;
        }

        // §6.5 — session-based company resolution for Super Admin
        $id = (int) $request->session()->get('selected_company_id', 0);
        abort_unless($id > 0, 422, 'Please enter a company first from the Companies page.');
        return $id;
    }

    /** Permission guard */
    private function authorise(string $permission): void
    {
        $user = Auth::user();
        abort_unless(
            $user->is_super_admin || $user->hasPermissionTo($permission),
            403
        );
    }
}