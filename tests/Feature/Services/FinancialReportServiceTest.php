<?php

namespace Tests\Feature\Services;

use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════════
//  FinancialReportServiceTest
//
//  Covers:
//    Methodology #5  — TrialBalance
//    Methodology #6  — ProfitAndLoss
//    Methodology #7  — BalanceSheet
//    Methodology #8  — CashFlow
//    Methodology #38 — BcmathMonetaryPrecision
//
//  Test file placement: tests/Feature/Services/FinancialReportServiceTest.php
//
//  All tests hit the database — RefreshDatabase rolls back after each test.
//  No factories are used; all data is inserted via DB::table() helpers so
//  the tests remain dependency-free from the model layer.
// ════════════════════════════════════════════════════════════════════════════

class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialReportService $svc;

    // ── Company and Account IDs shared across helpers ──────────────────────
    private int $companyId;
    private int $companyBId;   // second company for isolation tests

    // Account IDs — populated by makeAccount()
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = new FinancialReportService();

        // ── Two companies ──────────────────────────────────────────────────
        $this->companyId  = $this->makeCompany('VERO Test Farm A');
        $this->companyBId = $this->makeCompany('VERO Test Farm B');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SECTION 1 — TRIAL BALANCE  (Methodology #5)
    // ════════════════════════════════════════════════════════════════════════

    // ── G1: Account with activity appears; zero-activity account is excluded ─

    /** @test */
    public function trial_balance_includes_accounts_that_have_activity(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',   'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable','liability', 'credit');

        // Post a balanced entry within the period
        $this->postEntry('2025-01-15', [
            [$wipId, 5000, 0],
            [$apId,  0,    5000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $codes = array_column($result['rows'], 'code');
        $this->assertContains('1310', $codes);
        $this->assertContains('2210', $codes);
    }

    /** @test */
    public function trial_balance_excludes_accounts_with_zero_activity(): void
    {
        // Create an account but post nothing to it
        $this->makeAccount('1320', 'Feed Inventory', 'asset', 'debit');

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $codes = array_column($result['rows'], 'code');
        $this->assertNotContains('1320', $codes);
    }

    // ── G2: Opening balance sign matches normal side ────────────────────────

    /** @test */
    public function trial_balance_opening_debit_normal_account_is_debit_minus_credit(): void
    {
        // Livestock WIP (debit-normal): an entry BEFORE the period
        // Dr WIP 8 000 / Cr AP 8 000
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable', 'liability', 'credit');

        $this->postEntry('2024-12-20', [
            [$wipId, 8000, 0],
            [$apId,  0,    8000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $wipRow = $this->findRow($result['rows'], '1310');
        // Opening for debit-normal = debit − credit = 8 000 − 0 = 8 000
        $this->assertEquals(8000.00, $wipRow['opening']);
        $this->assertEquals(0.00,    $wipRow['period_debit']);
        $this->assertEquals(0.00,    $wipRow['period_credit']);
    }

    /** @test */
    public function trial_balance_opening_credit_normal_account_is_credit_minus_debit(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable', 'liability', 'credit');

        $this->postEntry('2024-12-20', [
            [$wipId, 8000, 0],
            [$apId,  0,    8000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $apRow = $this->findRow($result['rows'], '2210');
        // Opening for credit-normal = credit − debit = 8 000 − 0 = 8 000
        $this->assertEquals(8000.00, $apRow['opening']);
    }

    // ── G3: Period movements are isolated to the period window ─────────────

    /** @test */
    public function trial_balance_pre_period_lines_appear_only_in_opening_not_period_columns(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable', 'liability', 'credit');

        // Entry BEFORE period
        $this->postEntry('2024-12-31', [
            [$wipId, 3000, 0],
            [$apId,  0,    3000],
        ]);
        // Entry WITHIN period
        $this->postEntry('2025-01-10', [
            [$wipId, 2000, 0],
            [$apId,  0,    2000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $wipRow = $this->findRow($result['rows'], '1310');
        $this->assertEquals(3000.00, $wipRow['opening']);      // only the pre-period entry
        $this->assertEquals(2000.00, $wipRow['period_debit']); // only the in-period entry
        $this->assertEquals(0.00,    $wipRow['period_credit']);
    }

    // ── G4: Closing = opening + net period movement ─────────────────────────

    /** @test */
    public function trial_balance_closing_equals_opening_plus_net_period_movement(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $revId = $this->makeAccount('4100', 'Revenue',          'revenue',   'credit');

        // Opening: Dr WIP 5 000 / Cr Revenue 5 000 (before period)
        $this->postEntry('2024-12-15', [
            [$wipId, 5000, 0],
            [$revId, 0,    5000],
        ]);
        // Period: Dr WIP 2 000 / Cr WIP 1 000  → net +1 000 to WIP
        $this->postEntry('2025-01-20', [
            [$wipId, 2000, 0],
            [$revId, 0,    2000],
        ]);
        $this->postEntry('2025-01-25', [
            [$revId, 0,    0],   // dummy balance line
            [$wipId, 0,    1000],
            [$revId, 1000, 0],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $wipRow = $this->findRow($result['rows'], '1310');
        // Opening=5000, period_debit=2000, period_credit=1000 → net=+1000 → closing=6000
        $this->assertEquals(5000.00, $wipRow['opening']);
        $this->assertEquals(2000.00, $wipRow['period_debit']);
        $this->assertEquals(1000.00, $wipRow['period_credit']);
        $this->assertEquals(6000.00, $wipRow['closing']);
    }

    // ── G5: Balanced ledger flag ─────────────────────────────────────────────

    /** @test */
    public function trial_balance_balanced_flag_is_true_for_a_balanced_ledger(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable', 'liability', 'credit');

        $this->postEntry('2025-01-10', [
            [$wipId, 10000, 0],
            [$apId,  0,     10000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $this->assertTrue($result['balanced']);
        $this->assertEqualsWithDelta(0.0, $result['imbalance'], 0.01);
    }

    // ── G6: Draft entries are excluded entirely ──────────────────────────────

    /** @test */
    public function trial_balance_excludes_draft_journal_entries(): void
    {
        $wipId = $this->makeAccount('1310', 'Livestock WIP',    'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Accounts Payable', 'liability', 'credit');

        // Insert a draft entry — NOT posted
        $this->insertEntry('2025-01-10', 'draft', [
            [$wipId, 7000, 0],
            [$apId,  0,    7000],
        ]);

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        // No rows because the only entry is draft
        $this->assertEmpty($result['rows']);
    }

    // ── G7: Cross-company isolation ──────────────────────────────────────────

    /** @test */
    public function trial_balance_does_not_include_other_company_entries(): void
    {
        // Accounts for company A and company B
        $wipA = $this->makeAccount('1310', 'WIP A', 'asset', 'debit',  $this->companyId);
        $apA  = $this->makeAccount('2210', 'AP A',  'liability', 'credit', $this->companyId);

        $wipB = $this->makeAccount('1310', 'WIP B', 'asset', 'debit',  $this->companyBId);
        $apB  = $this->makeAccount('2210', 'AP B',  'liability', 'credit', $this->companyBId);

        // Post to company A
        $this->postEntry('2025-01-10', [[$wipA, 4000, 0], [$apA, 0, 4000]], $this->companyId);
        // Post to company B
        $this->postEntry('2025-01-10', [[$wipB, 9000, 0], [$apB, 0, 9000]], $this->companyBId);

        // Query company A only
        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $wipRow = $this->findRow($result['rows'], '1310');
        // Should see only A's 4 000, not B's 9 000
        $this->assertEquals(4000.00, $wipRow['period_debit']);
    }

    // ── G8: bcmath precision — large repeating decimal ──────────────────────

    /** @test */
    public function trial_balance_accumulates_totals_without_float_drift(): void
    {
        // 3 × 333.33 = 999.99 — well-known float problem if summed naively
        $wipId = $this->makeAccount('1310', 'WIP',       'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'Payables',  'liability', 'credit');

        foreach ([333.33, 333.33, 333.33] as $amount) {
            $this->postEntry('2025-01-05', [
                [$wipId, $amount, 0],
                [$apId,  0,       $amount],
            ]);
        }

        $result = $this->svc->trialBalance($this->companyId, '2025-01-01', '2025-01-31');

        $wipRow = $this->findRow($result['rows'], '1310');
        $this->assertEquals(999.99, $wipRow['period_debit']);
        $this->assertEquals(999.99, $wipRow['closing']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SECTION 2 — PROFIT & LOSS  (Methodology #6)
    // ════════════════════════════════════════════════════════════════════════

    // ── G9: Revenue line amount = credit − debit ──────────────────────────

    /** @test */
    public function profit_and_loss_revenue_line_amount_equals_credit_minus_debit(): void
    {
        $revId = $this->makeAccount('4100', 'Animal Sales Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR Receivable',        'asset',   'debit');

        // Sale: Dr AR 10 000 / Cr Revenue 10 000
        $this->postEntry('2025-01-20', [
            [$arId,  10000, 0],
            [$revId, 0,     10000],
        ]);

        $result = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31');
        $current = $result['current'];

        $this->assertEquals(10000.00, $current['total_revenue']);
        $this->assertCount(1, $current['revenue_lines']);
        $this->assertEquals(10000.00, $current['revenue_lines'][0]['amount']);
    }

    // ── G10: COGS line amount = debit − credit ────────────────────────────

    /** @test */
    public function profit_and_loss_cogs_line_amount_equals_debit_minus_credit(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        // Sale entry: Dr AR 10 000 / Cr Revenue 10 000
        $this->postEntry('2025-01-20', [
            [$arId,  10000, 0],
            [$revId, 0,     10000],
        ]);
        // COGS entry: Dr COGS 6 000 / Cr WIP 6 000
        $this->postEntry('2025-01-20', [
            [$cogsId, 6000, 0],
            [$wipId,  0,    6000],
        ]);

        $result  = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31');
        $current = $result['current'];

        $this->assertEquals(6000.00, $current['total_cogs']);
        $this->assertCount(1, $current['cogs_lines']);
        $this->assertEquals(6000.00, $current['cogs_lines'][0]['amount']);
    }

    // ── G11: Gross profit = revenue − COGS ────────────────────────────────

    /** @test */
    public function profit_and_loss_gross_profit_equals_revenue_minus_cogs(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        $this->postEntry('2025-01-20', [[$arId, 10000, 0], [$revId, 0, 10000]]);
        $this->postEntry('2025-01-20', [[$cogsId, 6000, 0], [$wipId, 0, 6000]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        // 10 000 − 6 000 = 4 000
        $this->assertEquals(4000.00, $current['gross_profit']);
    }

    // ── G12: Expenses reduce net income but not gross profit ──────────────

    /** @test */
    public function profit_and_loss_expenses_reduce_net_income_not_gross_profit(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue',      'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',         'cogs',    'debit');
        $expId  = $this->makeAccount('6310', 'Labor Expense', 'expense', 'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',          'asset',   'debit');
        $arId   = $this->makeAccount('1600', 'AR',           'asset',   'debit');
        $bankId = $this->makeAccount('1500', 'Bank',         'asset',   'debit');

        // Revenue 10 000 / COGS 6 000 / Expense 1 000
        $this->postEntry('2025-01-20', [[$arId,  10000, 0], [$revId,  0, 10000]]);
        $this->postEntry('2025-01-20', [[$cogsId, 6000, 0], [$wipId,  0,  6000]]);
        $this->postEntry('2025-01-20', [[$expId,  1000, 0], [$bankId, 0,  1000]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        $this->assertEquals(4000.00, $current['gross_profit']);   // unchanged by expense
        $this->assertEquals(1000.00, $current['total_expenses']);
        $this->assertEquals(3000.00, $current['net_income']);     // 4 000 − 1 000
    }

    // ── G13: Gross margin % = gross profit / revenue × 100 ───────────────

    /** @test */
    public function profit_and_loss_gross_margin_pct_is_correct(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        $this->postEntry('2025-01-20', [[$arId,  10000, 0], [$revId,  0, 10000]]);
        $this->postEntry('2025-01-20', [[$cogsId, 6000, 0], [$wipId,  0,  6000]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        // 4 000 / 10 000 × 100 = 40.00
        $this->assertNotNull($current['gross_margin_pct']);
        $this->assertEqualsWithDelta(40.00, $current['gross_margin_pct'], 0.01);
    }

    // ── G14: Gross margin % is null when revenue is zero ──────────────────

    /** @test */
    public function profit_and_loss_gross_margin_pct_is_null_when_revenue_is_zero(): void
    {
        // Post only a COGS entry — no revenue
        $cogsId = $this->makeAccount('5100', 'COGS', 'cogs',  'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',  'asset', 'debit');

        $this->postEntry('2025-01-20', [[$cogsId, 3000, 0], [$wipId, 0, 3000]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        $this->assertNull($current['gross_margin_pct']);
    }

    // ── G15: Asset / liability accounts do not appear in P&L ─────────────

    /** @test */
    public function profit_and_loss_excludes_asset_and_liability_accounts(): void
    {
        // Post something entirely within balance-sheet accounts — a livestock receipt
        $wipId = $this->makeAccount('1310', 'WIP', 'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'AP',  'liability', 'credit');

        $this->postEntry('2025-01-10', [[$wipId, 5000, 0], [$apId, 0, 5000]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        $this->assertEquals(0.00, $current['total_revenue']);
        $this->assertEquals(0.00, $current['total_cogs']);
        $this->assertEquals(0.00, $current['total_expenses']);
        $this->assertEmpty($current['revenue_lines']);
        $this->assertEmpty($current['cogs_lines']);
        $this->assertEmpty($current['expense_lines']);
    }

    // ── G16: Comparison period returns independent figures ─────────────────

    /** @test */
    public function profit_and_loss_comparison_period_returns_independent_figures(): void
    {
        $revId = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        // Current period: Jan 2025 → 10 000
        $this->postEntry('2025-01-15', [[$arId, 10000, 0], [$revId, 0, 10000]]);
        // Comparison period: Jan 2024 → 7 500
        $this->postEntry('2024-01-15', [[$arId, 7500, 0], [$revId, 0, 7500]]);

        $result = $this->svc->profitAndLoss(
            $this->companyId,
            '2025-01-01', '2025-01-31',
            '2024-01-01', '2024-01-31'
        );

        $this->assertEquals(10000.00, $result['current']['total_revenue']);
        $this->assertNotNull($result['compare']);
        $this->assertEquals(7500.00,  $result['compare']['total_revenue']);
    }

    // ── G17: No comparison period → compare key is null ───────────────────

    /** @test */
    public function profit_and_loss_compare_is_null_when_no_comparison_dates_given(): void
    {
        $revId = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR',      'asset',   'debit');
        $this->postEntry('2025-01-15', [[$arId, 5000, 0], [$revId, 0, 5000]]);

        $result = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31');

        $this->assertNull($result['compare']);
    }

    // ── G18: Period boundary exactness ────────────────────────────────────

    /** @test */
    public function profit_and_loss_includes_entries_on_both_boundary_dates(): void
    {
        $revId = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        // Exactly on date_from
        $this->postEntry('2025-01-01', [[$arId, 1000, 0], [$revId, 0, 1000]]);
        // Exactly on date_to
        $this->postEntry('2025-01-31', [[$arId, 2000, 0], [$revId, 0, 2000]]);
        // One day outside each boundary — must NOT be counted
        $this->postEntry('2024-12-31', [[$arId, 500, 0], [$revId, 0, 500]]);
        $this->postEntry('2025-02-01', [[$arId, 500, 0], [$revId, 0, 500]]);

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        // Only 1 000 + 2 000 = 3 000 should be counted
        $this->assertEquals(3000.00, $current['total_revenue']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SECTION 3 — BALANCE SHEET  (Methodology #7)
    // ════════════════════════════════════════════════════════════════════════

    // ── G19: Asset line appears with debit-normal balance ─────────────────

    /** @test */
    public function balance_sheet_asset_line_uses_debit_minus_credit(): void
    {
        $wipId = $this->makeAccount('1310', 'WIP', 'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'AP',  'liability', 'credit');

        $this->postEntry('2025-01-10', [[$wipId, 12000, 0], [$apId, 0, 12000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        $this->assertCount(1, $result['assets']);
        $this->assertEquals(12000.00, $result['assets'][0]['balance']);
        $this->assertEquals('1310',   $result['assets'][0]['code']);
    }

    // ── G20: Liability line uses credit-normal balance ────────────────────

    /** @test */
    public function balance_sheet_liability_line_uses_credit_minus_debit(): void
    {
        $wipId = $this->makeAccount('1310', 'WIP', 'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'AP',  'liability', 'credit');

        $this->postEntry('2025-01-10', [[$wipId, 8000, 0], [$apId, 0, 8000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        $this->assertCount(1, $result['liabilities']);
        $this->assertEquals(8000.00, $result['liabilities'][0]['balance']);
    }

    // ── G21: Equity line appears in the equity section ────────────────────

    /** @test */
    public function balance_sheet_equity_line_appears_in_equity_section(): void
    {
        $bankId  = $this->makeAccount('1500', 'Bank',   'asset',  'debit');
        $equityId = $this->makeAccount('3100', 'Equity', 'equity', 'credit');

        $this->postEntry('2025-01-05', [[$bankId, 50000, 0], [$equityId, 0, 50000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        $this->assertCount(1, $result['equity']);
        $this->assertEquals(50000.00, $result['equity'][0]['balance']);
    }

    // ── G22: Cumulative — entries after as_of_date are excluded ──────────

    /** @test */
    public function balance_sheet_excludes_entries_after_as_of_date(): void
    {
        $wipId = $this->makeAccount('1310', 'WIP', 'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'AP',  'liability', 'credit');

        // Entry within scope
        $this->postEntry('2025-01-20', [[$wipId, 5000, 0], [$apId, 0, 5000]]);
        // Entry AFTER the as_of_date — must NOT appear
        $this->postEntry('2025-02-05', [[$wipId, 3000, 0], [$apId, 0, 3000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        // Only the 5 000 entry is in scope
        $this->assertEquals(5000.00, $result['assets'][0]['balance']);
        $this->assertEquals(5000.00, $result['liabilities'][0]['balance']);
    }

    // ── G23: YTD net income is injected as synthetic equity line ──────────

    /** @test */
    public function balance_sheet_injects_ytd_net_income_into_equity_when_fiscal_year_exists(): void
    {
        // Create a fiscal year covering the date
        $fyId = $this->makeFiscalYear('2025-01-01', '2025-12-31');

        $revId   = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId  = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $arId    = $this->makeAccount('1600', 'AR',      'asset',   'debit');
        $wipId   = $this->makeAccount('1310', 'WIP',     'asset',   'debit');

        // Revenue 20 000, COGS 12 000 → YTD net income 8 000
        $this->postEntry('2025-06-15', [[$arId, 20000, 0], [$revId, 0, 20000]]);
        $this->postEntry('2025-06-15', [[$cogsId, 12000, 0], [$wipId, 0, 12000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-06-30', $fyId);

        // There should be an RE_YTD line in equity
        $reLine = collect($result['equity'])->firstWhere('code', 'RE_YTD');
        $this->assertNotNull($reLine, 'Expected RE_YTD synthetic equity line');
        $this->assertEquals(8000.00, $reLine['balance']);
    }

    // ── G23b: Profit of an EARLIER year (not closed) still reaches equity ─

    /** @test */
    public function balance_sheet_carries_prior_year_profit_into_equity_and_balances(): void
    {
        $this->makeFiscalYear('2025-01-01', '2025-12-31');
        $fy2026 = $this->makeFiscalYear('2026-01-01', '2026-12-31');

        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');

        // 2025: revenue 20 000, COGS 12 000 → profit 8 000 (never closed)
        $this->postEntry('2025-06-15', [[$arId, 20000, 0], [$revId, 0, 20000]]);
        $this->postEntry('2025-06-15', [[$cogsId, 12000, 0], [$wipId, 0, 12000]]);
        // 2026: revenue 5 000 → profit 5 000
        $this->postEntry('2026-03-10', [[$arId, 5000, 0], [$revId, 0, 5000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2026-06-30', $fy2026);

        $prior = collect($result['equity'])->firstWhere('code', 'RE_PRIOR');
        $ytd   = collect($result['equity'])->firstWhere('code', 'RE_YTD');
        $this->assertNotNull($prior);
        $this->assertEquals(8000.00, $prior['balance']);
        $this->assertEquals(5000.00, $ytd['balance']);
        $this->assertTrue($result['balanced'], 'Balance sheet must balance across year boundaries');
        $this->assertEquals(13000.00, $result['total_equity']);
    }

    // ── G24: No fiscal year → no synthetic RE_YTD line, no crash ─────────

    /** @test */
    public function balance_sheet_has_no_re_ytd_line_when_no_fiscal_year_exists(): void
    {
        $bankId   = $this->makeAccount('1500', 'Bank',   'asset',  'debit');
        $equityId = $this->makeAccount('3100', 'Equity', 'equity', 'credit');

        $this->postEntry('2025-01-10', [[$bankId, 30000, 0], [$equityId, 0, 30000]]);

        // No fiscal year seeded — $fiscalYearId = null
        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        $reLine = collect($result['equity'])->firstWhere('code', 'RE_YTD');
        $this->assertNull($reLine, 'RE_YTD should not exist when no fiscal year covers the date');
    }

    // ── G25: Balance sheet must balance (assets = liabilities + equity) ───

    /** @test */
    public function balance_sheet_balances_within_tolerance(): void
    {
        $fyId = $this->makeFiscalYear('2025-01-01', '2025-12-31');

        $bankId   = $this->makeAccount('1500', 'Bank',    'asset',     'debit');
        $apId     = $this->makeAccount('2210', 'AP',      'liability', 'credit');
        $equityId = $this->makeAccount('3100', 'Equity',  'equity',    'credit');
        $revId    = $this->makeAccount('4100', 'Revenue', 'revenue',   'credit');
        $arId     = $this->makeAccount('1600', 'AR',      'asset',     'debit');

        // Initial equity injection: Dr Bank 100 000 / Cr Equity 100 000
        $this->postEntry('2024-12-01', [[$bankId, 100000, 0], [$equityId, 0, 100000]]);
        // Purchase on credit: Dr Bank 0 (asset stays) but lets post an AP
        $this->postEntry('2025-01-10', [[$bankId, 0, 20000], [$apId, 20000, 0]]);
        // Revenue: Dr AR 15 000 / Cr Revenue 15 000
        $this->postEntry('2025-01-20', [[$arId, 15000, 0], [$revId, 0, 15000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31', $fyId);

        $this->assertTrue($result['balanced'],
            "Balance sheet should balance — imbalance: {$result['imbalance']}");
        $this->assertEqualsWithDelta(0.0, $result['imbalance'], 0.01);
    }

    // ── G26: Revenue / COGS / expense accounts do not appear in BS ────────

    /** @test */
    public function balance_sheet_excludes_revenue_cogs_and_expense_accounts(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');

        $this->postEntry('2025-01-20', [[$arId, 10000, 0], [$revId, 0, 10000]]);
        $this->postEntry('2025-01-20', [[$cogsId, 6000, 0], [$wipId, 0, 6000]]);

        $result = $this->svc->balanceSheet($this->companyId, '2025-01-31');

        $allCodes = collect(array_merge(
            $result['assets'],
            $result['liabilities'],
            $result['equity']
        ))->pluck('code')->all();

        $this->assertNotContains('4100', $allCodes);
        $this->assertNotContains('5100', $allCodes);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SECTION 4 — CASH FLOW  (Methodology #8)
    // ════════════════════════════════════════════════════════════════════════

    // ── G27: Operating CF — net income flows through from P&L ────────────

    /** @test */
    public function cash_flow_operating_net_income_matches_pl_net_income(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $cogsId = $this->makeAccount('5100', 'COGS',    'cogs',    'debit');
        $arId   = $this->makeAccount('1600', 'AR',      'asset',   'debit');
        $wipId  = $this->makeAccount('1310', 'WIP',     'asset',   'debit');

        $this->postEntry('2025-01-15', [[$arId, 10000, 0], [$revId, 0, 10000]]);
        $this->postEntry('2025-01-15', [[$cogsId, 6000, 0], [$wipId, 0, 6000]]);

        $pl  = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];
        $cf  = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        $this->assertEqualsWithDelta($pl['net_income'], $cf['operating']['net_income'], 0.01);
    }

    // ── G28: Operating CF — depreciation is added back (non-cash) ────────

    /** @test */
    public function cash_flow_operating_adds_back_depreciation(): void
    {
        // A depreciation entry: Dr Expense / Cr Accumulated Dep
        // The service finds this via source_type = 'fixed_asset' on the journal entry
        $depExpId  = $this->makeAccount('6100', 'Dep Expense', 'expense', 'debit');
        $accDepId  = $this->makeAccount('1120', 'Acc Dep',     'asset',   'credit');

        // Insert a posted entry with source_type = 'fixed_asset'
        $entryId = $this->insertEntry('2025-01-31', 'posted', [
            [$depExpId, 5000, 0],
            [$accDepId, 0,    5000],
        ], source_type: 'fixed_asset');

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // Depreciation should be positive (added back to operating CF)
        $this->assertEquals(5000.00, $cf['operating']['depreciation']);
    }

    // ── G29: Operating CF — increase in AR uses cash (negative delta) ─────

    /** @test */
    public function cash_flow_operating_ar_increase_produces_negative_delta(): void
    {
        $revId = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        // AR grows during the period (sale on credit, not collected)
        // Before period: AR = 0 (nothing)
        // During period: Dr AR 8 000 / Cr Revenue 8 000
        $this->postEntry('2025-01-20', [[$arId, 8000, 0], [$revId, 0, 8000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // AR increased by 8 000 → delta_ar should be negative (cash was tied up)
        $this->assertLessThan(0, $cf['operating']['delta_ar'],
            'Increase in AR should produce a negative delta_ar (cash used)');
    }

    // ── G30: Operating CF — increase in AP frees cash (positive delta) ───

    /** @test */
    public function cash_flow_operating_ap_increase_produces_positive_delta(): void
    {
        $wipId = $this->makeAccount('1310', 'WIP', 'asset',     'debit');
        $apId  = $this->makeAccount('2210', 'AP',  'liability', 'credit');

        // Livestock purchase on credit: AP grows during the period
        // Before period: AP = 0
        // During period: Dr WIP 15 000 / Cr AP 15 000
        $this->postEntry('2025-01-10', [[$wipId, 15000, 0], [$apId, 0, 15000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // AP increased → delta_ap should be positive (suppliers funded the operation)
        $this->assertGreaterThan(0, $cf['operating']['delta_ap'],
            'Increase in AP should produce a positive delta_ap (cash source)');
    }

    // ── G31: Operating CF — inventory increase uses cash ─────────────────

    /** @test */
    public function cash_flow_operating_inventory_increase_uses_cash(): void
    {
        $invId = $this->makeAccount('1320', 'Feed Inventory', 'asset', 'debit');
        $apId  = $this->makeAccount('2230', 'AP Accruals',    'liability', 'credit');

        // Inventory purchased (cash equivalent for simplicity)
        // Before period: Feed Inv = 0
        // During period: Dr Feed Inv 4 000 / Cr AP Accruals 4 000
        $this->postEntry('2025-01-15', [[$invId, 4000, 0], [$apId, 0, 4000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // Inventory grew → delta_inventory should be negative
        $this->assertLessThanOrEqual(0, $cf['operating']['delta_inventory'],
            'Increase in inventory should produce a negative or zero delta_inventory');
    }

    // ── G32: Investing CF — fixed asset purchase uses cash ───────────────

    /** @test */
    public function cash_flow_investing_fixed_asset_purchase_is_negative(): void
    {
        // Fixed asset accounts use prefix '11'
        $faId   = $this->makeAccount('1100', 'Fixed Assets', 'asset', 'debit');
        $bankId = $this->makeAccount('1500', 'Bank',         'asset', 'debit');

        // Before period: FA = 0
        // During period: buy a tractor — Dr FA 50 000 / Cr Bank 50 000
        $this->postEntry('2025-01-20', [[$faId, 50000, 0], [$bankId, 0, 50000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // Investing CF should be negative (cash went out to buy the asset)
        $this->assertLessThan(0, $cf['investing']['total'],
            'Fixed asset purchase should produce negative investing CF');
    }

    // ── G33: Financing CF — new loan is positive CF ───────────────────────

    /** @test */
    public function cash_flow_financing_new_loan_is_positive(): void
    {
        // Long-term liability prefix '21'
        $loanId = $this->makeAccount('2100', 'Long-Term Loan', 'liability', 'credit');
        $bankId = $this->makeAccount('1500', 'Bank',           'asset',     'debit');

        // Loan received: Dr Bank 200 000 / Cr Loan 200 000
        // Before period: Loan = 0
        $this->postEntry('2025-01-05', [[$bankId, 200000, 0], [$loanId, 0, 200000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        // Financing CF should be positive (loan receipt is a cash inflow)
        $this->assertGreaterThan(0, $cf['financing']['total'],
            'Receiving a new loan should produce positive financing CF');
    }

    // ── G34: Net change = operating + investing + financing ───────────────

    /** @test */
    public function cash_flow_net_change_equals_sum_of_three_sections(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue',   'revenue',   'credit');
        $arId   = $this->makeAccount('1600', 'AR',        'asset',     'debit');
        $faId   = $this->makeAccount('1100', 'FA',        'asset',     'debit');
        $bankId = $this->makeAccount('1500', 'Bank',      'asset',     'debit');
        $loanId = $this->makeAccount('2100', 'Loan',      'liability', 'credit');

        // Revenue
        $this->postEntry('2025-01-15', [[$arId, 10000, 0], [$revId, 0, 10000]]);
        // FA purchase
        $this->postEntry('2025-01-10', [[$faId, 5000, 0], [$bankId, 0, 5000]]);
        // Loan
        $this->postEntry('2025-01-05', [[$bankId, 20000, 0], [$loanId, 0, 20000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        $expected = round(
            $cf['operating']['total'] + $cf['investing']['total'] + $cf['financing']['total'],
            2
        );

        $this->assertEqualsWithDelta($expected, $cf['net_change'], 0.01);
    }

    // ── G35: Closing cash = opening cash + net change ─────────────────────

    /** @test */
    public function cash_flow_closing_cash_equals_opening_plus_net_change(): void
    {
        $revId  = $this->makeAccount('4100', 'Revenue',   'revenue',   'credit');
        $arId   = $this->makeAccount('1600', 'AR',        'asset',     'debit');
        $bankId = $this->makeAccount('1500', 'Bank',      'asset',     'debit');

        // Pre-period: existing bank balance
        $equityId = $this->makeAccount('3100', 'Equity', 'equity', 'credit');
        $this->postEntry('2024-12-01', [[$bankId, 30000, 0], [$equityId, 0, 30000]]);

        // Period: revenue (credit sale, no cash movement in bank)
        $this->postEntry('2025-01-15', [[$arId, 5000, 0], [$revId, 0, 5000]]);

        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        $expectedClosing = round($cf['opening_cash'] + $cf['net_change'], 2);
        $this->assertEqualsWithDelta($expectedClosing, $cf['closing_cash'], 0.01);
    }

    // ── G36: Empty period returns all zeros and does not crash ────────────

    /** @test */
    public function cash_flow_returns_all_zeros_for_an_empty_period(): void
    {
        $cf = $this->svc->cashFlow($this->companyId, '2025-01-01', '2025-01-31');

        $this->assertEquals(0.0, $cf['operating']['net_income']);
        $this->assertEquals(0.0, $cf['operating']['depreciation']);
        $this->assertEquals(0.0, $cf['operating']['total']);
        $this->assertEquals(0.0, $cf['investing']['total']);
        $this->assertEquals(0.0, $cf['financing']['total']);
        $this->assertEquals(0.0, $cf['net_change']);
        $this->assertEquals(0.0, $cf['opening_cash']);
        $this->assertEquals(0.0, $cf['closing_cash']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SECTION 5 — BcmathMonetaryPrecision  (Methodology #38)
    // ════════════════════════════════════════════════════════════════════════

    // ── G37: Output values are PHP floats rounded to 2 decimal places ─────

    /** @test */
    public function financial_report_output_values_are_floats_rounded_to_two_decimal_places(): void
    {
        $revId = $this->makeAccount('4100', 'Revenue', 'revenue', 'credit');
        $arId  = $this->makeAccount('1600', 'AR',      'asset',   'debit');

        // 3 × 333.33 = 999.99
        foreach ([333.33, 333.33, 333.33] as $amount) {
            $this->postEntry('2025-01-10', [[$arId, $amount, 0], [$revId, 0, $amount]]);
        }

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        $this->assertIsFloat($current['total_revenue']);
        // Must be exactly 999.99, not 999.9899999... or 1000.00
        $this->assertEquals(999.99, $current['total_revenue']);
    }

    // ── G38: Large multi-account accumulation stays exact ─────────────────

    /** @test */
    public function financial_report_accumulates_many_accounts_without_float_drift(): void
    {
        // Post 10 revenue lines of 3 333.33 each = 33 333.30
        $arId = $this->makeAccount('1600', 'AR', 'asset', 'debit');

        $totalPosted = 0.0;
        for ($i = 1; $i <= 10; $i++) {
            $revId = $this->makeAccount("41{$i}0", "Revenue $i", 'revenue', 'credit');
            $amount = 3333.33;
            $totalPosted = round($totalPosted + $amount, 2);
            $this->postEntry('2025-01-10', [
                [$arId,  $amount, 0],
                [$revId, 0,       $amount],
            ]);
        }

        $current = $this->svc->profitAndLoss($this->companyId, '2025-01-01', '2025-01-31')['current'];

        // 10 × 3 333.33 = 33 333.30
        $this->assertEqualsWithDelta(33333.30, $current['total_revenue'], 0.01,
            'bcmath accumulation across 10 accounts must not drift');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  HELPER METHODS
    //  All helpers insert directly via DB::table() — no model dependencies.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a minimal company row.
     */
    private function makeCompany(string $name): int
    {
        return DB::table('companies')->insertGetId([
            'name_en'                => $name,
            'name_ar'                => $name,
            'country'                => 'EG',
            'currency'               => 'EGP',
            'fiscal_year_start_month'=> 1,
            'accounting_mode'        => 'standalone',
            'is_active'              => 1,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * Create an account row.
     * All accounts default to active and allow_direct_posting = true.
     *
     * @param int|null $companyId  Defaults to $this->companyId when omitted.
     */
    private function makeAccount(
        string   $code,
        string   $nameEn,
        string   $type,
        string   $normalBalance,
        ?int     $companyId = null
    ): int {
        return DB::table('accounts')->insertGetId([
            'company_id'          => $companyId ?? $this->companyId,
            'code'                => $code,
            'name_en'             => $nameEn,
            'name_ar'             => $nameEn,
            'type'                => $type,
            'normal_balance'      => $normalBalance,
            'allow_direct_posting'=> 1,
            'is_active'           => 1,
            'is_system'           => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /**
     * Insert a journal entry with lines in status = 'posted'.
     *
     * $lines format: [[account_id, debit, credit], ...]
     */
    private function postEntry(
        string  $date,
        array   $lines,
        ?int    $companyId   = null,
        ?string $source_type = null
    ): int {
        return $this->insertEntry($date, 'posted', $lines, $companyId, $source_type);
    }

    /**
     * Insert a journal entry with lines in the given status.
     *
     * $lines format: [[account_id, debit, credit], ...]
     */
    private function insertEntry(
        string  $date,
        string  $status,
        array   $lines,
        ?int    $companyId   = null,
        ?string $source_type = null
    ): int {
        $cid = $companyId ?? $this->companyId;

        // Derive period from date month
        $period = (int) date('n', strtotime($date));

        $totalDebit  = array_sum(array_column($lines, 1));
        $totalCredit = array_sum(array_column($lines, 2));

        $entryId = DB::table('journal_entries')->insertGetId([
            'company_id'   => $cid,
            'date'         => $date,
            'description'  => 'Test entry',
            'period'       => $period,
            'status'       => $status,
            'entry_type'   => 'manual',
            'source_type'  => $source_type,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        foreach ($lines as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'entry_id'   => $entryId,
                'account_id' => $accountId,
                'debit'      => $debit,
                'credit'     => $credit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $entryId;
    }

    /**
     * Create a fiscal year row.
     */
    private function makeFiscalYear(string $start, string $end): int
    {
        $year = (int) date('Y', strtotime($start));
        return DB::table('fiscal_years')->insertGetId([
            'company_id' => $this->companyId,
            'name'       => "FY $year",
            'year'       => $year,
            'start_date' => $start,
            'end_date'   => $end,
            'status'     => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Find a row in the trial balance result by account code.
     */
    private function findRow(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        $this->fail("No trial balance row found for account code '{$code}'");
    }
}
