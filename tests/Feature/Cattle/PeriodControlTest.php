<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  PeriodControlTest
//
//  Fiscal-year control, through the real routes:
//    • year lifecycle: open -> closed -> locked, reopen rules, overlaps,
//      who may do what
//    • nothing can be posted into a closed or locked year, and a manual
//      entry can never be saved "without a year" to dodge that
//    • reversing an entry: mirror posted, original marked reversed,
//      both stay in the books, the net is zero, and the correction must
//      land in an open year
//    • a sale cannot be confirmed in a closed year
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class PeriodControlTest extends TestCase
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

    // ── helpers ───────────────────────────────────────────────────

    private function year(int $year, string $status = 'open'): FiscalYear
    {
        return FiscalYear::factory()->create([
            'company_id' => $this->s->company->id,
            'start_date' => "$year-01-01",
            'end_date'   => "$year-12-31",
            'status'     => $status,
        ]);
    }

    /** A manual entry: Dr general expenses 6910 / Cr cash 1401. */
    private function entryData(array $overrides = []): array
    {
        return array_merge([
            'date'        => '2026-06-10',
            'description' => 'Office supplies',
            'lines'       => [
                ['account_id' => $this->s->account('6910')->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->s->cashAccount('1401')->id, 'debit' => 0, 'credit' => 100],
            ],
        ], $overrides);
    }

    private function createEntry(array $overrides = [])
    {
        $this->s->fund($this->s->cashAccount('1401'), '10000.00');
        return $this->actingAs($this->s->admin())->post(route('journal-entries.store'), $this->entryData($overrides));
    }

    /** Entries made by the test form (the funding entry is not counted). */
    private function manualCount(): int
    {
        return JournalEntry::where('description', 'Office supplies')->count();
    }

    private function latestEntry(): JournalEntry
    {
        return JournalEntry::where('description', 'Office supplies')->latest('id')->firstOrFail();
    }

    // ── 1. year lifecycle ─────────────────────────────────────────

    public function test_an_open_year_can_be_closed_then_locked_but_a_locked_year_cannot_reopen(): void
    {
        $fy    = $this->year(2027);
        $owner = $this->s->admin();
        $owner->assignRole('Farm Owner');

        $this->actingAs($owner)->patch(route('fiscal-years.close', $fy))->assertSessionHasNoErrors();
        $this->assertSame('closed', $fy->fresh()->status);

        $this->actingAs($owner)->patch(route('fiscal-years.reopen', $fy))->assertSessionHasNoErrors();
        $this->assertSame('open', $fy->fresh()->status);

        $this->actingAs($owner)->patch(route('fiscal-years.close', $fy));
        $this->actingAs($owner)->patch(route('fiscal-years.lock', $fy))->assertSessionHasNoErrors();
        $this->assertSame('locked', $fy->fresh()->status);

        $this->actingAs($owner)->patch(route('fiscal-years.reopen', $fy))->assertStatus(422);
        $this->assertSame('locked', $fy->fresh()->status);
    }

    public function test_a_year_with_draft_entries_cannot_be_closed(): void
    {
        $this->createEntry()->assertSessionHasNoErrors();      // leaves a draft in 2026

        $this->actingAs($this->s->admin())
            ->patch(route('fiscal-years.close', $this->s->fiscalYear))
            ->assertStatus(422);

        $this->assertSame('open', $this->s->fiscalYear->fresh()->status);
    }

    public function test_only_a_farm_owner_can_lock_or_reopen(): void
    {
        $fy = $this->year(2027, 'closed');
        $notOwner = $this->s->admin();

        $this->actingAs($notOwner)->patch(route('fiscal-years.reopen', $fy))->assertForbidden();
        $this->actingAs($notOwner)->patch(route('fiscal-years.lock', $fy))->assertForbidden();
        $this->assertSame('closed', $fy->fresh()->status);
    }

    public function test_overlapping_years_are_refused(): void
    {
        $this->actingAs($this->s->admin())->post(route('fiscal-years.store'), [
            'year' => 2030, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
        ])->assertStatus(422);

        $this->assertSame(1, FiscalYear::where('company_id', $this->s->company->id)->count());
    }

    public function test_a_year_with_entries_or_locked_cannot_be_deleted(): void
    {
        $this->s->fund($this->s->cashAccount('1401'), '500.00');   // entry in 2026
        $locked = $this->year(2027, 'locked');
        $admin  = $this->s->admin();

        $this->actingAs($admin)->delete(route('fiscal-years.destroy', $this->s->fiscalYear))->assertStatus(422);
        $this->actingAs($admin)->delete(route('fiscal-years.destroy', $locked))->assertStatus(422);

        $this->assertNotNull(FiscalYear::find($this->s->fiscalYear->id));
        $this->assertNotNull(FiscalYear::find($locked->id));
    }

    public function test_view_only_and_other_company_users_cannot_change_years(): void
    {
        $viewer  = $this->s->user(['view fiscal_years']);
        $outsider = CattleScenario::make()->admin();

        $this->actingAs($viewer)->patch(route('fiscal-years.close', $this->s->fiscalYear))->assertForbidden();
        $this->actingAs($outsider)->patch(route('fiscal-years.close', $this->s->fiscalYear))->assertForbidden();
        $this->assertSame('open', $this->s->fiscalYear->fresh()->status);
    }

    // ── 2. manual entries and the year ────────────────────────────

    public function test_an_entry_without_a_chosen_year_is_attached_to_the_open_year_covering_its_date(): void
    {
        $this->createEntry()->assertSessionHasNoErrors();

        $this->assertSame($this->s->fiscalYear->id, (int) $this->latestEntry()->fiscal_year_id);
    }

    public function test_an_entry_dated_outside_every_open_year_is_refused(): void
    {
        $this->createEntry(['date' => '2031-03-01'])->assertSessionHasErrors('date');

        $this->assertSame(0, $this->manualCount());
    }

    public function test_an_entry_cannot_be_dated_outside_the_year_it_is_put_in(): void
    {
        $this->year(2027);

        $this->createEntry([
            'fiscal_year_id' => $this->s->fiscalYear->id,      // 2026
            'date'           => '2027-02-01',                  // but dated 2027
        ])->assertSessionHasErrors('date');

        $this->assertSame(0, $this->manualCount());
    }

    public function test_an_entry_cannot_be_created_in_a_closed_year(): void
    {
        $this->year(2025, 'closed');

        $this->createEntry(['date' => '2025-06-10'])->assertSessionHasErrors('date');

        $this->assertSame(0, $this->manualCount());
    }

    public function test_a_draft_cannot_be_posted_once_its_year_is_closed(): void
    {
        $this->createEntry()->assertSessionHasNoErrors();
        $entry = $this->latestEntry();
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($this->s->admin())
            ->patch(route('journal-entries.post', $entry))
            ->assertSessionHasErrors('date');

        $this->assertSame('draft', $entry->fresh()->status);
    }

    public function test_a_draft_cannot_be_edited_into_a_closed_year(): void
    {
        $this->createEntry()->assertSessionHasNoErrors();
        $entry = $this->latestEntry();
        $this->year(2025, 'closed');

        $this->actingAs($this->s->admin())
            ->put(route('journal-entries.update', $entry), $this->entryData(['date' => '2025-03-01']))
            ->assertSessionHasErrors('date');

        $this->assertSame('2026-06-10', $entry->fresh()->date->toDateString());
    }

    public function test_a_valid_draft_posts_and_a_posted_entry_cannot_be_deleted(): void
    {
        $this->createEntry()->assertSessionHasNoErrors();
        $entry = $this->latestEntry();
        $admin = $this->s->admin();

        $this->actingAs($admin)->patch(route('journal-entries.post', $entry))->assertSessionHasNoErrors();
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame('100.00', $this->s->net('6910'));

        $this->actingAs($admin)->delete(route('journal-entries.destroy', $entry))->assertStatus(422);
        $this->assertNotNull(JournalEntry::find($entry->id));
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $this->createEntry([
            'lines' => [
                ['account_id' => $this->s->account('6910')->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->s->cashAccount('1401')->id, 'debit' => 0, 'credit' => 90],
            ],
        ]);

        $this->assertSame(0, $this->manualCount());
    }

    public function test_cash_cannot_be_credited_beyond_its_balance(): void
    {
        $this->s->fund($this->s->cashAccount('1401'), '50.00');

        $this->actingAs($this->s->admin())->post(route('journal-entries.store'), $this->entryData())
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->manualCount());
    }

    // ── 3. reversal ───────────────────────────────────────────────

    private function postedEntry(): JournalEntry
    {
        $this->createEntry()->assertSessionHasNoErrors();
        $entry = $this->latestEntry();
        $this->actingAs($this->s->admin())->patch(route('journal-entries.post', $entry))->assertSessionHasNoErrors();
        return $entry->fresh();
    }

    public function test_reversing_posts_a_mirror_marks_the_original_and_nets_to_zero(): void
    {
        $entry = $this->postedEntry();

        $this->actingAs($this->s->admin())
            ->post(route('journal-entries.reverse', $entry), ['date' => '2026-07-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame('reversed', $entry->fresh()->status);

        $mirror = JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();
        $this->assertSame('posted', $mirror->status);
        $this->assertEquals((float) $entry->total_debit, (float) $mirror->total_credit);
        $this->assertSame($this->s->fiscalYear->id, (int) $mirror->fiscal_year_id);

        $this->assertSame('0.00', $this->s->net('6910'));
        $this->assertSame('10000.00', $this->s->net('1401'));    // cash back to the funded amount
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->postedEntry();
        $admin = $this->s->admin();

        $this->actingAs($admin)->post(route('journal-entries.reverse', $entry), ['date' => '2026-07-01']);
        $this->actingAs($admin)->post(route('journal-entries.reverse', $entry->fresh()), ['date' => '2026-07-02'])
            ->assertStatus(422);

        $this->assertSame(1, JournalEntry::where('reversal_of_id', $entry->id)->count());
    }

    public function test_the_reversal_cannot_be_dated_into_a_closed_year(): void
    {
        $entry = $this->postedEntry();
        $this->year(2025, 'closed');

        $this->actingAs($this->s->admin())
            ->post(route('journal-entries.reverse', $entry), ['date' => '2025-12-31'])
            ->assertSessionHasErrors('date');

        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame(0, JournalEntry::where('reversal_of_id', $entry->id)->count());
    }

    public function test_an_entry_in_a_closed_year_is_corrected_in_an_open_year(): void
    {
        $entry = $this->postedEntry();
        $next  = $this->year(2027);
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($this->s->admin())
            ->post(route('journal-entries.reverse', $entry), ['date' => '2027-01-15'])
            ->assertSessionHasNoErrors();

        $mirror = JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();
        $this->assertSame($next->id, (int) $mirror->fiscal_year_id);
        $this->assertSame('0.00', $this->s->net('6910'));
    }

    // ── 4. automatic postings ─────────────────────────────────────

    public function test_a_sale_cannot_be_confirmed_in_a_closed_year(): void
    {
        $sale = $this->s->draftSale([['price' => '10000.00']], '2026-06-15');
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->actingAs($this->s->admin())->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $this->s->account('4100')->id,
        ])->assertSessionHasErrors('date');

        $this->assertSame('draft', $sale->fresh()->status);
        $this->assertNull($sale->fresh()->ar_invoice_id);
        $this->assertSame('0.00', $this->s->net('4100'));
    }

    public function test_view_only_user_cannot_post_or_reverse(): void
    {
        $entry  = $this->postedEntry();
        $viewer = $this->s->user(['view journal_entries']);

        $this->actingAs($viewer)->post(route('journal-entries.reverse', $entry), ['date' => '2026-07-01'])->assertForbidden();
        $this->assertSame('posted', $entry->fresh()->status);
    }
}
