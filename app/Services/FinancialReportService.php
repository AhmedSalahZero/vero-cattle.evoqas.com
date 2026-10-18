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
//  Only 'posted' and 'reversed' journal entries are included: a reversed
//  original stays in the ledger and its mirror entry cancels it. Drafts are excluded.
//
//  FIX (Session 65c — BalanceSheet parent rollup):
//    Group/header accounts (allow_direct_posting = false) now receive
//    a rolled-up balance = Σ of all descendant leaf balances.
//    Previously only posting accounts were included, so all parent
//    rows showed 0.00 in the Vue tree even when children had balances.
//    Section totals are now computed from top-level accounts only
//    (parent_id = null) to prevent double-counting.
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
            ->whereIn('je.status', ['posted', 'reversed'])
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
            ->whereIn('je.status', ['posted', 'reversed'])
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
                'opening'        => (float) \App\Support\Money::round($ob, 2),
                'period_debit'   => (float) \App\Support\Money::round($pd, 2),
                'period_credit'  => (float) \App\Support\Money::round($pc, 2),
                'closing'        => (float) \App\Support\Money::round($cb, 2),
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
                'opening'      => (float) \App\Support\Money::round($totalOb, 2),
                'period_debit' => (float) \App\Support\Money::round($totalDr, 2),
                'period_credit'=> (float) \App\Support\Money::round($totalCr, 2),
            ],
            'balanced'       => $balanced,
            'imbalance'      => (float) \App\Support\Money::round($totalCb, 2),
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
            ->whereIn('je.status', ['posted', 'reversed'])
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
                'amount'     => (float) \App\Support\Money::round($line->type === 'revenue'
                        ? bcsub($totalC, $totalD, 4)
                        : bcsub($totalD, $totalC, 4), 2),
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
            ? (float) \App\Support\Money::round(bcdiv(bcmul($grossProfit, '100', 6), $totalRevenue, 6), 2)
            : null;

        return [
            'revenue_lines'    => $revenue,
            'cogs_lines'       => $cogs,
            'expense_lines'    => $expenses,
            'total_revenue'    => (float) \App\Support\Money::round($totalRevenue, 2),
            'total_cogs'       => (float) \App\Support\Money::round($totalCogs, 2),
            'gross_profit'     => (float) \App\Support\Money::round($grossProfit, 2),
            'gross_margin_pct' => $grossMarginPct,
            'total_expenses'   => (float) \App\Support\Money::round($totalExpenses, 2),
            'net_income'       => (float) \App\Support\Money::round($netIncome, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // METHODOLOGY 3 — BALANCE SHEET
    // Assets = Liabilities + Equity
    // All balances as at $asOfDate (cumulative from the beginning)
    // Retained earnings = equity account balance + YTD net income
    //
    // FIX (Session 65c — v2): Rollup uses CODE-PREFIX matching, not
    // parent_id FK chain. User-created sub-accounts (e.g. 1110001)
    // may have parent_id = null or point incorrectly, so parent_id
    // cannot be trusted for tree traversal. Instead, a group header
    // account with code "1110" accumulates the balance of every
    // posting account whose code starts with "1110" — regardless of
    // what parent_id says in the database.
    //
    // Section totals are computed by summing the balances of the
    // top-level system group headers (shortest codes per type prefix)
    // to avoid double-counting.
    // ──────────────────────────────────────────────────────────────
    public function balanceSheet(
        int    $companyId,
        string $asOfDate,
        ?int   $fiscalYearId = null
    ): array {
        // Step 1 — Pull journal totals for all posting accounts up to asOfDate.
        // Only allow_direct_posting = true accounts ever have journal lines.
        // 📌 BcmathMonetaryPrecision — SUM() returns strings via PDO, keep as strings.
        $ledger = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereDate('je.date', '<=', $asOfDate)
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->groupBy('jl.account_id', 'a.normal_balance')
            ->select(
                'jl.account_id',
                'a.normal_balance',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get()
            ->keyBy('account_id');

        // Step 2 — Fetch ALL accounts for this company (posting + group headers).
        // Order by code length DESC then code ASC so children always appear
        // before parents when we iterate — ensures we accumulate leaf values
        // into intermediate nodes before intermediate nodes roll up further.
        $allAccounts = Account::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->orderByRaw('LENGTH(code) DESC')
            ->orderBy('code')
            ->get(['id', 'parent_id', 'code', 'name_en', 'name_ar', 'type', 'normal_balance', 'allow_direct_posting']);

        // Step 3 — Compute the signed leaf balance for every posting account.
        // Group headers start at '0' — their balance will be filled by the rollup.
        // 📌 BcmathMonetaryPrecision — store as bcmath strings at scale=4.
        $balanceMap = [];  // account_id => bcmath string

        foreach ($allAccounts as $acc) {
            $row    = $ledger->get($acc->id);
            $totalD = (string)($row->total_debit  ?? '0');
            $totalC = (string)($row->total_credit ?? '0');

            // Debit-normal (asset): balance = debit − credit
            // Credit-normal (liability, equity): balance = credit − debit
            $balanceMap[$acc->id] = ($acc->normal_balance === 'debit')
                ? bcsub($totalD, $totalC, 4)
                : bcsub($totalC, $totalD, 4);
        }

        // Step 4 — Code-prefix rollup.
        //
        // For every posting account (leaf), find all group header accounts
        // in the same type whose code is a strict prefix of the leaf's code.
        // Add the leaf's balance into each matching ancestor.
        //
        // Example: leaf code "1110001" matches headers "1110", "1100", "1000"
        // (if those exist), and each gets the leaf balance added.
        //
        // This approach is immune to missing or incorrect parent_id FK values,
        // which is the root cause of the previous fix not working.
        //
        // We iterate accounts ordered by code length DESC (longest first),
        // so leaves are processed before intermediate nodes. This means an
        // intermediate node's own balance (from its own journal entries, if any)
        // is already set when it becomes an "ancestor" for even-shorter codes.

        // Separate posting leaves from group headers for clarity
        $postingAccounts = $allAccounts->where('allow_direct_posting', true);
        $groupHeaders    = $allAccounts->where('allow_direct_posting', false);

        foreach ($postingAccounts as $leaf) {
            $leafCode    = $leaf->code;
            $leafBalance = $balanceMap[$leaf->id] ?? '0';

            // Skip leaves with zero balance — nothing to roll up
            if (bccomp($leafBalance, '0', 4) === 0) {
                continue;
            }

            foreach ($groupHeaders as $header) {
                // A header is an ancestor of this leaf if the leaf code
                // starts with the header's code AND the codes are different.
                if (
                    $header->type === $leaf->type &&
                    $header->code !== $leafCode &&
                    str_starts_with($leafCode, $header->code)
                ) {
                    $balanceMap[$header->id] = bcadd(
                        $balanceMap[$header->id] ?? '0',
                        $leafBalance,
                        4
                    );
                }
            }
        }

        // Step 5 — Build output arrays in TREE ORDER.
        //
        // Goal: each group header is immediately followed by its children,
        // recursively — 1100 → 1110 → 1110001 → 1130 → 1130002 → 1200 → …
        //
        // Algorithm: pure code-prefix tree ordering (same basis as Step 4 rollup).
        // No parent_id dependency — codes are always reliable.
        //
        // For each account type:
        //   1. Filter accounts to this type.
        //   2. Find root accounts = those with no shorter-code ancestor in the set.
        //   3. Recursively emit: node first, then direct children in code order,
        //      then their children, etc. "Direct child" = descendant with no
        //      intermediate node between it and the current node.
        //   4. Attach depth (0=root group, 1=sub-group, 2=leaf) for Vue indentation.

        $sorted = $allAccounts->sortBy('code')->values();

        // $emitSubtree: emit $node then all its descendants from $pool, depth-first.
        // $pool = the filtered collection for this account type (passed down).
        $emitSubtree = function ($node, int $depth, $pool) use (
            &$emitSubtree, $balanceMap
        ): array {
            $out     = [];
            $balance = (float) \App\Support\Money::round($balanceMap[$node->id] ?? '0', 2);

            // Always emit group headers; skip zero-balance posting leaves.
            if (!$node->allow_direct_posting || $balance != 0.0) {
                $out[] = [
                    'account_id'           => $node->id,
                    'parent_id'            => $node->parent_id,
                    'code'                 => $node->code,
                    'name_en'              => $node->name_en,
                    'name_ar'              => $node->name_ar,
                    'balance'              => $balance,
                    'allow_direct_posting' => (bool) $node->allow_direct_posting,
                    'depth'                => $depth,
                ];
            }

            $nodeCode    = $node->code;
            $nodeCodeLen = strlen($nodeCode);

            // All descendants: code starts with $nodeCode and is longer.
            $descendants = $pool->filter(fn($a) =>
                strlen($a->code) > $nodeCodeLen &&
                str_starts_with($a->code, $nodeCode)
            )->sortBy('code')->values();

            if ($descendants->isEmpty()) {
                return $out;
            }

            // Direct children of $node = descendants that have no intermediate
            // ancestor in $descendants between themselves and $node.
            // i.e. no other descendant's code is a strict prefix of this account's code.
            foreach ($descendants as $desc) {
                $isDirectChild = true;
                foreach ($descendants as $other) {
                    if ($other->id === $desc->id)                    continue;
                    if (strlen($other->code) >= strlen($desc->code)) continue;
                    if (str_starts_with($desc->code, $other->code)) {
                        $isDirectChild = false;
                        break;
                    }
                }
                if ($isDirectChild) {
                    $out = array_merge($out, $emitSubtree($desc, $depth + 1, $descendants));
                }
            }

            return $out;
        };

        $assets      = [];
        $liabilities = [];
        $equity      = [];

        foreach (['asset', 'liability', 'equity'] as $type) {
            $typeAccounts = $sorted->filter(fn($a) => $a->type === $type)->values();

            // Root accounts = those with no shorter-code ancestor in this type's set.
            $roots = $typeAccounts->filter(function ($acc) use ($typeAccounts) {
                foreach ($typeAccounts as $other) {
                    if ($other->id === $acc->id)                    continue;
                    if (strlen($other->code) >= strlen($acc->code)) continue;
                    if (str_starts_with($acc->code, $other->code))  return false;
                }
                return true;
            })->sortBy('code')->values();

            $section = [];
            foreach ($roots as $root) {
                $section = array_merge($section, $emitSubtree($root, 0, $typeAccounts));
            }

            match ($type) {
                'asset'     => $assets      = $section,
                'liability' => $liabilities = $section,
                'equity'    => $equity      = $section,
            };
        }

        // Step 6 — Inject earnings as synthetic equity lines.
        //   RE_PRIOR : profit/loss of every period BEFORE the current fiscal year
        //              that has not been closed into an equity account yet.
        //              (Without it, last year's profit is missing from equity and
        //               the balance sheet is out of balance by exactly that amount.)
        //   RE_YTD   : profit/loss of the current fiscal year to the as-of date.
        // 📌 METHODOLOGY REFERENCE: BalanceSheet (#7) — Assets = Liabilities + Equity
        $fyStart       = $this->fiscalYearStart($companyId, $asOfDate, $fiscalYearId);
        $priorTo       = $fyStart
            ? \Illuminate\Support\Carbon::parse($fyStart)->subDay()->toDateString()
            : $asOfDate;
        $priorIncome   = $this->fetchPLData($companyId, '1900-01-01', $priorTo)['net_income'];
        if ($priorIncome != 0) {
            $equity[] = [
                'account_id'           => null,
                'parent_id'            => null,
                'code'                 => 'RE_PRIOR',
                'name_en'              => __('Retained Earnings — Prior Years'),
                'name_ar'              => __('أرباح مرحّلة — سنوات سابقة'),
                'balance'              => $priorIncome,
                'allow_direct_posting' => false,
            ];
        }

        if ($fyStart) {
            $plData    = $this->fetchPLData($companyId, $fyStart, $asOfDate);
            $netIncome = $plData['net_income'];
            if ($netIncome != 0) {
                $equity[] = [
                    'account_id'           => null,
                    'parent_id'            => null,
                    'code'                 => 'RE_YTD',
                    'name_en'              => __('Net Income — Current Year'),
                    'name_ar'              => __('صافي الربح — السنة الحالية'),
                    'balance'              => $netIncome,
                    'allow_direct_posting' => false,
                ];
            }
        }

        // Step 7 — Section totals.
        // Sum the balances of the TOP-LEVEL group headers only (the shortest
        // code per section prefix: "1000" for assets, "2000" for liabilities,
        // "3000" for equity). If no single root header exists, fall back to
        // summing all posting leaves directly to avoid double-counting.
        //
        // Double-counting explanation: if we sum every row, a leaf "1110001"
        // and its header "1110" both appear in $assets — adding them would
        // count the leaf's value twice. We must sum only the outermost level.
        //
        // Strategy: sum posting leaves only (they are the atomic units).
        // The rolled-up header values are for display only — not for totals.
        // 📌 BcmathMonetaryPrecision
        $totalAssets      = '0';
        $totalLiabilities = '0';
        $totalEquity      = '0';

        foreach ($postingAccounts as $leaf) {
            $leafBalance = $balanceMap[$leaf->id] ?? '0';
            match ($leaf->type) {
                'asset'     => $totalAssets      = bcadd($totalAssets,      $leafBalance, 4),
                'liability' => $totalLiabilities = bcadd($totalLiabilities, $leafBalance, 4),
                'equity'    => $totalEquity      = bcadd($totalEquity,      $leafBalance, 4),
                default     => null,
            };
        }

        // Also include prior-year and current-year earnings in the equity total
        $totalEquity = bcadd($totalEquity, (string) $priorIncome, 4);
        if (isset($netIncome)) {
            $totalEquity = bcadd($totalEquity, (string)$netIncome, 4);
        }

        // Balance sheet assertion: assets = liabilities + equity (±0.01)
        $liabPlusEquity = bcadd($totalLiabilities, $totalEquity, 4);
        $imbalanceStr   = bcsub($totalAssets, $liabPlusEquity, 4);
        $absImbalance   = ltrim($imbalanceStr, '-');
        $balanced       = bccomp($absImbalance, '0.0100', 4) <= 0;

        return [
            'as_of_date'        => $asOfDate,
            'assets'            => array_values($assets),
            'liabilities'       => array_values($liabilities),
            'equity'            => array_values($equity),
            'total_assets'      => (float) \App\Support\Money::round($totalAssets, 2),
            'total_liabilities' => (float) \App\Support\Money::round($totalLiabilities, 2),
            'total_equity'      => (float) \App\Support\Money::round($totalEquity, 2),
            'balanced'          => $balanced,
            'imbalance'         => (float) \App\Support\Money::round($imbalanceStr, 2),
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
                ->whereIn('je.status', ['posted', 'reversed'])
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
            ->whereIn('je.status', ['posted', 'reversed'])
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
                'net_income'      => (float) \App\Support\Money::round($netIncome, 2),
                'depreciation'    => (float) \App\Support\Money::round($depreciation, 2),
                'delta_ar'        => (float) \App\Support\Money::round($deltaAr, 2),
                'delta_ap'        => (float) \App\Support\Money::round($deltaAp, 2),
                'delta_inventory' => (float) \App\Support\Money::round($deltaInv, 2),
                'total'           => (float) \App\Support\Money::round($operatingCf, 2),
            ],
            'investing' => [
                'fixed_asset_movement' => (float) \App\Support\Money::round($investingCf, 2),
                'total'                => (float) \App\Support\Money::round($investingCf, 2),
            ],
            'financing' => [
                'loan_equity_movement' => (float) \App\Support\Money::round($financingCf, 2),
                'total'                => (float) \App\Support\Money::round($financingCf, 2),
            ],
            'net_change'   => (float) \App\Support\Money::round($netChange, 2),
            'opening_cash' => (float) \App\Support\Money::round($openingCash, 2),
            'closing_cash' => (float) \App\Support\Money::round($closingCash, 2),
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