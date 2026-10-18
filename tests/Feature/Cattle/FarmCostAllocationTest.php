<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  FarmCostAllocationTest  (the screens and their guards; the
//  maths itself is covered by FarmCostAllocationServiceTest)
//
//    • a monthly cost record: own farm / year / overhead types only,
//      one per farm per month, month dates computed from month+year
//    • draft-only edit and delete
//    • posting needs animals on the farm; settling needs a posted
//      record and the company's own payment account; no double settle
//    • overhead types: own accounts only, a name is required, a type
//      in use cannot be deleted
//    • who may do what, and nobody touches another company's records
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FarmCostAllocation;
use App\Models\FarmOverheadLine;
use App\Models\FarmOverheadType;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class FarmCostAllocationTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s = CattleScenario::make();
    }

    private function staff()
    {
        return $this->s->user([
            'view journal_entries', 'create journal_entries', 'edit journal_entries', 'delete journal_entries',
            'view accounts', 'create accounts', 'edit accounts', 'delete accounts',
        ]);
    }

    private function overheadType(?CattleScenario $s = null): FarmOverheadType
    {
        $s ??= $this->s;
        return FarmOverheadType::create([
            'company_id'          => $s->company->id,
            'name_en'             => 'Electricity',
            'name_ar'             => 'كهرباء',
            'accrued_account_id'  => $s->account('2271')->id,
            'variance_account_id' => $s->account('6321')->id,
            'is_active'           => true,
        ]);
    }

    private function allocation(string $status = 'draft', array $over = []): FarmCostAllocation
    {
        return FarmCostAllocation::create(array_merge([
            'company_id'      => $this->s->company->id,
            'farm_id'         => $this->s->farm->id,
            'fiscal_year_id'  => $this->s->fiscalYear->id,
            'month'           => 6,
            'year'            => 2026,
            'month_start'     => '2026-06-01',
            'month_end'       => '2026-06-30',
            'labor_estimated' => 1000,
            'status'          => $status,
        ], $over));
    }

    private function form(array $over = []): array
    {
        return array_merge([
            'farm_id'         => $this->s->farm->id,
            'fiscal_year_id'  => $this->s->fiscalYear->id,
            'month'           => 6,
            'year'            => 2026,
            'labor_estimated' => 1000,
        ], $over);
    }

    // ── Creating ──────────────────────────────────────────────────

    public function test_draft_is_created_with_month_dates_and_overhead_lines(): void
    {
        $type = $this->overheadType();

        $this->actingAs($this->staff())->post(route('farm-cost-allocations.store'), $this->form([
            'overhead_lines' => [['overhead_type_id' => $type->id, 'estimated_amount' => 250]],
        ]))->assertSessionHasNoErrors();

        $a = FarmCostAllocation::firstOrFail();
        $this->assertSame('draft', $a->status);
        $this->assertSame('2026-06-01', $a->month_start->toDateString());
        $this->assertSame('2026-06-30', $a->month_end->toDateString());
        $this->assertSame(1, FarmOverheadLine::where('farm_cost_allocation_id', $a->id)->count());
        $this->assertSame(0, JournalEntry::where('company_id', $this->s->company->id)->count(), 'a draft posts nothing');
    }

    public function test_second_record_for_same_farm_and_month_is_refused(): void
    {
        $u = $this->staff();
        $this->actingAs($u)->post(route('farm-cost-allocations.store'), $this->form());
        $this->actingAs($u)->post(route('farm-cost-allocations.store'), $this->form())->assertSessionHasErrors('month');

        $this->assertSame(1, FarmCostAllocation::count());
    }

    public function test_other_companys_farm_year_or_overhead_type_is_refused(): void
    {
        $other = CattleScenario::make();
        $u     = $this->staff();

        $this->actingAs($u)->post(route('farm-cost-allocations.store'), $this->form(['farm_id' => $other->farm->id]))->assertStatus(403);
        $this->actingAs($u)->post(route('farm-cost-allocations.store'), $this->form(['fiscal_year_id' => $other->fiscalYear->id]))->assertStatus(403);
        $this->actingAs($u)->post(route('farm-cost-allocations.store'), $this->form([
            'overhead_lines' => [['overhead_type_id' => $this->overheadType($other)->id, 'estimated_amount' => 5]],
        ]))->assertSessionHasErrors('overhead_lines');

        $this->assertSame(0, FarmCostAllocation::count());
    }

    public function test_negative_labor_is_refused(): void
    {
        $this->actingAs($this->staff())->post(route('farm-cost-allocations.store'), $this->form(['labor_estimated' => -1]))
            ->assertSessionHasErrors('labor_estimated');
    }

    // ── Editing / deleting ────────────────────────────────────────

    public function test_draft_can_be_edited_and_lines_are_replaced(): void
    {
        $a    = $this->allocation();
        $type = $this->overheadType();
        FarmOverheadLine::create(['farm_cost_allocation_id' => $a->id, 'overhead_type_id' => $type->id, 'estimated_amount' => 10]);

        $this->actingAs($this->staff())->put(route('farm-cost-allocations.update', $a), [
            'labor_estimated' => 1500,
            'overhead_lines'  => [['overhead_type_id' => $type->id, 'estimated_amount' => 99]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1500.0, (float) $a->fresh()->labor_estimated);
        $this->assertSame(1, FarmOverheadLine::where('farm_cost_allocation_id', $a->id)->count());
        $this->assertSame(99.0, (float) FarmOverheadLine::where('farm_cost_allocation_id', $a->id)->value('estimated_amount'));
    }

    public function test_posted_record_cannot_be_edited_or_deleted(): void
    {
        $a = $this->allocation('posted');
        $u = $this->staff();

        $this->actingAs($u)->put(route('farm-cost-allocations.update', $a), ['labor_estimated' => 1])->assertStatus(403);
        $this->actingAs($u)->delete(route('farm-cost-allocations.destroy', $a))->assertStatus(403);

        $this->assertSame(1000.0, (float) $a->fresh()->labor_estimated);
        $this->assertNotNull(FarmCostAllocation::find($a->id));
    }

    public function test_draft_can_be_deleted(): void
    {
        $a = $this->allocation();

        $this->actingAs($this->staff())->delete(route('farm-cost-allocations.destroy', $a))->assertSessionHasNoErrors();
        $this->assertNull(FarmCostAllocation::find($a->id));
    }

    // ── Posting / settling ────────────────────────────────────────

    public function test_farm_without_animals_cannot_be_posted(): void
    {
        $a = $this->allocation();

        $this->actingAs($this->staff())->post(route('farm-cost-allocations.post', $a))->assertSessionHasErrors('post');

        $this->assertSame('draft', $a->fresh()->status);
        $this->assertSame(0, JournalEntry::where('company_id', $this->s->company->id)->count());
    }

    public function test_draft_cannot_be_settled(): void
    {
        $a = $this->allocation();

        $this->actingAs($this->staff())->post(route('farm-cost-allocations.settle', $a), [
            'labor_actual' => 1000, 'payment_account_id' => $this->s->cashAccount()->id,
        ])->assertSessionHasErrors('settle');

        $this->assertSame('draft', $a->fresh()->status);
    }

    public function test_posted_record_settles_once_with_a_balanced_entry(): void
    {
        $a    = $this->allocation('posted');
        $cash = $this->s->cashAccount();
        $u    = $this->staff();

        // labor 1,000 estimated, 1,200 actually paid -> 200 unfavourable variance
        $this->actingAs($u)->post(route('farm-cost-allocations.settle', $a), [
            'labor_actual' => 1200, 'payment_account_id' => $cash->id,
        ])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('settled', $a->status);
        $this->assertSame(200.0, (float) $a->labor_variance);

        $je = JournalEntry::findOrFail($a->labor_settlement_je_id);
        $this->assertSame((float) $je->lines()->sum('debit'), (float) $je->lines()->sum('credit'));
        $this->assertSame(1200.0, (float) $je->lines()->where('account_id', $cash->id)->sum('credit'));
        $this->assertSame(200.0, (float) $je->lines()->where('account_id', $this->s->account('6310')->id)->sum('debit'));

        // settling again changes nothing
        $this->actingAs($u)->post(route('farm-cost-allocations.settle', $a), [
            'labor_actual' => 5, 'payment_account_id' => $cash->id,
        ])->assertSessionHasErrors('settle');
        $this->assertSame(1, JournalEntry::where('entry_type', 'labor_overhead_settlement')->count());
    }

    public function test_other_companys_payment_account_is_refused(): void
    {
        $a     = $this->allocation('posted');
        $other = CattleScenario::make();

        $this->actingAs($this->staff())->post(route('farm-cost-allocations.settle', $a), [
            'labor_actual' => 1000, 'payment_account_id' => $other->cashAccount()->id,
        ])->assertStatus(404);

        $this->assertSame('posted', $a->fresh()->status);
    }

    // ── Overhead types ────────────────────────────────────────────

    public function test_overhead_type_is_created_with_own_accounts(): void
    {
        $this->actingAs($this->staff())->post(route('farm-cost-allocations.overhead-types.store'), [
            'name_en' => 'Water', 'accrued_account_id' => $this->s->account('2272')->id,
            'variance_account_id' => $this->s->account('6322')->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, FarmOverheadType::where('company_id', $this->s->company->id)->count());
    }

    public function test_overhead_type_with_foreign_accounts_or_no_name_is_refused(): void
    {
        $other = CattleScenario::make();
        $u     = $this->staff();

        $this->actingAs($u)->post(route('farm-cost-allocations.overhead-types.store'), [
            'name_en' => 'Water', 'accrued_account_id' => $other->account('2272')->id,
            'variance_account_id' => $this->s->account('6322')->id,
        ])->assertSessionHasErrors('accrued_account_id');

        $this->actingAs($u)->post(route('farm-cost-allocations.overhead-types.store'), [
            'accrued_account_id' => $this->s->account('2272')->id,
            'variance_account_id' => $this->s->account('6322')->id,
        ])->assertSessionHasErrors('name_en');

        $this->assertSame(0, FarmOverheadType::count());
    }

    public function test_type_in_use_cannot_be_deleted_but_unused_can(): void
    {
        $u      = $this->staff();
        $used   = $this->overheadType();
        $unused = $this->overheadType();
        FarmOverheadLine::create([
            'farm_cost_allocation_id' => $this->allocation()->id,
            'overhead_type_id'        => $used->id,
            'estimated_amount'        => 10,
        ]);

        $this->actingAs($u)->delete(route('farm-cost-allocations.overhead-types.destroy', $used))->assertSessionHasErrors('overhead_type');
        $this->assertNotNull(FarmOverheadType::find($used->id));

        $this->actingAs($u)->delete(route('farm-cost-allocations.overhead-types.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertNull(FarmOverheadType::find($unused->id));
    }

    public function test_overhead_type_update_rejects_foreign_accounts(): void
    {
        $t     = $this->overheadType();
        $other = CattleScenario::make();

        $this->actingAs($this->staff())->put(route('farm-cost-allocations.overhead-types.update', $t), [
            'name_en' => 'Electricity', 'is_active' => true,
            'accrued_account_id' => $this->s->account('2271')->id,
            'variance_account_id' => $other->account('6321')->id,
        ])->assertSessionHasErrors('accrued_account_id');

        $this->assertSame($this->s->account('6321')->id, (int) $t->fresh()->variance_account_id);
    }

    // ── Permissions & company boundary ────────────────────────────

    public function test_view_only_user_cannot_change_anything(): void
    {
        $viewer = $this->s->user(['view journal_entries', 'view accounts']);
        $a      = $this->allocation();
        $t      = $this->overheadType();

        $this->actingAs($viewer)->post(route('farm-cost-allocations.store'), $this->form())->assertStatus(403);
        $this->actingAs($viewer)->put(route('farm-cost-allocations.update', $a), ['labor_estimated' => 1])->assertStatus(403);
        $this->actingAs($viewer)->delete(route('farm-cost-allocations.destroy', $a))->assertStatus(403);
        $this->actingAs($viewer)->post(route('farm-cost-allocations.post', $a))->assertStatus(403);
        $this->actingAs($viewer)->post(route('farm-cost-allocations.overhead-types.store'), [
            'name_en' => 'x', 'accrued_account_id' => $this->s->account('2271')->id, 'variance_account_id' => $this->s->account('6321')->id,
        ])->assertStatus(403);
        $this->actingAs($viewer)->delete(route('farm-cost-allocations.overhead-types.destroy', $t))->assertStatus(403);

        $this->assertNotNull(FarmCostAllocation::find($a->id));
        $this->assertNotNull(FarmOverheadType::find($t->id));
    }

    public function test_other_company_user_cannot_touch_the_records(): void
    {
        $a     = $this->allocation();
        $posted = $this->allocation('posted', ['month' => 7, 'month_start' => '2026-07-01', 'month_end' => '2026-07-31']);
        $t     = $this->overheadType();
        $other = CattleScenario::make()->admin();

        $this->actingAs($other)->get(route('farm-cost-allocations.edit', $a))->assertStatus(403);
        $this->actingAs($other)->put(route('farm-cost-allocations.update', $a), ['labor_estimated' => 1])->assertStatus(403);
        $this->actingAs($other)->delete(route('farm-cost-allocations.destroy', $a))->assertStatus(403);
        $this->actingAs($other)->post(route('farm-cost-allocations.post', $a))->assertStatus(403);
        $this->actingAs($other)->post(route('farm-cost-allocations.settle', $posted), [
            'labor_actual' => 1, 'payment_account_id' => 1,
        ])->assertStatus(403);
        $this->actingAs($other)->put(route('farm-cost-allocations.overhead-types.update', $t), [
            'name_en' => 'Hijack', 'is_active' => true,
            'accrued_account_id' => $this->s->account('2271')->id, 'variance_account_id' => $this->s->account('6321')->id,
        ])->assertStatus(403);
        $this->actingAs($other)->delete(route('farm-cost-allocations.overhead-types.destroy', $t))->assertStatus(403);

        $this->assertSame('draft', $a->fresh()->status);
        $this->assertSame('Electricity', $t->fresh()->name_en);
    }
}
