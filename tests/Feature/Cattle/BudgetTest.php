<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  BudgetTest
//
//  The budget grid, through the real routes:
//    • saving a year's budget for one account: exactly 12 distinct
//      months, open year, own company's year and account only
//    • saving again replaces (never duplicates)
//    • copy-from-actuals: last year's posted figures x growth %,
//      rounded to the cent, written into an open target year
//    • clearing one account's budget
//    • the page's net totals = revenue budget - cost budget
//    • who may do what
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s = CattleScenario::make();      // open FY 2026
    }

    private function planner()
    {
        return $this->s->user(['view budgets', 'create budgets', 'edit budgets', 'delete budgets']);
    }

    private function months(float $each = 100.0): array
    {
        $m = [];
        for ($i = 1; $i <= 12; $i++) {
            $m[] = ['month' => $i, 'amount' => $each];
        }
        return $m;
    }

    private function save(string $code = '6910', ?int $fyId = null, ?array $months = null, $user = null)
    {
        return $this->actingAs($user ?? $this->planner())->post(route('budgets.bulk-upsert'), [
            'fiscal_year_id' => $fyId ?? $this->s->fiscalYear->id,
            'account_id'     => $this->s->account($code)->id,
            'months'         => $months ?? $this->months(),
        ]);
    }

    private function rows(string $code = '6910', ?int $fyId = null)
    {
        return Budget::where('company_id', $this->s->company->id)
            ->where('fiscal_year_id', $fyId ?? $this->s->fiscalYear->id)
            ->where('account_id', $this->s->account($code)->id);
    }

    private function priorYear(string $status = 'closed'): FiscalYear
    {
        return FiscalYear::factory()->create([
            'company_id' => $this->s->company->id,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => $status,
        ]);
    }

    /** A posted entry in the given year: Dr expense (or Cr revenue) / opposite cash. */
    private function postedActual(FiscalYear $fy, string $code, string $amount, int $month, bool $revenue = false): void
    {
        $cash = $this->s->cashAccount();
        $acc  = $this->s->account($code);

        $je = JournalEntry::create([
            'company_id'     => $this->s->company->id,
            'fiscal_year_id' => $fy->id,
            'date'           => sprintf('%d-%02d-10', $fy->year ?? 2025, $month),
            'period'         => $month,
            'reference'      => 'ACT/' . uniqid(),
            'description'    => 'Actual',
            'status'         => 'posted',
            'entry_type'     => 'manual',
            'total_debit'    => $amount,
            'total_credit'   => $amount,
        ]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $acc->id,
            'debit' => $revenue ? 0 : $amount, 'credit' => $revenue ? $amount : 0]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $cash->id,
            'debit' => $revenue ? $amount : 0, 'credit' => $revenue ? 0 : $amount]);
    }

    // ── Saving ────────────────────────────────────────────────────

    public function test_saving_creates_twelve_rows(): void
    {
        $this->save()->assertSessionHasNoErrors();

        $this->assertSame(12, $this->rows()->count());
        $this->assertSame(1200.0, (float) $this->rows()->sum('amount'));
    }

    public function test_saving_again_replaces_instead_of_duplicating(): void
    {
        $this->save();
        $this->save('6910', null, $this->months(250));

        $this->assertSame(12, $this->rows()->count());
        $this->assertSame(3000.0, (float) $this->rows()->sum('amount'));
    }

    public function test_not_exactly_twelve_months_is_refused(): void
    {
        $this->save('6910', null, array_slice($this->months(), 0, 11))->assertSessionHasErrors('months');
        $this->assertSame(0, $this->rows()->count());
    }

    public function test_repeated_month_is_refused(): void
    {
        $m = $this->months();
        $m[11]['month'] = 1;                    // month 1 twice, month 12 missing

        $this->save('6910', null, $m)->assertSessionHasErrors();
        $this->assertSame(0, $this->rows()->count());
    }

    public function test_negative_amount_is_refused(): void
    {
        $m = $this->months();
        $m[0]['amount'] = -5;

        $this->save('6910', null, $m)->assertSessionHasErrors('months.0.amount');
        $this->assertSame(0, $this->rows()->count());
    }

    public function test_closed_year_cannot_be_budgeted(): void
    {
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->save()->assertStatus(422);
        $this->assertSame(0, $this->rows()->count());
    }

    public function test_other_companys_year_or_account_is_refused(): void
    {
        $other = CattleScenario::make();

        $this->actingAs($this->planner())->post(route('budgets.bulk-upsert'), [
            'fiscal_year_id' => $other->fiscalYear->id,
            'account_id'     => $this->s->account('6910')->id,
            'months'         => $this->months(),
        ])->assertStatus(403);

        $this->actingAs($this->planner())->post(route('budgets.bulk-upsert'), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
            'account_id'     => $other->account('6910')->id,
            'months'         => $this->months(),
        ])->assertStatus(403);

        $this->assertSame(0, Budget::count());
    }

    // ── Copy from actuals ─────────────────────────────────────────

    public function test_copy_applies_growth_and_rounds_to_the_cent(): void
    {
        $prior = $this->priorYear();
        $this->postedActual($prior, '6910', '100.05', 3);
        $this->postedActual($prior, '6910', '200.00', 3);   // same month adds up: 300.05
        $this->postedActual($prior, '4100', '1000.00', 4, true);

        $this->actingAs($this->planner())->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $prior->id,
            'target_fiscal_year_id' => $this->s->fiscalYear->id,
            'growth_pct'            => 10,
        ])->assertSessionHasNoErrors();

        // 300.05 x 1.10 = 330.055 -> 330.06
        $this->assertSame('330.06', number_format((float) $this->rows('6910')->where('month', 3)->value('amount'), 2, '.', ''));
        $this->assertSame('1100.00', number_format((float) $this->rows('4100')->where('month', 4)->value('amount'), 2, '.', ''));
    }

    public function test_copy_without_growth_keeps_the_figures(): void
    {
        $prior = $this->priorYear();
        $this->postedActual($prior, '6910', '123.45', 6);

        $this->actingAs($this->planner())->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $prior->id,
            'target_fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('123.45', number_format((float) $this->rows('6910')->where('month', 6)->value('amount'), 2, '.', ''));
    }

    public function test_copy_into_a_closed_or_foreign_year_is_refused(): void
    {
        $prior = $this->priorYear();
        $this->postedActual($prior, '6910', '50', 2);
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($this->planner())->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $prior->id,
            'target_fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertStatus(422);

        $other = CattleScenario::make();
        $this->actingAs($this->planner())->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $prior->id,
            'target_fiscal_year_id' => $other->fiscalYear->id,
        ])->assertStatus(403);

        $this->assertSame(0, Budget::count());
    }

    public function test_copy_ignores_unposted_actuals(): void
    {
        $prior = $this->priorYear();
        $this->postedActual($prior, '6910', '80', 5);
        JournalEntry::where('company_id', $this->s->company->id)->update(['status' => 'draft']);

        $this->actingAs($this->planner())->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $prior->id,
            'target_fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Budget::count());
    }

    // ── Clearing ──────────────────────────────────────────────────

    public function test_clearing_removes_only_that_account(): void
    {
        $this->save('6910');
        $this->save('6920');

        $this->actingAs($this->planner())->delete(route('budgets.destroy-by-account'), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
            'account_id'     => $this->s->account('6910')->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->rows('6910')->count());
        $this->assertSame(12, $this->rows('6920')->count());
    }

    public function test_clearing_in_a_closed_year_is_refused(): void
    {
        $this->save('6910');
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($this->planner())->delete(route('budgets.destroy-by-account'), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
            'account_id'     => $this->s->account('6910')->id,
        ])->assertStatus(422);

        $this->assertSame(12, $this->rows('6910')->count());
    }

    // ── The page ──────────────────────────────────────────────────

    public function test_page_net_budget_is_revenue_minus_costs(): void
    {
        $this->save('4100', null, $this->months(300));
        $this->save('6910', null, $this->months(100));

        $this->actingAs($this->planner())->get(route('budgets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/Budgets/Index')
                ->where('revenueBudget', fn ($v) => abs((float) $v - 3600) < 0.005)
                ->where('costBudget', fn ($v) => abs((float) $v - 1200) < 0.005)
                ->where('netBudget', fn ($v) => abs((float) $v - 2400) < 0.005)
            );
    }

    // ── Permissions ───────────────────────────────────────────────

    public function test_view_only_user_cannot_change_budgets(): void
    {
        $viewer = $this->s->user(['view budgets']);

        $this->save('6910', null, null, $viewer)->assertStatus(403);
        $this->actingAs($viewer)->post(route('budgets.copy-from-actuals'), [
            'source_fiscal_year_id' => $this->priorYear()->id,
            'target_fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertStatus(403);
        $this->actingAs($viewer)->delete(route('budgets.destroy-by-account'), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
            'account_id'     => $this->s->account('6910')->id,
        ])->assertStatus(403);

        $this->assertSame(0, Budget::count());
    }

    public function test_user_without_view_permission_cannot_open_the_page(): void
    {
        $this->actingAs($this->s->user([]))->get(route('budgets.index'))->assertStatus(403);
    }
}
