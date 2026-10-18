<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  FeedConsumptionTest
//
//  Feeding a lot, through the real route:
//    • stock goes down, cost = quantity x stock cost
//    • a balanced journal entry is posted: Dr Livestock WIP 1310 / Cr Feed 1320
//    • the chosen animals are recorded against the feeding
//    • guards: not enough stock, animals of another lot, other company,
//      view-only user
//    • a recorded feeding cannot be edited in quantity, only in notes
//    • delete is blocked once the journal entry is posted (stock stays)
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FeedConsumption;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class FeedConsumptionTest extends TestCase
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

    private function feed(array $stk, array $animalIds, string $qty, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('feed.consumption.batch'), array_merge([
            'farm_id'    => $this->s->farm->id,
            'lot_id'     => $this->s->lot->id,
            'date'       => '2026-06-10',
            'animal_ids' => $animalIds,
            'rows'       => [[
                'inventory_item_id' => $stk['master']->id,
                'location_id'       => $stk['location']->id,
                'qty_kg'            => $qty,
            ]],
        ], $extra));
    }

    public function test_feeding_reduces_stock_and_records_the_cost(): void
    {
        $stk = $this->s->stockedItem('feed', '1000', '10');
        $a1 = $this->s->animal();
        $a2 = $this->s->animal();

        $this->feed($stk, [$a1->id, $a2->id], '100')->assertSessionHasNoErrors();

        $this->assertEquals(900, (float) $stk['stock']->fresh()->qty_on_hand);

        $fc = FeedConsumption::firstOrFail();
        $this->assertEquals(2, $fc->head_count);
        $this->assertEquals(100, (float) $fc->qty_kg);
        $this->assertEquals(1000, (float) $fc->total_cost);   // 100 kg x 10
        $this->assertEquals(2, DB::table('feed_consumption_animals')->where('feed_consumption_id', $fc->id)->count());

        $this->assertEquals(1, InventoryTransaction::where('ref_type', 'feed_consumption')->where('ref_id', $fc->id)->count());
    }

    public function test_feeding_posts_a_balanced_entry_to_wip_and_feed_inventory(): void
    {
        $stk = $this->s->stockedItem('feed', '1000', '10.5');
        $a   = $this->s->animal();

        $this->feed($stk, [$a->id], '33')->assertSessionHasNoErrors();

        $fc = FeedConsumption::firstOrFail();
        $je = JournalEntry::findOrFail($fc->journal_entry_id);

        $this->assertSame('posted', $je->status);
        $this->assertEquals(346.5, (float) $je->total_debit);    // 33 x 10.5
        $this->assertEquals((float) $je->total_debit, (float) $je->total_credit);

        $this->assertSame('346.50', $this->s->net('1310'));       // WIP up
        $this->assertSame('-346.50', $this->s->net('1320'));      // Feed down (credit)
    }

    public function test_cost_is_rounded_to_two_decimals(): void
    {
        $stk = $this->s->stockedItem('feed', '1000', '3.3333');
        $a   = $this->s->animal();

        $this->feed($stk, [$a->id], '10')->assertSessionHasNoErrors();

        $this->assertEquals(33.33, (float) FeedConsumption::firstOrFail()->total_cost);
    }

    public function test_not_enough_stock_is_refused_and_nothing_changes(): void
    {
        $stk = $this->s->stockedItem('feed', '50', '10');
        $a   = $this->s->animal();

        $this->feed($stk, [$a->id], '80')->assertSessionHasErrors('rows.0.qty_kg');

        $this->assertEquals(50, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame(0, FeedConsumption::count());
        $this->assertSame(0, JournalEntry::where('entry_type', 'feed_consumption')->count());
    }

    public function test_animals_from_another_lot_are_refused(): void
    {
        $stk   = $this->s->stockedItem('feed');
        $other = \App\Models\Lot::factory()->create([
            'company_id' => $this->s->company->id,
            'farm_id'    => $this->s->farm->id,
        ]);
        $stray = $this->s->animal(['lot_id' => $other->id]);

        $this->feed($stk, [$stray->id], '10')->assertSessionHasErrors('animal_ids');

        $this->assertSame(0, FeedConsumption::count());
        $this->assertEquals(1000, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_sold_animals_cannot_be_fed(): void
    {
        $stk  = $this->s->stockedItem('feed');
        $sold = $this->s->animal(['status' => 'sold']);

        $this->feed($stk, [$sold->id], '10')->assertSessionHasErrors('animal_ids');
        $this->assertSame(0, FeedConsumption::count());
    }

    public function test_a_medicine_item_cannot_be_used_as_feed(): void
    {
        $med = $this->s->stockedItem('medicine');
        $a   = $this->s->animal();

        $this->feed($med, [$a->id], '1')->assertSessionHasErrors('rows.0.inventory_item_id');
        $this->assertSame(0, FeedConsumption::count());
    }

    public function test_view_only_user_cannot_record_feeding(): void
    {
        $stk = $this->s->stockedItem('feed');
        $a   = $this->s->animal();

        $this->feed($stk, [$a->id], '10', [], $this->s->user(['view feed']))->assertForbidden();

        $this->assertSame(0, FeedConsumption::count());
        $this->assertEquals(1000, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_another_companys_user_cannot_feed_this_lot(): void
    {
        $stk = $this->s->stockedItem('feed');
        $a   = $this->s->animal();

        $otherScenario = CattleScenario::make();
        $outsider      = $otherScenario->admin();

        $this->feed($stk, [$a->id], '10', [], $outsider)->assertForbidden();

        $this->assertSame(0, FeedConsumption::count());
        $this->assertEquals(1000, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_only_notes_can_be_edited_after_recording(): void
    {
        $stk = $this->s->stockedItem('feed');
        $a   = $this->s->animal();
        $this->feed($stk, [$a->id], '100');
        $fc = FeedConsumption::firstOrFail();

        $admin = $this->s->admin();

        $this->actingAs($admin)->put(route('feed.consumption.update', $fc), [
            'qty_kg' => 500,
        ])->assertSessionHasErrors();
        $this->assertEquals(100, (float) $fc->fresh()->qty_kg);

        $this->actingAs($admin)->put(route('feed.consumption.update', $fc), [
            'notes' => 'Morning feed',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Morning feed', $fc->fresh()->notes);
        $this->assertEquals(100, (float) $fc->fresh()->qty_kg);
    }

    public function test_a_posted_feeding_cannot_be_deleted_and_stock_is_untouched(): void
    {
        $stk = $this->s->stockedItem('feed', '1000', '10');
        $a   = $this->s->animal();
        $this->feed($stk, [$a->id], '100');
        $fc = FeedConsumption::firstOrFail();

        $this->actingAs($this->s->admin())
            ->delete(route('feed.consumption.destroy', $fc))
            ->assertSessionHasErrors('delete');

        $this->assertNotNull(FeedConsumption::find($fc->id));
        $this->assertEquals(900, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame('posted', JournalEntry::find($fc->journal_entry_id)->status);
    }

    public function test_user_without_delete_permission_is_refused(): void
    {
        $stk = $this->s->stockedItem('feed');
        $a   = $this->s->animal();
        $this->feed($stk, [$a->id], '10');
        $fc = FeedConsumption::firstOrFail();

        $this->actingAs($this->s->user(['view feed', 'edit feed']))
            ->delete(route('feed.consumption.destroy', $fc))
            ->assertForbidden();

        $this->assertNotNull(FeedConsumption::find($fc->id));
    }
}
