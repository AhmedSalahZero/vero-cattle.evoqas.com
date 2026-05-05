<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Analytics Report Controller
//
//  Thin controller — all calculation logic is inline via DB queries
//  or delegated to FinancialReportService. No calculations in Vue.
//
//  Routes:
//    GET  /analytics                     → index (landing)
//    GET  /analytics/financial-ratios    → financialRatios
//    GET  /analytics/top-customers       → topCustomers
//    GET  /analytics/top-customers-breed → topCustomersByBreed
//    GET  /analytics/top-suppliers       → topSuppliers
//    GET  /analytics/top-suppliers-item  → topSuppliersByItem
//    GET  /analytics/feeding-duration    → feedingDuration
//    POST /analytics/ratio-comment       → saveRatioComment
//
//  All routes require 'view reports' permission.
//
//  Methodologies implemented:
//    #32 FinancialRatioAnalysis
//    #33 TopCustomerRanking
//    #34 TopCustomerByBreed
//    #35 TopSupplierRanking
//    #36 TopSupplierByInventoryItem
//    #37 FeedingDurationAnalysis
// ══════════════════════════════════════════════════════════════════

class AnalyticsReportController extends Controller
{
    public function __construct(
        private FinancialReportService $financials,
    ) {}

    // ── INDEX — landing, no report data yet ───────────────────────
    public function index(Request $request)
    {
        $this->authorise('view reports');
        $companyId = $this->resolveCompany($request);

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => null,
                'activeTab' => $request->get('tab', 'financial-ratios'),
            ]
        ));
    }

    // ── TAB 1 — FINANCIAL RATIOS ──────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: FinancialRatioAnalysis (#32)
    public function financialRatios(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'as_of_date'     => 'required|date',
            'date_from'      => 'required|date',
            'date_to'        => 'required|date|after_or_equal:date_from',
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
        ]);

        $companyId    = $this->resolveCompany($request);
        $asOfDate     = $request->as_of_date;
        $dateFrom     = $request->date_from;
        $dateTo       = $request->date_to;
        $fiscalYearId = $request->fiscal_year_id;

        // Pull Balance Sheet and P&L from existing services
        $bs = $this->financials->balanceSheet($companyId, $asOfDate, $fiscalYearId);
        $pl = $this->financials->profitAndLoss($companyId, $dateFrom, $dateTo);

        // ── Derive sub-totals from Balance Sheet ─────────────────
        $currentAssets = collect($bs['assets'])
            ->filter(fn($a) => !str_starts_with($a['code'] ?? '', '16'))
            ->sum('balance');

        $inventory = collect($bs['assets'])
            ->filter(fn($a) => str_starts_with($a['code'] ?? '', '13'))
            ->sum('balance');

        $currentLiabilities = collect($bs['liabilities'])
            ->filter(fn($a) => !str_starts_with($a['code'] ?? '', '23'))
            ->sum('balance');

        $totalAssets      = $bs['total_assets'];
        $totalLiabilities = $bs['total_liabilities'];
        $totalEquity      = $bs['total_equity'];

        // ── P&L values ───────────────────────────────────────────
        $revenue     = $pl['current']['revenue']     ?? 0;
        $cogs        = $pl['current']['cogs']         ?? 0;
        $grossProfit = $pl['current']['gross_profit'] ?? 0;
        $netIncome   = $pl['current']['net_income']   ?? 0;

        // Average inventory (opening + closing) / 2
        // Opening: balance sheet one day before date_from
        $bsOpen       = $this->financials->balanceSheet($companyId, $dateFrom, $fiscalYearId);
        $inventoryOpen = collect($bsOpen['assets'])
            ->filter(fn($a) => str_starts_with($a['code'] ?? '', '13'))
            ->sum('balance');
        $avgInventory = ($inventoryOpen + $inventory) / 2;

        // ── Compute ratios — guard division by zero ───────────────
        $safe = fn($n, $d) => ($d != 0) ? round($n / $d, 4) : null;

        $ratios = [
            // — Liquidity —
            [
                'key'         => 'current_ratio',
                'group'       => 'liquidity',
                'label_en'    => 'Current Ratio',
                'label_ar'    => 'نسبة التداول',
                'formula_en'  => 'Current Assets ÷ Current Liabilities',
                'formula_ar'  => 'الأصول المتداولة ÷ الالتزامات المتداولة',
                'value'       => $safe($currentAssets, $currentLiabilities),
                'benchmark'   => ['good_above' => 1.5, 'warning_below' => 1.0],
                'unit'        => 'x',
                'higher_is'   => 'better',
            ],
            [
                'key'         => 'quick_ratio',
                'group'       => 'liquidity',
                'label_en'    => 'Quick Ratio',
                'label_ar'    => 'نسبة السيولة السريعة',
                'formula_en'  => '(Current Assets − Inventory) ÷ Current Liabilities',
                'formula_ar'  => '(الأصول المتداولة − المخزون) ÷ الالتزامات المتداولة',
                'value'       => $safe($currentAssets - $inventory, $currentLiabilities),
                'benchmark'   => ['good_above' => 1.0, 'warning_below' => 0.5],
                'unit'        => 'x',
                'higher_is'   => 'better',
            ],

            // — Profitability —
            [
                'key'         => 'gross_margin_pct',
                'group'       => 'profitability',
                'label_en'    => 'Gross Margin %',
                'label_ar'    => 'هامش الربح الإجمالي %',
                'formula_en'  => 'Gross Profit ÷ Revenue × 100',
                'formula_ar'  => 'الربح الإجمالي ÷ الإيرادات × 100',
                'value'       => ($safe($grossProfit, $revenue) !== null) ? round($safe($grossProfit, $revenue) * 100, 2) : null,
                'benchmark'   => ['good_above' => 20, 'warning_below' => 10],
                'unit'        => '%',
                'higher_is'   => 'better',
            ],
            [
                'key'         => 'net_profit_margin_pct',
                'group'       => 'profitability',
                'label_en'    => 'Net Profit Margin %',
                'label_ar'    => 'هامش صافي الربح %',
                'formula_en'  => 'Net Income ÷ Revenue × 100',
                'formula_ar'  => 'صافي الربح ÷ الإيرادات × 100',
                'value'       => ($safe($netIncome, $revenue) !== null) ? round($safe($netIncome, $revenue) * 100, 2) : null,
                'benchmark'   => ['good_above' => 10, 'warning_below' => 5],
                'unit'        => '%',
                'higher_is'   => 'better',
            ],
            [
                'key'         => 'return_on_assets',
                'group'       => 'profitability',
                'label_en'    => 'Return on Assets (ROA)',
                'label_ar'    => 'العائد على الأصول',
                'formula_en'  => 'Net Income ÷ Total Assets × 100',
                'formula_ar'  => 'صافي الربح ÷ إجمالي الأصول × 100',
                'value'       => ($safe($netIncome, $totalAssets) !== null) ? round($safe($netIncome, $totalAssets) * 100, 2) : null,
                'benchmark'   => ['good_above' => 5, 'warning_below' => 2],
                'unit'        => '%',
                'higher_is'   => 'better',
            ],
            [
                'key'         => 'return_on_equity',
                'group'       => 'profitability',
                'label_en'    => 'Return on Equity (ROE)',
                'label_ar'    => 'العائد على حقوق الملكية',
                'formula_en'  => 'Net Income ÷ Total Equity × 100',
                'formula_ar'  => 'صافي الربح ÷ حقوق الملكية × 100',
                'value'       => ($safe($netIncome, $totalEquity) !== null) ? round($safe($netIncome, $totalEquity) * 100, 2) : null,
                'benchmark'   => ['good_above' => 15, 'warning_below' => 8],
                'unit'        => '%',
                'higher_is'   => 'better',
            ],

            // — Leverage —
            [
                'key'         => 'debt_to_equity',
                'group'       => 'leverage',
                'label_en'    => 'Debt-to-Equity',
                'label_ar'    => 'نسبة الدين إلى حقوق الملكية',
                'formula_en'  => 'Total Liabilities ÷ Total Equity',
                'formula_ar'  => 'إجمالي الالتزامات ÷ حقوق الملكية',
                'value'       => $safe($totalLiabilities, $totalEquity),
                'benchmark'   => ['good_below' => 1.0, 'warning_above' => 2.0],
                'unit'        => 'x',
                'higher_is'   => 'worse',
            ],
            [
                'key'         => 'debt_to_assets',
                'group'       => 'leverage',
                'label_en'    => 'Debt-to-Assets',
                'label_ar'    => 'نسبة الدين إلى الأصول',
                'formula_en'  => 'Total Liabilities ÷ Total Assets',
                'formula_ar'  => 'إجمالي الالتزامات ÷ إجمالي الأصول',
                'value'       => $safe($totalLiabilities, $totalAssets),
                'benchmark'   => ['good_below' => 0.5, 'warning_above' => 0.7],
                'unit'        => 'x',
                'higher_is'   => 'worse',
            ],

            // — Efficiency —
            [
                'key'         => 'asset_turnover',
                'group'       => 'efficiency',
                'label_en'    => 'Asset Turnover',
                'label_ar'    => 'معدل دوران الأصول',
                'formula_en'  => 'Revenue ÷ Total Assets',
                'formula_ar'  => 'الإيرادات ÷ إجمالي الأصول',
                'value'       => $safe($revenue, $totalAssets),
                'benchmark'   => ['good_above' => 0.5, 'warning_below' => 0.2],
                'unit'        => 'x',
                'higher_is'   => 'better',
            ],
            [
                'key'         => 'inventory_turnover',
                'group'       => 'efficiency',
                'label_en'    => 'Inventory Turnover',
                'label_ar'    => 'معدل دوران المخزون',
                'formula_en'  => 'COGS ÷ Average Inventory',
                'formula_ar'  => 'تكلفة المبيعات ÷ متوسط المخزون',
                'value'       => $safe($cogs, $avgInventory),
                'benchmark'   => ['good_above' => 4, 'warning_below' => 2],
                'unit'        => 'x',
                'higher_is'   => 'better',
            ],
        ];

        // ── Load saved comments for this company + fiscal year ────
        if ($fiscalYearId) {
            $comments = DB::table('financial_ratio_comments')
                ->where('company_id', $companyId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->get()
                ->keyBy('ratio_key');

            $ratios = array_map(function ($r) use ($comments) {
                $c = $comments->get($r['key']);
                $r['comment'] = $c?->comment;
                $r['action']  = $c?->action;
                return $r;
            }, $ratios);
        } else {
            $ratios = array_map(fn($r) => array_merge($r, ['comment' => null, 'action' => null]), $ratios);
        }

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'         => [
                    'ratios'     => $ratios,
                    'bs_summary' => [
                        'total_assets'      => round($totalAssets, 2),
                        'total_liabilities' => round($totalLiabilities, 2),
                        'total_equity'      => round($totalEquity, 2),
                        'revenue'           => round($revenue, 2),
                        'gross_profit'      => round($grossProfit, 2),
                        'net_income'        => round($netIncome, 2),
                    ],
                ],
                'activeTab'      => 'financial-ratios',
                'filters'        => $request->only(['as_of_date', 'date_from', 'date_to', 'fiscal_year_id', 'company_id']),
            ]
        ));
    }

    // ── SAVE RATIO COMMENT ────────────────────────────────────────
    // POST /analytics/ratio-comment
    public function saveRatioComment(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'ratio_key'      => 'required|string|max:60',
            'comment'        => 'nullable|string|max:2000',
            'action'         => 'nullable|string|max:2000',
        ]);

        $companyId = $this->resolveCompany($request);

        DB::table('financial_ratio_comments')->upsert(
            [[
                'company_id'     => $companyId,
                'fiscal_year_id' => $request->fiscal_year_id,
                'ratio_key'      => $request->ratio_key,
                'comment'        => $request->comment,
                'action'         => $request->action,
                'updated_by'     => Auth::id(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]],
            ['company_id', 'fiscal_year_id', 'ratio_key'],
            ['comment', 'action', 'updated_by', 'updated_at']
        );

        return back()->with('success', __('messages.comment_saved'));
    }

    // ── TAB 2 — TOP CUSTOMERS ─────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: TopCustomerRanking (#33)
    public function topCustomers(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'limit'     => 'nullable|integer|min:5|max:50',
        ]);

        $companyId = $this->resolveCompany($request);
        $limit     = (int) ($request->limit ?? 10);

        $rows = DB::table('sales_records as sr')
            ->join('customers as c', 'c.id', '=', 'sr.customer_id')
            ->where('sr.company_id', $companyId)
            ->whereIn('sr.status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('sr.date', [$request->date_from, $request->date_to])
            ->groupBy('sr.customer_id', 'c.name_en', 'c.name_ar', 'c.phone', 'c.email')
            ->select([
                'sr.customer_id',
                'c.name_en',
                'c.name_ar',
                'c.phone',
                'c.email',
                DB::raw('SUM(sr.total_amount) as total_revenue'),
                DB::raw('COUNT(sr.id) as invoice_count'),
                DB::raw('AVG(sr.total_amount) as avg_invoice'),
                DB::raw('COUNT(DISTINCT DATE_FORMAT(sr.date, "%Y-%m")) as active_months'),
            ])
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        $grandTotal = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('date', [$request->date_from, $request->date_to])
            ->sum('total_amount');

        $ranked = $rows->map(function ($row, $idx) use ($grandTotal) {
            return [
                'rank'          => $idx + 1,
                'customer_id'   => $row->customer_id,
                'name_en'       => $row->name_en,
                'name_ar'       => $row->name_ar,
                'phone'         => $row->phone,
                'total_revenue' => round((float) $row->total_revenue, 2),
                'invoice_count' => (int) $row->invoice_count,
                'avg_invoice'   => round((float) $row->avg_invoice, 2),
                'active_months' => (int) $row->active_months,
                'revenue_pct'   => $grandTotal > 0
                    ? round((float) $row->total_revenue / (float) $grandTotal * 100, 2)
                    : 0,
            ];
        });

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => [
                    'ranked'      => $ranked,
                    'grand_total' => round((float) $grandTotal, 2),
                    'total_count' => $rows->count(),
                ],
                'activeTab' => 'top-customers',
                'filters'   => $request->only(['date_from', 'date_to', 'limit', 'company_id']),
            ]
        ));
    }

    // ── TAB 3 — TOP CUSTOMERS BY BREED ───────────────────────────
    // 📌 METHODOLOGY REFERENCE: TopCustomerByBreed (#34)
    public function topCustomersByBreed(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'breed'     => 'nullable|string|max:100',
            'limit'     => 'nullable|integer|min:3|max:20',
        ]);

        $companyId = $this->resolveCompany($request);
        $limit     = (int) ($request->limit ?? 5);

        // Join chain: sales_records → sales_lines → animals
        $query = DB::table('sales_records as sr')
            ->join('sales_lines as sl', 'sl.sale_id', '=', 'sr.id')
            ->join('animals as a', 'a.id', '=', 'sl.animal_id')
            ->join('customers as c', 'c.id', '=', 'sr.customer_id')
            ->where('sr.company_id', $companyId)
            ->whereIn('sr.status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('sr.date', [$request->date_from, $request->date_to])
            ->whereNotNull('sl.animal_id');

        if ($request->filled('breed')) {
            $query->where('a.breed', $request->breed);
        }

        $rows = $query
            ->groupBy('a.breed', 'sr.customer_id', 'c.name_en', 'c.name_ar')
            ->select([
                DB::raw('COALESCE(a.breed, "Unknown") as breed'),
                'sr.customer_id',
                'c.name_en',
                'c.name_ar',
                DB::raw('SUM(sl.total) as total_revenue'),
                DB::raw('COUNT(DISTINCT sl.animal_id) as head_count'),
                DB::raw('COUNT(DISTINCT sr.id) as sale_count'),
            ])
            ->orderByDesc('total_revenue')
            ->get();

        // Group by breed, rank customers within each breed
        $byBreed = $rows->groupBy('breed')->map(function ($breedRows, $breedName) use ($limit) {
            $breedTotal = $breedRows->sum('total_revenue');
            $sorted     = $breedRows->sortByDesc('total_revenue')->values()->take($limit);

            return [
                'breed'       => $breedName,
                'breed_total' => round((float) $breedTotal, 2),
                'customers'   => $sorted->map(function ($row, $idx) use ($breedTotal) {
                    return [
                        'rank'          => $idx + 1,
                        'customer_id'   => $row->customer_id,
                        'name_en'       => $row->name_en,
                        'name_ar'       => $row->name_ar,
                        'total_revenue' => round((float) $row->total_revenue, 2),
                        'head_count'    => (int) $row->head_count,
                        'sale_count'    => (int) $row->sale_count,
                        'revenue_pct'   => $breedTotal > 0
                            ? round((float) $row->total_revenue / (float) $breedTotal * 100, 2)
                            : 0,
                    ];
                })->values(),
            ];
        })->values();

        // Distinct breed list for filter dropdown
        $availableBreeds = DB::table('animals')
            ->where('company_id', $companyId)
            ->whereNotNull('breed')
            ->distinct()
            ->orderBy('breed')
            ->pluck('breed');

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => [
                    'by_breed'        => $byBreed,
                    'available_breeds'=> $availableBreeds,
                ],
                'activeTab' => 'top-customers-breed',
                'filters'   => $request->only(['date_from', 'date_to', 'breed', 'limit', 'company_id']),
            ]
        ));
    }

    // ── TAB 4 — TOP SUPPLIERS ─────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: TopSupplierRanking (#35)
    public function topSuppliers(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'limit'     => 'nullable|integer|min:5|max:50',
        ]);

        $companyId = $this->resolveCompany($request);
        $limit     = (int) ($request->limit ?? 10);

        $rows = DB::table('ap_invoices as ap')
            ->join('suppliers as s', 's.id', '=', 'ap.supplier_id')
            ->where('ap.company_id', $companyId)
            ->whereIn('ap.status', ['open', 'partially_paid', 'paid', 'overdue'])
            ->whereBetween('ap.date', [$request->date_from, $request->date_to])
            ->groupBy('ap.supplier_id', 's.name_en', 's.name_ar', 's.phone', 's.email')
            ->select([
                'ap.supplier_id',
                's.name_en',
                's.name_ar',
                's.phone',
                's.email',
                DB::raw('SUM(ap.total) as total_purchases'),
                DB::raw('COUNT(ap.id) as invoice_count'),
                DB::raw('AVG(ap.total) as avg_invoice'),
                DB::raw('SUM(ap.balance) as outstanding_balance'),
            ])
            ->orderByDesc('total_purchases')
            ->limit($limit)
            ->get();

        $grandTotal = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'paid', 'overdue'])
            ->whereBetween('date', [$request->date_from, $request->date_to])
            ->sum('total');

        $ranked = $rows->map(function ($row, $idx) use ($grandTotal) {
            return [
                'rank'                => $idx + 1,
                'supplier_id'         => $row->supplier_id,
                'name_en'             => $row->name_en,
                'name_ar'             => $row->name_ar,
                'phone'               => $row->phone,
                'total_purchases'     => round((float) $row->total_purchases, 2),
                'invoice_count'       => (int) $row->invoice_count,
                'avg_invoice'         => round((float) $row->avg_invoice, 2),
                'outstanding_balance' => round((float) $row->outstanding_balance, 2),
                'purchases_pct'       => $grandTotal > 0
                    ? round((float) $row->total_purchases / (float) $grandTotal * 100, 2)
                    : 0,
            ];
        });

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => [
                    'ranked'      => $ranked,
                    'grand_total' => round((float) $grandTotal, 2),
                    'total_count' => $rows->count(),
                ],
                'activeTab' => 'top-suppliers',
                'filters'   => $request->only(['date_from', 'date_to', 'limit', 'company_id']),
            ]
        ));
    }

    // ── TAB 5 — TOP SUPPLIERS BY INVENTORY ITEM ──────────────────
    // 📌 METHODOLOGY REFERENCE: TopSupplierByInventoryItem (#36)
    public function topSuppliersByItem(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'item_id'   => 'nullable|integer|exists:inventory_items,id',
        ]);

        $companyId = $this->resolveCompany($request);

        // Join: inventory_transactions (receipt) → inventory_items (master) → suppliers
        $query = DB::table('inventory_transactions as it')
            ->join('inventory_items as ii', 'ii.id', '=', 'it.item_id')
            ->join('suppliers as s', 's.id', '=', 'it.supplier_id')
            ->where('it.company_id', $companyId)
            ->where('it.type', 'receipt')
            ->whereNotNull('it.supplier_id')
            ->where('ii.is_master', true)
            ->whereBetween('it.date', [$request->date_from, $request->date_to]);

        if ($request->filled('item_id')) {
            $query->where('it.item_id', $request->item_id);
        }

        $rows = $query
            ->groupBy('it.item_id', 'ii.name_en', 'ii.name_ar', 'ii.unit', 'ii.category', 'it.supplier_id', 's.name_en', 's.name_ar')
            ->select([
                'it.item_id',
                'ii.name_en as item_name_en',
                'ii.name_ar as item_name_ar',
                'ii.unit',
                'ii.category',
                'it.supplier_id',
                DB::raw('s.name_en as supplier_name_en'),
                DB::raw('s.name_ar as supplier_name_ar'),
                DB::raw('SUM(it.total) as total_value'),
                DB::raw('SUM(it.qty) as total_qty'),
                DB::raw('SUM(it.total) / NULLIF(SUM(it.qty), 0) as avg_unit_cost'),
                DB::raw('COUNT(it.id) as receipt_count'),
            ])
            ->orderByDesc('total_value')
            ->get();

        // Group by item, rank suppliers within each item
        $byItem = $rows->groupBy('item_id')->map(function ($itemRows, $itemId) {
            $first     = $itemRows->first();
            $itemTotal = $itemRows->sum('total_value');
            $sorted    = $itemRows->sortByDesc('total_value')->values();

            return [
                'item_id'      => $itemId,
                'item_name_en' => $first->item_name_en,
                'item_name_ar' => $first->item_name_ar,
                'unit'         => $first->unit,
                'category'     => $first->category,
                'item_total'   => round((float) $itemTotal, 2),
                'suppliers'    => $sorted->map(function ($row, $idx) use ($itemTotal) {
                    return [
                        'rank'             => $idx + 1,
                        'supplier_id'      => $row->supplier_id,
                        'name_en'          => $row->supplier_name_en,
                        'name_ar'          => $row->supplier_name_ar,
                        'total_value'      => round((float) $row->total_value, 2),
                        'total_qty'        => round((float) $row->total_qty, 3),
                        'avg_unit_cost'    => round((float) $row->avg_unit_cost, 4),
                        'receipt_count'    => (int) $row->receipt_count,
                        'value_pct'        => $itemTotal > 0
                            ? round((float) $row->total_value / (float) $itemTotal * 100, 2)
                            : 0,
                    ];
                })->values(),
            ];
        })->values();

        // Available master items for filter dropdown
        $availableItems = DB::table('inventory_items')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'category', 'unit']);

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => [
                    'by_item'         => $byItem,
                    'available_items' => $availableItems,
                ],
                'activeTab' => 'top-suppliers-item',
                'filters'   => $request->only(['date_from', 'date_to', 'item_id', 'company_id']),
            ]
        ));
    }

    // ── TAB 6 — FEEDING DURATION ANALYSIS ────────────────────────
    // 📌 METHODOLOGY REFERENCE: FeedingDurationAnalysis (#37)
    public function feedingDuration(Request $request)
    {
        $this->authorise('view reports');

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'breed'     => 'nullable|string|max:100',
        ]);

        $companyId = $this->resolveCompany($request);

        // Buckets: 0–3 / 3–6 / 6–9 / 9–12 / 12+
        // duration_months = DATEDIFF(sr.date, a.purchase_date) / 30
        $query = DB::table('sales_records as sr')
            ->join('sales_lines as sl', 'sl.sale_id', '=', 'sr.id')
            ->join('animals as a', 'a.id', '=', 'sl.animal_id')
            ->where('sr.company_id', $companyId)
            ->whereIn('sr.status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('sr.date', [$request->date_from, $request->date_to])
            ->whereNotNull('sl.animal_id')
            ->whereNotNull('a.purchase_date')
            ->whereRaw('DATEDIFF(sr.date, a.purchase_date) >= 0'); // exclude data errors

        if ($request->filled('breed')) {
            $query->where('a.breed', $request->breed);
        }

        $rows = $query
            ->select([
                'sl.animal_id',
                'sl.total as sale_total',
                DB::raw('COALESCE(a.breed, "Unknown") as breed'),
                DB::raw('DATEDIFF(sr.date, a.purchase_date) as duration_days'),
                DB::raw('DATEDIFF(sr.date, a.purchase_date) / 30.0 as duration_months'),
                // Bucket assignment via CASE
                DB::raw("CASE
                    WHEN DATEDIFF(sr.date, a.purchase_date) / 30.0 < 3   THEN '0-3'
                    WHEN DATEDIFF(sr.date, a.purchase_date) / 30.0 < 6   THEN '3-6'
                    WHEN DATEDIFF(sr.date, a.purchase_date) / 30.0 < 9   THEN '6-9'
                    WHEN DATEDIFF(sr.date, a.purchase_date) / 30.0 < 12  THEN '9-12'
                    ELSE '12+'
                END as bucket"),
            ])
            ->get();

        // Count excluded animals (NULL purchase_date or negative duration)
        $excludedCount = DB::table('sales_records as sr')
            ->join('sales_lines as sl', 'sl.sale_id', '=', 'sr.id')
            ->join('animals as a', 'a.id', '=', 'sl.animal_id')
            ->where('sr.company_id', $companyId)
            ->whereIn('sr.status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('sr.date', [$request->date_from, $request->date_to])
            ->whereNotNull('sl.animal_id')
            ->where(function ($q) {
                $q->whereNull('a.purchase_date')
                  ->orWhereRaw('DATEDIFF(sr.date, a.purchase_date) < 0');
            })
            ->count();

        // Group by breed then bucket
        $bucketOrder = ['0-3', '3-6', '6-9', '9-12', '12+'];
        $bucketLabelsEn = ['0–3 Months', '3–6 Months', '6–9 Months', '9–12 Months', '12+ Months'];
        $bucketLabelsAr = ['٠-٣ أشهر', '٣-٦ أشهر', '٦-٩ أشهر', '٩-١٢ شهراً', '١٢+ شهراً'];

        $byBreed = $rows->groupBy('breed')->map(function ($breedRows, $breed) use ($bucketOrder, $bucketLabelsEn, $bucketLabelsAr) {
            $total    = $breedRows->count();
            $buckets  = $breedRows->groupBy('bucket');

            return [
                'breed'       => $breed,
                'total_count' => $total,
                'total_revenue' => round((float) $breedRows->sum('sale_total'), 2),
                'avg_duration_months' => $total > 0
                    ? round((float) $breedRows->avg('duration_months'), 1)
                    : null,
                'buckets' => collect($bucketOrder)->map(function ($b, $i) use ($buckets, $total, $bucketLabelsEn, $bucketLabelsAr) {
                    $inBucket = $buckets->get($b, collect());
                    $count    = $inBucket->count();
                    return [
                        'bucket'     => $b,
                        'label_en'   => $bucketLabelsEn[$i],
                        'label_ar'   => $bucketLabelsAr[$i],
                        'count'      => $count,
                        'pct'        => $total > 0 ? round($count / $total * 100, 1) : 0,
                        'revenue'    => round((float) $inBucket->sum('sale_total'), 2),
                        'avg_days'   => $count > 0 ? round((float) $inBucket->avg('duration_days'), 0) : null,
                    ];
                })->values(),
            ];
        })->values();

        // Grand total across all breeds
        $grandTotal  = $rows->count();
        $grandBuckets = $rows->groupBy('bucket');
        $grandSummary = collect($bucketOrder)->map(function ($b, $i) use ($grandBuckets, $grandTotal, $bucketLabelsEn, $bucketLabelsAr) {
            $inBucket = $grandBuckets->get($b, collect());
            $count    = $inBucket->count();
            return [
                'bucket'   => $b,
                'label_en' => $bucketLabelsEn[$i],
                'label_ar' => $bucketLabelsAr[$i],
                'count'    => $count,
                'pct'      => $grandTotal > 0 ? round($count / $grandTotal * 100, 1) : 0,
                'revenue'  => round((float) $inBucket->sum('sale_total'), 2),
            ];
        })->values();

        // Distinct breeds for dropdown
        $availableBreeds = DB::table('animals')
            ->where('company_id', $companyId)
            ->whereNotNull('breed')
            ->distinct()
            ->orderBy('breed')
            ->pluck('breed');

        return Inertia::render('Accounting/Analytics/Index', array_merge(
            $this->sharedProps($request, $companyId),
            [
                'report'    => [
                    'by_breed'        => $byBreed,
                    'grand_summary'   => $grandSummary,
                    'grand_total'     => $grandTotal,
                    'excluded_count'  => $excludedCount,
                    'available_breeds'=> $availableBreeds,
                ],
                'activeTab' => 'feeding-duration',
                'filters'   => $request->only(['date_from', 'date_to', 'breed', 'company_id']),
            ]
        ));
    }

    // ── Private Helpers ───────────────────────────────────────────

    private function authorise(string $permission): void
    {
        $user = Auth::user();
        abort_unless(
            $user->is_super_admin || $user->hasPermissionTo($permission),
            403
        );
    }

    private function resolveCompany(Request $request): int
    {
        $user = Auth::user();
        if ($user->is_super_admin) {
            $id = (int) $request->input('company_id');
            if ($id > 0) return $id;
            return (int) \App\Models\Company::value('id');
        }
        return (int) $user->company_id;
    }

    private function sharedProps(Request $request, int $companyId): array
    {
        $user = Auth::user();

        $companies   = $user->is_super_admin
            ? \App\Models\Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        $fiscalYears = FiscalYear::where('company_id', $companyId)
            ->orderByDesc('year')
            ->get()
            ->map(fn($fy) => [
                'id'         => $fy->id,
                'name'       => $fy->name ?? $fy->year,
                'year'       => $fy->year,
                'start_date' => $fy->start_date instanceof \Carbon\Carbon
                    ? $fy->start_date->format('Y-m-d')
                    : $fy->start_date,
                'end_date'   => $fy->end_date instanceof \Carbon\Carbon
                    ? $fy->end_date->format('Y-m-d')
                    : $fy->end_date,
                'status'     => $fy->status,
            ]);

        return [
            'companies'          => $companies,
            'fiscalYears'        => $fiscalYears,
            'selectedCompanyId'  => $companyId,
        ];
    }
}