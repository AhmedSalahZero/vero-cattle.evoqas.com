<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  OpeningBalancesTest
//
//  The opening-balance wizard, through the real routes:
//    • the entry always balances; the gap goes to Retained Earnings
//    • stock value = quantity x cost, rounded to the cent
//    • the date must sit inside an OPEN fiscal year (save, edit, post)
//    • only one per year; a posted one is final
//    • accounts, customers, suppliers and items of another company
//      (or accounts that do not accept direct posting) are refused
//    • who may do what
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class OpeningBalancesTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'fiscal_year_id'               => $this->s->fiscalYear->id,
            'date'                         => '2026-01-01',
            'retained_earnings_account_id' => $this->s->account('3400')->id,
            'account_lines'                => [
                ['account_id' => $this->s->cashAccount()->id, 'amount' => 10000, 'side' => 'dr'],
                ['account_id' => $this->s->account('2210')->id, 'amount' => 3000, 'side' => 'cr'],
            ],
        ], $over);
    }

    private function entries()
    {
        return JournalEntry::where('company_id', $this->s->company->id)->where('entry_type', 'opening_balance');
    }

    private function closedYear(int $year): FiscalYear
    {
        return FiscalYear::factory()->create([
            'company_id' => $this->s->company->id,
            'start_date' => "$year-01-01",
            'end_date'   => "$year-12-31",
            'status'     => 'closed',
        ]);
    }

    // ── Balancing ─────────────────────────────────────────────────

    public function test_difference_goes_to_retained_earnings_and_entry_balances(): void
    {
        $u = $this->s->user(['create journal_entries']);

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['auto_post' => true]))
            ->assertSessionHasNoErrors();

        $e = $this->entries()->firstOrFail();
        $this->assertSame('posted', $e->status);
        $this->assertSame(10000.0, (float) $e->lines()->sum('debit'));
        $this->assertSame(10000.0, (float) $e->lines()->sum('credit'));
        // the plug: credit 7,000 on Retained Earnings
        $this->assertSame(7000.0, (float) $e->lines()->where('account_id', $this->s->account('3400')->id)->sum('credit'));
    }

    public function test_already_balanced_input_gets_no_plug_line(): void
    {
        $u = $this->s->user(['create journal_entries']);
        $data = $this->payload([
            'account_lines' => [
                ['account_id' => $this->s->cashAccount()->id, 'amount' => 500, 'side' => 'dr'],
                ['account_id' => $this->s->account('2210')->id, 'amount' => 500, 'side' => 'cr'],
            ],
        ]);

        $this->actingAs($u)->post(route('opening-balances.store'), $data)->assertSessionHasNoErrors();

        $this->assertSame(2, $this->entries()->firstOrFail()->lines()->count());
    }

    public function test_stock_value_is_quantity_times_cost_rounded_to_the_cent(): void
    {
        $u    = $this->s->user(['create journal_entries']);
        $item = $this->s->stockedItem('feed', '0', '1')['master'];

        // 3.333 x 1.005 = 3.349665 -> 3.35
        $data = $this->payload([
            'account_lines'   => [],
            'inventory_lines' => [[
                'account_id' => $this->s->account('1320')->id,
                'item_id'    => $item->id,
                'qty'        => 3.333,
                'unit_cost'  => 1.005,
            ]],
        ]);

        $this->actingAs($u)->post(route('opening-balances.store'), $data)->assertSessionHasNoErrors();

        $line = $this->entries()->firstOrFail()->lines()->where('account_id', $this->s->account('1320')->id)->firstOrFail();
        $this->assertSame('3.35', number_format((float) $line->debit, 2, '.', ''));
    }

    public function test_customer_breakdown_posts_one_line_per_customer(): void
    {
        $u  = $this->s->user(['create journal_entries']);
        $c2 = \App\Models\Customer::create([
            'company_id' => $this->s->company->id, 'name_en' => 'Second', 'name_ar' => 'ثان', 'is_active' => true,
        ]);

        $data = $this->payload(['account_lines' => [[
            'account_id'    => $this->s->account('1600')->id,
            'side'          => 'dr',
            'customer_rows' => [
                ['customer_id' => $this->s->customer->id, 'amount' => 400],
                ['customer_id' => $c2->id, 'amount' => 600],
            ],
        ]]]);

        $this->actingAs($u)->post(route('opening-balances.store'), $data)->assertSessionHasNoErrors();

        $e = $this->entries()->firstOrFail();
        $this->assertSame(2, $e->lines()->where('account_id', $this->s->account('1600')->id)->count());
        $this->assertSame(1000.0, (float) $e->lines()->where('account_id', $this->s->account('1600')->id)->sum('debit'));
    }

    // ── Fiscal year control ───────────────────────────────────────

    public function test_date_outside_the_year_is_refused(): void
    {
        $u = $this->s->user(['create journal_entries']);

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['date' => '2027-03-01']))
            ->assertSessionHasErrors('date');

        $this->assertSame(0, $this->entries()->count());
    }

    public function test_closed_year_is_refused(): void
    {
        $u  = $this->s->user(['create journal_entries']);
        $fy = $this->closedYear(2025);

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload([
            'fiscal_year_id' => $fy->id, 'date' => '2025-02-01',
        ]))->assertStatus(422);

        $this->assertSame(0, $this->entries()->count());
    }

    public function test_draft_cannot_be_edited_or_posted_after_its_year_closes(): void
    {
        $u = $this->s->user(['create journal_entries', 'edit journal_entries']);
        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload())->assertSessionHasNoErrors();
        $e = $this->entries()->firstOrFail();
        $this->assertSame('draft', $e->status);

        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($u)->put(route('opening-balances.update', $e), $this->payload())->assertStatus(422);
        $this->actingAs($u)->post(route('opening-balances.post', $e))->assertStatus(422);
        $this->assertSame('draft', $e->fresh()->status);
    }

    public function test_draft_can_be_edited_and_posted_while_year_is_open(): void
    {
        $u = $this->s->user(['create journal_entries', 'edit journal_entries']);
        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload())->assertSessionHasNoErrors();
        $e = $this->entries()->firstOrFail();

        $this->actingAs($u)->put(route('opening-balances.update', $e), $this->payload([
            'account_lines' => [
                ['account_id' => $this->s->cashAccount()->id, 'amount' => 2000, 'side' => 'dr'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2000.0, (float) $e->fresh()->total_debit);

        $this->actingAs($u)->post(route('opening-balances.post', $e))->assertSessionHasNoErrors();
        $this->assertSame('posted', $e->fresh()->status);

        // a posted opening balance is final
        $this->actingAs($u)->put(route('opening-balances.update', $e), $this->payload())->assertStatus(422);
        $this->actingAs($u)->post(route('opening-balances.post', $e))->assertStatus(422);
    }

    // ── One per year ──────────────────────────────────────────────

    public function test_second_opening_balance_for_same_year_is_not_created(): void
    {
        $u = $this->s->user(['create journal_entries']);
        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload());
        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload());

        $this->assertSame(1, $this->entries()->count());
    }

    public function test_cannot_start_another_once_the_first_is_posted(): void
    {
        $u = $this->s->user(['create journal_entries']);
        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['auto_post' => true]));

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload())
            ->assertSessionHasErrors('fiscal_year_id');

        $this->assertSame(1, $this->entries()->count());
    }

    // ── Company boundaries & account rules ────────────────────────

    public function test_other_companys_account_is_refused(): void
    {
        $u     = $this->s->user(['create journal_entries']);
        $other = CattleScenario::make();

        $data = $this->payload(['account_lines' => [
            ['account_id' => $other->cashAccount()->id, 'amount' => 100, 'side' => 'dr'],
        ]]);

        $this->actingAs($u)->post(route('opening-balances.store'), $data)->assertSessionHasErrors('account_lines');
        $this->assertSame(0, $this->entries()->count());
    }

    public function test_other_companys_customer_supplier_and_item_are_refused(): void
    {
        $u     = $this->s->user(['create journal_entries']);
        $other = CattleScenario::make();
        $sup   = Supplier::factory()->create(['company_id' => $other->company->id]);
        $item  = $other->stockedItem('feed')['master'];

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['account_lines' => [[
            'account_id' => $this->s->account('1600')->id, 'side' => 'dr',
            'customer_rows' => [['customer_id' => $other->customer->id, 'amount' => 50]],
        ]]]))->assertSessionHasErrors('customer_rows');

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['account_lines' => [[
            'account_id' => $this->s->account('2210')->id, 'side' => 'cr',
            'supplier_rows' => [['supplier_id' => $sup->id, 'amount' => 50]],
        ]]]))->assertSessionHasErrors('supplier_rows');

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload([
            'account_lines'   => [],
            'inventory_lines' => [[
                'account_id' => $this->s->account('1320')->id, 'item_id' => $item->id, 'qty' => 1, 'unit_cost' => 1,
            ]],
        ]))->assertSessionHasErrors('inventory_lines');

        $this->assertSame(0, $this->entries()->count());
    }

    public function test_group_accounts_that_do_not_accept_posting_are_refused(): void
    {
        $u = $this->s->user(['create journal_entries']);

        $this->actingAs($u)->post(route('opening-balances.store'), $this->payload(['account_lines' => [
            ['account_id' => $this->s->account('1100')->id, 'amount' => 100, 'side' => 'dr'],
        ]]))->assertSessionHasErrors('account_lines');

        $this->assertSame(0, $this->entries()->count());
    }

    // ── Permissions ───────────────────────────────────────────────

    public function test_user_without_permission_is_refused(): void
    {
        $viewer = $this->s->user(['view journal_entries']);

        $this->actingAs($viewer)->post(route('opening-balances.store'), $this->payload())->assertStatus(403);
        $this->assertSame(0, $this->entries()->count());
    }

    public function test_other_company_user_cannot_edit_or_post(): void
    {
        $owner = $this->s->user(['create journal_entries']);
        $this->actingAs($owner)->post(route('opening-balances.store'), $this->payload());
        $e = $this->entries()->firstOrFail();

        $other = CattleScenario::make()->user(['create journal_entries', 'edit journal_entries']);

        $this->actingAs($other)->post(route('opening-balances.post', $e))->assertStatus(403);
        $this->actingAs($other)->put(route('opening-balances.update', $e), $this->payload())->assertStatus(403);
        $this->assertSame('draft', $e->fresh()->status);
    }
}
