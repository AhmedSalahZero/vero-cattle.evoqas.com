<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Financial Report Service
//
//  Implements four core financial statements from the journal ledger.
//  All methods are pure-read — no writes occur here.
//
//  Methodology references (see chat METHODOLOGY_REFERENCE):
//    TrialBalance        — Methodology 1
//    ProfitAndLoss       — Methodology 2
//    BalanceSheet        — Methodology 3
//    CashFlow            — Methodology 4
//
//  All amounts in company currency (EGP by default).
//  Only status = 'posted' journal entries are included.
// ══════════════════════════════════════════════════════════════════

class FinancialReportService
{
    // ──────────────────────────────────────────────────────────────
    // METHODOLOGY 1 — TRIAL BALANCE
    // Opening + Σdebit − Σcredit = Closing  (debit-normal accounts)
    // Opening − Σdebit + Σcredit = Closing  (credit-normal accounts)
    // ──────────────────────────────────────────────────────────────
    public function trialBalance(
        int    $companyId,
        string $periodStart,   // Y-m-d
        string $periodEnd      // Y-m-d
    ): array {
        // Step 1 — Fetch all active, direct-posting accounts for this company
        $accounts = Account::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->orderBy('code')
            ->get();

        // Step 2 — Pull opening balances (all posted lines BEFORE period_start)
        $opening = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereDate('je.date', '<', $periodStart)
            ->groupBy('jl.account_id')
            ->select('jl.account_id',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit'))
            ->get()
            ->keyBy('account_id');

        // Step 3 — Pull period movements (posted lines WITHIN the period)
        $period = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereBetween('je.date', [$periodStart, $periodEnd])
            ->groupBy('jl.account_id')
            ->select('jl.account_id',
                DB::raw('SUM(jl.debit)  as period_debit'),
                DB::raw('SUM(jl.credit) as period_credit'))
            ->get()
            ->keyBy('account_id');

        $rows = [];
        // 📌 BcmathMonetaryPrecision — accumulate totals as bcmath strings, never PHP float
        $totalOb = '0';
        $totalDr = '0';
        $totalCr = '0';
        $totalCb = '0';

        foreach ($accounts as $account) {
            // MySQL SUM() returns strings via PDO — cast to string, not float, before bcmath
            $ob_d = (string)($opening[$account->id]->total_debit  ?? '0');
            $ob_c = (string)($opening[$account->id]->total_credit ?? '0');
            $pd   = (string)($period[$account->id]->period_debit  ?? '0');
            $pc   = (string)($period[$account->id]->period_credit ?? '0');

            // Opening balance: positive = normal-side balance
            // Debit-normal: ob = debit − credit
            // Credit-normal: ob = credit − debit
            // 📌 BcmathMonetaryPrecision — use bcsub at scale=4
            $ob = ($account->normal_balance === 'debit')
                ? bcsub($ob_d, $ob_c, 4)
                : bcsub($ob_c, $ob_d, 4);

            // Closing = opening + net period movement (signed same way)
            $net = ($account->normal_balance === 'debit')
                ? bcsub($pd, $pc, 4)
                : bcsub($pc, $pd, 4);

            $cb = bcadd($ob, $net, 4);

            // Skip fully-zero rows (nothing ever posted)
            if (bccomp($ob, '0', 4) === 0 && bccomp($pd, '0', 4) === 0 && bccomp($pc, '0', 4) === 0) {
                continue;
            }

            $rows[] = [
                'account_id'     => $account->id,
                'code'           => $account->code,
                'name_en'        => $account->name_en,
                'name_ar'        => $account->name_ar,
                'type'           => $account->type,
                'normal_balance' => $account->normal_balance,
                'opening'        => (float) bcadd($ob, '0', 2),
                'period_debit'   => (float) bcadd($pd, '0', 2),
                'period_credit'  => (float) bcadd($pc, '0', 2),
                'closing'        => (float) bcadd($cb, '0', 2),
            ];

            $totalOb = bcadd($totalOb, $ob, 4);
            $totalDr = bcadd($totalDr, $pd, 4);
            $totalCr = bcadd($totalCr, $pc, 4);
            // Net check value: debit-normal accounts add closing, credit-normal subtract
            $totalCb = ($account->normal_balance === 'debit')
                ? bcadd($totalCb, $cb, 4)
                : bcsub($totalCb, $cb, 4);
        }

        // Assertion: balanced ledger check (tolerance 0.01)
        $absCb   = ltrim($totalCb, '-');
        $balanced = bccomp($absCb, '0.01', 4) <= 0;

        return [
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'rows'           => $rows,
            'totals'         => [
                'opening'      => (float) bcadd($totalOb, '0', 2),
                'period_debit' => (float) bcadd($totalDr, '0', 2),
                'period_credit'=> (float) bcadd($totalCr, '0', 2),
            ],
            'balanced'       => $balanced,
            'imbalance'      => (float) bcadd($totalCb, '0', 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // METHODOLOGY 2 — PROFIT & LOSS
    // Revenue (credit-normal) − COGS (debit-normal) = Gross Profit
    // Gross Profit − Expenses (debit-normal) = Net Income
    // Gross Margin % = Gross Profit / Revenue × 100
    // ──────────────────────────────────────────────────────────────
    public function profitAndLoss(
        int    $companyId,
        string $dateFrom,
        string $dateTo,
        ?string $compareDateFrom = null,   // optional prior-period
        ?string $compareDateTo   = null
    ): array {
        $current  = $this->fetchPLData($companyId, $dateFrom, $dateTo);
        $compare  = ($compareDateFrom && $compareDateTo)
            ? $this->fetchPLData($companyId, $compareDateFrom, $compareDateTo)
            : null;

        return [
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'current'     => $current,
            'compare'     => $compare,
            'compare_from'=> $compareDateFrom,
            'compare_to'  => $compareDateTo,
        ];
    }

    private function fetchPLData(int $companyId, string $from, string $to): array
    {
        // Fetch account-level movements for P&L account types
        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereBetween('je.date', [$from, $to])
            ->whereIn('a.type', ['revenue', 'cogs', 'expense'])
            ->groupBy('jl.account_id', 'a.code', 'a.name_en', 'a.name_ar', 'a.type', 'a.normal_balance')
            ->select(
                'jl.account_id',
                'a.code',
                'a.name_en',
                'a.name_ar',
                'a.type',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get();

        $revenue  = [];
        $cogs     = [];
        $expenses = [];

        foreach ($lines as $line) {
            // 📌 BcmathMonetaryPrecision — MySQL SUM() returns strings, use bcsub not float subtraction
            $totalD = (string)($line->total_debit  ?? '0');
            $totalC = (string)($line->total_credit ?? '0');

            $entry = [
                'account_id' => $line->account_id,
                'code'       => $line->code,
                'name_en'    => $line->name_en,
                'name_ar'    => $line->name_ar,
                // For revenue (credit-normal): amount = credit − debit
                // For cogs/expense (debit-normal): amount = debit − credit
                'amount'     => (float) bcadd(
                    $line->type === 'revenue'
                        ? bcsub($totalC, $totalD, 4)
                        : bcsub($totalD, $totalC, 4),
                    '0', 2
                ),
            ];

            match ($line->type) {
                'revenue' => $revenue[]  = $entry,
                'cogs'    => $cogs[]     = $entry,
                'expense' => $expenses[] = $entry,
                default   => null,
            };
        }

        // 📌 BcmathMonetaryPrecision — accumulate section totals with bcadd
        $totalRevenue  = '0';
        $totalCogs     = '0';
        $totalExpenses = '0';
        foreach ($revenue  as $r) { $totalRevenue  = bcadd($totalRevenue,  (string)$r['amount'], 4); }
        foreach ($cogs     as $c) { $totalCogs     = bcadd($totalCogs,     (string)$c['amount'], 4); }
        foreach ($expenses as $e) { $totalExpenses = bcadd($totalExpenses, (string)$e['amount'], 4); }

        $grossProfit = bcsub($totalRevenue, $totalCogs,     4);
        $netIncome   = bcsub($grossProfit,  $totalExpenses, 4);

        // Gross margin % — null-safe (avoid division by zero)
        $grossMarginPct = (bccomp($totalRevenue, '0', 4) > 0)
            ? (float) bcadd(bcdiv(bcmul($grossProfit, '100', 6), $totalRevenue, 6), '0', 2)
            : null;

        return [
            'revenue_lines'    => $revenue,
            'cogs_lines'       => $cogs,
            'expense_lines'    => $expenses,
            'total_revenue'    => (float) bcadd($totalRevenue,  '0', 2),
            'total_cogs'       => (float) bcadd($totalCogs,     '0', 2),
            'gross_profit'     => (float) bcadd($grossProfit,   '0', 2),
            'gross_margin_pct' => $grossMarginPct,
            'total_expenses'   => (float) bcadd($totalExpenses, '0', 2),
            'net_income'       => (float) bcadd($netIncome,     '0', 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // METHODOLOGY 3 — BALANCE SHEET
    // Assets = Liabilities + Equity
    // All balances as at $asOfDate (cumulative from the beginning)
    // Retained earnings = equity account balance + YTD net income
    // ──────────────────────────────────────────────────────────────
    public function balanceSheet(
        int    $companyId,
        string $asOfDate,
        ?int   $fiscalYearId = null
    ): array {
        // All posted lines up to and including asOfDate
        $balances = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->whereDate('je.date', '<=', $asOfDate)
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->groupBy('jl.account_id', 'a.code', 'a.name_en', 'a.name_ar', 'a.type', 'a.normal_balance')
            ->select(
                'jl.account_id',
                'a.code',
                'a.name_en',
                'a.name_ar',
                'a.type',
                'a.normal_balance',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get();

        $assets      = [];
        $liabilities = [];
        $equity      = [];

        foreach ($balances as $row) {
            // Asset (debit-normal): balance = debit − credit
            // Liability/Equity (credit-normal): balance = credit − debit
            // 📌 BcmathMonetaryPrecision — MySQL SUM() strings, use bcsub
            $totalD = (string)($row->total_debit  ?? '0');
            $totalC = (string)($row->total_credit ?? '0');
            $balance = (float) bcadd(
                ($row->normal_balance === 'debit')
                    ? bcsub($totalD, $totalC, 4)
                    : bcsub($totalC, $totalD, 4),
                '0', 2
            );

            $entry = [
                'account_id' => $row->account_id,
                'code'       => $row->code,
                'name_en'    => $row->name_en,
                'name_ar'    => $row->name_ar,
                'balance'    => $balance,
            ];

            match ($row->type) {
                'asset'     => $assets[]      = $entry,
                'liability' => $liabilities[] = $entry,
                'equity'    => $equity[]      = $entry,
                default     => null,
            };
        }

        // Add YTD net income to retained earnings
        // Find fiscal year start for this period
        $fyStart = $this->fiscalYearStart($companyId, $asOfDate, $fiscalYearId);

        if ($fyStart) {
            $plData     = $this->fetchPLData($companyId, $fyStart, $asOfDate);
            $netIncome  = $plData['net_income'];

            // Inject as a synthetic "Net Income (Current Year)" equity line
            if ($netIncome != 0) {
                $equity[] = [
                    'account_id' => null,
                    'code'       => 'RE_YTD',
                    'name_en'    => __('Net Income — Current Year'),
                    'name_ar'    => __('صافي الربح — السنة الحالية'),
                    'balance'    => $netIncome,
                ];
            }
        }

        // 📌 BcmathMonetaryPrecision — section totals accumulated with bcadd
        $totalAssets      = '0';
        $totalLiabilities = '0';
        $totalEquity      = '0';
        foreach ($assets      as $a) { $totalAssets      = bcadd($totalAssets,      (string)$a['balance'], 4); }
        foreach ($liabilities as $l) { $totalLiabilities = bcadd($totalLiabilities, (string)$l['balance'], 4); }
        foreach ($equity      as $e) { $totalEquity      = bcadd($totalEquity,      (string)$e['balance'], 4); }

        // Balance sheet assertion: assets = liabilities + equity (±0.01)
        $liabPlusEquity = bcadd($totalLiabilities, $totalEquity, 4);
        $imbalanceStr   = bcsub($totalAssets, $liabPlusEquity, 4);
        $absImbalance   = ltrim($imbalanceStr, '-');
        $balanced       = bccomp($absImbalance, '0.0100', 4) <= 0;

        return [
            'as_of_date'        => $asOfDate,
            'assets'            => $assets,
            'liabilities'       => $liabilities,
            'equity'            => $equity,
            'total_assets'      => (float) bcadd($totalAssets,      '0', 2),
            'total_liabilities' => (float) bcadd($totalLiabilities, '0', 2),
            'total_equity'      => (float) bcadd($totalEquity,      '0', 2),
            'balanced'          => $balanced,
            'imbalance'         => (float) bcadd($imbalanceStr,     '0', 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // METHODOLOGY 4 — CASH FLOW (INDIRECT METHOD)
    // Operating CF = Net Income + Non-cash + ΔWorking Capital
    // Investing CF = Net movement in fixed-asset accounts
    // Financing CF = Net movement in loan/equity (excl. retained earnings)
    // Net Change   = Operating + Investing + Financing
    // Closing Cash = Opening Cash + Net Change
    // ──────────────────────────────────────────────────────────────
    public function cashFlow(
        int    $companyId,
        string $dateFrom,
        string $dateTo
    ): array {
        // Net income from P&L — fetchPLData now returns float, convert to string for bcmath
        $pl        = $this->fetchPLData($companyId, $dateFrom, $dateTo);
        $netIncome = (string)$pl['net_income'];

        // Opening and closing balances for working capital accounts
        $dayBefore = Carbon::parse($dateFrom)->subDay()->toDateString();

        // 📌 BcmathMonetaryPrecision — $balance helper returns bcmath string (not float).
        // MySQL computed expressions (SUM(debit) - SUM(credit)) are returned as strings by PDO.
        // Capture as-is; never cast to float until final output.
        $balance = function (string $prefix, string $type, string $date) use ($companyId): string {
            $raw = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('je.company_id', $companyId)
                ->where('je.status', 'posted')
                ->whereDate('je.date', '<=', $date)
                ->where('a.type', $type)
                ->where('a.code', 'like', $prefix . '%')
                ->select(DB::raw('SUM(jl.debit) - SUM(jl.credit) as net'))
                ->value('net');
            return (string)($raw ?? '0');
        };

        // abs() for bcmath strings: strip leading minus sign
        $bcabs = fn(string $v): string => ltrim($v, '-');

        // Depreciation: debit to expense from fixed_asset source_type entries
        // MySQL SUM() returns a string — keep as string for bcmath
        $depreciation = (string)(DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', 'posted')
            ->where('je.source_type', 'fixed_asset')
            ->whereBetween('je.date', [$dateFrom, $dateTo])
            ->where('a.type', 'expense')
            ->sum('jl.debit') ?? '0');

        // ΔReceivables: increase in AR = use of cash (subtract)
        // AR accounts: type=asset, code prefix '12' (1600-series per CoA)
        $arOpen  = $balance('16', 'asset', $dayBefore);
        $arClose = $balance('16', 'asset', $dateTo);
        $deltaAr = bcsub($arOpen, $arClose, 4);  // increase = negative (used cash)

        // ΔPayables: increase in AP = source of cash (add)
        // AP accounts: type=liability, code prefix '22'
        $apOpen  = $bcabs($balance('22', 'liability', $dayBefore));
        $apClose = $bcabs($balance('22', 'liability', $dateTo));
        $deltaAp = bcsub($apClose, $apOpen, 4);  // increase = positive (freed cash)

        // ΔInventory: increase = use of cash
        // Inventory accounts: type=asset, code prefix '13'
        $invOpen  = $balance('13', 'asset', $dayBefore);
        $invClose = $balance('13', 'asset', $dateTo);
        $deltaInv = bcsub($invOpen, $invClose, 4);

        // Operating CF = net income + depreciation + Δworking capital
        $operatingCf = bcadd(
            bcadd(bcadd(bcadd($netIncome, $depreciation, 4), $deltaAr, 4), $deltaAp, 4),
            $deltaInv, 4
        );

        // Investing CF: net movement in fixed asset accounts (type=asset, prefix '11')
        $faOpen      = $balance('11', 'asset', $dayBefore);
        $faClose     = $balance('11', 'asset', $dateTo);
        $investingCf = bcsub($faOpen, $faClose, 4);  // increase in FA = use of cash

        // Financing CF: net movement in loan/equity accounts
        // Long-term liabilities prefix '21', equity prefix '31' (excl. retained earnings)
        $loanOpen    = $bcabs($balance('21', 'liability', $dayBefore));
        $loanClose   = $bcabs($balance('21', 'liability', $dateTo));
        $equOpen     = $bcabs($balance('31', 'equity', $dayBefore));
        $equClose    = $bcabs($balance('31', 'equity', $dateTo));
        $financingCf = bcadd(
            bcsub($loanClose, $loanOpen, 4),
            bcsub($equClose, $equOpen, 4),
            4
        );

        $netChange = bcadd(bcadd($operatingCf, $investingCf, 4), $financingCf, 4);

        // Opening and closing cash: safes (14xx) + bank accounts (15xx)
        $openingCash = bcadd($balance('14', 'asset', $dayBefore), $balance('15', 'asset', $dayBefore), 4);
        $closingCash = bcadd($openingCash, $netChange, 4);

        return [
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'operating' => [
                'net_income'      => (float) bcadd($netIncome,    '0', 2),
                'depreciation'    => (float) bcadd($depreciation, '0', 2),
                'delta_ar'        => (float) bcadd($deltaAr,      '0', 2),
                'delta_ap'        => (float) bcadd($deltaAp,      '0', 2),
                'delta_inventory' => (float) bcadd($deltaInv,     '0', 2),
                'total'           => (float) bcadd($operatingCf,  '0', 2),
            ],
            'investing' => [
                'fixed_asset_movement' => (float) bcadd($investingCf, '0', 2),
                'total'                => (float) bcadd($investingCf, '0', 2),
            ],
            'financing' => [
                'loan_equity_movement' => (float) bcadd($financingCf, '0', 2),
                'total'                => (float) bcadd($financingCf, '0', 2),
            ],
            'net_change'   => (float) bcadd($netChange,   '0', 2),
            'opening_cash' => (float) bcadd($openingCash, '0', 2),
            'closing_cash' => (float) bcadd($closingCash, '0', 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // HELPER — resolve fiscal year start date
    // ──────────────────────────────────────────────────────────────
    private function fiscalYearStart(int $companyId, string $date, ?int $fyId = null): ?string
    {
        $query = FiscalYear::where('company_id', $companyId);

        if ($fyId) {
            $fy = $query->find($fyId);
        } else {
            $fy = $query
                ->where('start_date', '<=', $date)
                ->where('end_date',   '>=', $date)
                ->first();
        }

        return $fy?->start_date?->toDateString();
    }
}