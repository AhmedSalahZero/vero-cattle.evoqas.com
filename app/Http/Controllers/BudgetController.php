<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Budget Controller
//
//  📌 METHODOLOGY REFERENCE: BudgetVariancePercent
//  variance_absolute = actual − budget
//  variance_percent  = (actual − budget) / budget × 100
//  flag = 'favourable' | 'unfavourable' based on account type:
//    revenue accounts  → higher actual = favourable
//    expense/cogs      → lower  actual = favourable
//  See BudgetVariancePercent in session methodology notes.
//
//  Budget is entered per account per month (1–12) per fiscal year.
//  One row per (company, fiscal_year, account, month).
//  Bulk-upsert pattern: the Vue grid submits all 12 months at once
//  for a given account, handled by bulkUpsert().
//  Actuals are pulled from posted journal lines grouped by
//  account + month within the fiscal year date range.
// ══════════════════════════════════════════════════════════════════

class BudgetController extends Controller
{
    // ── Index — budget grid with variance ────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view budgets'),
            403
        );

        // Super Admin: resolve company_id from request or fall back to first company
        $companyId = $authUser->is_super_admin
            ? (int) ($request->input('company_id') ?: Company::value('id'))
            : $authUser->company_id;
        $fiscalYearId = $request->input('fiscal_year_id');

        // Default to the currently open fiscal year
        if (!$fiscalYearId) {
            $fy = FiscalYear::where('company_id', $companyId)
                ->where('status', 'open')
                ->orderByDesc('year')
                ->first();
            $fiscalYearId = $fy?->id;
        }

        $fiscalYear   = $fiscalYearId ? FiscalYear::find($fiscalYearId) : null;
        $fiscalYears  = FiscalYear::where('company_id', $companyId)->orderByDesc('year')->get(['id','year','name','status']);

        // Budget rows for this year
        $budgetRows = $fiscalYearId
            ? Budget::where('company_id', $companyId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->get()
            : collect();

        // Actuals: SUM of posted journal lines per account per period month
        $actuals = $fiscalYearId
            ? $this->fetchActuals($companyId, $fiscalYearId, $fiscalYear)
            : collect();

        // Accounts eligible for budget (income statement accounts)
        $accounts = Account::where('company_id', $companyId)
            ->whereIn('type', ['revenue', 'cogs', 'expense'])
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);

        // Build the grid: accounts × 12 months with budget + actual + variance
        $grid = $this->buildGrid($accounts, $budgetRows, $actuals, $fiscalYear);

        // 📌 METHODOLOGY REFERENCE: BudgetPnlSummaryTotals (#44)
        // Revenue accounts are credit-normal; COGS/expense are debit-normal.
        // Summing them into one undifferentiated total produces a meaningless
        // figure. We partition into revenue vs cost buckets and compute
        // Budgeted Net Income = Revenue Budget − Cost Budget so the Vue
        // KPI strip can display all three lines correctly.
        $revenueBudget = $grid->where('account_type', 'revenue')->sum('total_budget');
        $revenueActual = $grid->where('account_type', 'revenue')->sum('total_actual');

        $costBudget    = $grid->whereIn('account_type', ['cogs', 'expense'])->sum('total_budget');
        $costActual    = $grid->whereIn('account_type', ['cogs', 'expense'])->sum('total_actual');

        $netBudget     = round($revenueBudget - $costBudget, 2);
        $netActual     = round($revenueActual - $costActual, 2);
        $netVariance   = round($netActual - $netBudget, 2);
        $netFlag       = $netVariance === 0.0 ? 'neutral'
                       : ($netVariance > 0    ? 'favourable' : 'unfavourable');

        return Inertia::render('Accounting/Budgets/Index', [
            'grid'              => $grid,
            'fiscalYear'        => $fiscalYear?->only(['id','year','name','start_date','end_date','status']),
            'fiscalYears'       => $fiscalYears,
            // ── P&L summary buckets (replace old meaningless totalBudget/totalActual) ──
            'revenueBudget'     => round($revenueBudget, 2),
            'revenueActual'     => round($revenueActual, 2),
            'costBudget'        => round($costBudget, 2),
            'costActual'        => round($costActual, 2),
            'netBudget'         => $netBudget,
            'netActual'         => $netActual,
            'netVariance'       => $netVariance,
            'netFlag'           => $netFlag,
            // ── kept for backward compat if Vue still reads them — both are now
            //    meaningful: revenue budget total and cost budget total respectively ──
            'totalBudget'       => round($revenueBudget, 2),
            'totalActual'       => round($revenueActual, 2),
            'filters'           => ['fiscal_year_id' => $fiscalYearId],
            'companies'         => $authUser->is_super_admin
                ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                : collect(),
            'selectedCompanyId' => $companyId,
        ]);
    }

    // ── Bulk upsert — save all 12 months for one account ─────────
    public function bulkUpsert(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create budgets'),
            403
        );

        // Super Admin: resolve company_id from request
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id')
            : $authUser->company_id;

        abort_unless((int) $companyId > 0, 422, 'A company must be selected.');

        $validated = $request->validate([
            'fiscal_year_id'   => 'required|integer|exists:fiscal_years,id',
            'account_id'       => 'required|integer|exists:accounts,id',
            'months'           => 'required|array|size:12',
            'months.*.month'   => 'required|integer|min:1|max:12',
            'months.*.amount'  => 'required|numeric|min:0',
            'months.*.notes'   => 'nullable|string|max:255',
        ]);

        // Guard: fiscal year must be open
        $fy = FiscalYear::findOrFail($validated['fiscal_year_id']);
        abort_unless($fy->company_id === $companyId, 403);
        abort_unless($fy->status === 'open', 422, __('messages.fiscal_year_not_open'));

        // Guard: account must belong to same company
        $account = Account::findOrFail($validated['account_id']);
        abort_unless($account->company_id === $companyId, 403);

        DB::transaction(function () use ($validated, $companyId) {
            foreach ($validated['months'] as $m) {
                Budget::updateOrCreate(
                    [
                        'company_id'     => $companyId,
                        'fiscal_year_id' => $validated['fiscal_year_id'],
                        'account_id'     => $validated['account_id'],
                        'month'          => $m['month'],
                    ],
                    [
                        'amount' => $m['amount'],
                        'notes'  => $m['notes'] ?? null,
                    ]
                );
            }
        });

        return back()->with('success', __('messages.budget_saved'));
    }

    // ── Copy prior year actuals as new year budget ────────────────
    public function copyFromActuals(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create budgets'),
            403
        );

        // Super Admin: resolve company_id from request
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id')
            : $authUser->company_id;

        abort_unless((int) $companyId > 0, 422, 'A company must be selected.');

        $validated = $request->validate([
            'source_fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'target_fiscal_year_id' => 'required|integer|exists:fiscal_years,id|different:source_fiscal_year_id',
            'growth_pct'            => 'nullable|numeric|min:-100|max:1000', // optional growth adjustment
        ]);

        $targetFy = FiscalYear::findOrFail($validated['target_fiscal_year_id']);
        $sourceFy = FiscalYear::findOrFail($validated['source_fiscal_year_id']);

        abort_unless($targetFy->company_id === $companyId && $sourceFy->company_id === $companyId, 403);
        abort_unless($targetFy->status === 'open', 422, __('messages.fiscal_year_not_open'));

        // Growth multiplier: 1.0 = no growth, 1.10 = 10% growth
        $multiplier = 1 + (($validated['growth_pct'] ?? 0) / 100);

        // Fetch prior year actuals (posted journal lines) grouped by account + month
        $actuals = $this->fetchActuals($companyId, $validated['source_fiscal_year_id'], $sourceFy);

        DB::transaction(function () use ($actuals, $validated, $companyId, $multiplier) {
            foreach ($actuals as $key => $amount) {
                // Key format: "{account_id}-{month}"
                [$accountId, $month] = explode('-', $key);

                Budget::updateOrCreate(
                    [
                        'company_id'     => $companyId,
                        'fiscal_year_id' => $validated['target_fiscal_year_id'],
                        'account_id'     => (int) $accountId,
                        'month'          => (int) $month,
                    ],
                    [
                        // 📌 BudgetVariancePercent — growth applied to prior actuals
                        'amount' => round($amount * $multiplier, 2),
                        'notes'  => 'Copied from prior year actuals',
                    ]
                );
            }
        });

        return back()->with('success', __('messages.budget_copied'));
    }

    // ── Destroy — remove all budget rows for one account/year ─────
    public function destroyByAccount(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete budgets'),
            403
        );

        // Super Admin: resolve company_id from request
        $companyId = $authUser->is_super_admin
            ? (int) $request->input('company_id')
            : $authUser->company_id;

        abort_unless((int) $companyId > 0, 422, 'A company must be selected.');

        $validated = $request->validate([
            'fiscal_year_id' => 'required|integer|exists:fiscal_years,id',
            'account_id'     => 'required|integer|exists:accounts,id',
        ]);

        $fy = FiscalYear::findOrFail($validated['fiscal_year_id']);
        abort_unless($fy->company_id === $companyId, 403);
        abort_unless($fy->status === 'open', 422, __('messages.fiscal_year_not_open'));

        Budget::where('company_id', $companyId)
            ->where('fiscal_year_id', $validated['fiscal_year_id'])
            ->where('account_id', $validated['account_id'])
            ->delete();

        return back()->with('success', __('messages.budget_cleared'));
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * Fetch posted actuals from journal_lines, grouped by account + period month.
     * Returns associative array: "{account_id}-{month}" => amount
     *
     * For debit-normal accounts (asset/cogs/expense): sum debits − credits
     * For credit-normal accounts (liability/equity/revenue): sum credits − debits
     */
    private function fetchActuals(int $companyId, int $fiscalYearId, ?FiscalYear $fy): \Illuminate\Support\Collection
    {
        if (!$fy) return collect();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->where('je.fiscal_year_id', $fiscalYearId)
            ->where('je.status', 'posted')
            ->whereIn('a.type', ['revenue', 'cogs', 'expense'])
            ->select(
                'jl.account_id',
                'je.period',
                'a.normal_balance',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->groupBy('jl.account_id', 'je.period', 'a.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $row->account_id . '-' . $row->period;
            // Net amount by normal balance convention
            $net = ($row->normal_balance === 'debit')
                ? ($row->total_debit - $row->total_credit)
                : ($row->total_credit - $row->total_debit);
            $result[$key] = round($net, 2);
        }

        return collect($result);
    }

    /**
     * Build the 12-month grid for Inertia.
     * 📌 BudgetVariancePercent applied per account per month.
     */
    private function buildGrid(
        \Illuminate\Support\Collection $accounts,
        \Illuminate\Support\Collection $budgetRows,
        \Illuminate\Support\Collection $actuals,
        ?FiscalYear $fy
    ): \Illuminate\Support\Collection {

        return $accounts->map(function ($account) use ($budgetRows, $actuals, $fy) {
            $months = [];

            for ($m = 1; $m <= 12; $m++) {
                $budgetRow = $budgetRows->first(fn($r) => $r->account_id == $account->id && $r->month == $m);
                $budgetAmt = (float)($budgetRow?->amount ?? 0);
                $actualAmt = (float)($actuals->get($account->id . '-' . $m, 0));

                // 📌 BudgetVariancePercent
                $absolute = $actualAmt - $budgetAmt;
                $percent  = $budgetAmt != 0 ? round($absolute / $budgetAmt * 100, 2) : null;
                $flag     = $this->varianceFlag($absolute, $account->type);

                $months[$m] = [
                    'budget'   => $budgetAmt,
                    'actual'   => $actualAmt,
                    'variance' => $absolute,
                    'pct'      => $percent,
                    'flag'     => $flag,
                    'notes'    => $budgetRow?->notes,
                ];
            }

            $totalBudget = array_sum(array_column($months, 'budget'));
            $totalActual = array_sum(array_column($months, 'actual'));
            $totalVar    = $totalActual - $totalBudget;
            $totalPct    = $totalBudget != 0 ? round($totalVar / $totalBudget * 100, 2) : null;

            return [
                'account_id'    => $account->id,
                'account_code'  => $account->code,
                'account_en'    => $account->name_en,
                'account_ar'    => $account->name_ar,
                'account_type'  => $account->type,
                'months'        => $months,
                'total_budget'  => round($totalBudget, 2),
                'total_actual'  => round($totalActual, 2),
                'total_variance'=> round($totalVar, 2),
                'total_pct'     => $totalPct,
                'total_flag'    => $this->varianceFlag($totalVar, $account->type),
            ];
        });
    }

    /**
     * 📌 BudgetVariancePercent — determine flag
     * Revenue: higher actual = favourable (+)
     * Expense/COGS: lower actual = favourable (negative variance)
     */
    private function varianceFlag(float $variance, string $accountType): ?string
    {
        if ($variance == 0) return 'neutral';
        return match ($accountType) {
            'revenue'         => $variance > 0 ? 'favourable' : 'unfavourable',
            'cogs', 'expense' => $variance < 0 ? 'favourable' : 'unfavourable',
            default           => null,
        };
    }
}