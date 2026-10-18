<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  HealthAndVaccinationTest
//
//  Treating and vaccinating animals, through the real routes:
//    • medicine / vaccine stock goes down, cost = quantity x stock cost
//    • a balanced journal entry is posted (Dr WIP 1310 / Cr 1330 or 1340)
//    • not enough stock is refused and nothing changes
//    • deleting a treatment gives the stock back and reverses the entry
//    • a posted vaccination cannot be deleted one animal at a time
//    • view-only users and other companies are refused
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\HealthRecord;
use App\Models\JournalEntry;
use App\Models\VaccinationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class HealthAndVaccinationTest extends TestCase
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

    // ── helpers ───────────────────────────────────────────────────

    private function treat(array $stk, $animal, string $dose, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('health.store'), array_merge([
            'animal_id'         => $animal->id,
            'farm_id'           => $this->s->farm->id,
            'lot_id'            => $this->s->lot->id,
            'inventory_item_id' => $stk['master']->id,
            'location_id'       => $stk['location']->id,
            'type'              => 'treatment',
            'date'              => '2026-06-10',
            'dose'              => $dose,
        ], $extra));
    }

    private function vaccinate(array $stk, array $animalIds, string $dose, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('vaccinations.batch'), array_merge([
            'farm_id'           => $this->s->farm->id,
            'lot_id'            => $this->s->lot->id,
            'inventory_item_id' => $stk['master']->id,
            'location_id'       => $stk['location']->id,
            'date'              => '2026-06-10',
            'animal_ids'        => $animalIds,
            'dose_given_ml'     => $dose,
        ], $extra));
    }

    // ── Health ────────────────────────────────────────────────────

    public function test_treatment_reduces_medicine_stock_and_records_the_cost(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();

        $this->treat($stk, $a, '2')->assertSessionHasNoErrors();

        $this->assertEquals(98, (float) $stk['stock']->fresh()->qty_on_hand);

        $hr = HealthRecord::firstOrFail();
        $this->assertEquals(50, (float) $hr->cost);               // 2 x 25
        $this->assertEquals($a->id, $hr->animal_id);
        $this->assertEquals($stk['location']->id, $hr->location_id);
    }

    public function test_treatment_posts_a_balanced_entry_to_wip_and_medicine_inventory(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();

        $this->treat($stk, $a, '2')->assertSessionHasNoErrors();

        $je = JournalEntry::findOrFail(HealthRecord::firstOrFail()->journal_entry_id);
        $this->assertSame('posted', $je->status);
        $this->assertEquals((float) $je->total_debit, (float) $je->total_credit);

        $this->assertSame('50.00', $this->s->net('1310'));
        $this->assertSame('-50.00', $this->s->net('1330'));
    }

    public function test_treatment_cost_comes_from_stock_not_from_what_the_user_sends(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();

        $this->treat($stk, $a, '2', ['cost' => 1])->assertSessionHasNoErrors();

        $this->assertEquals(50, (float) HealthRecord::firstOrFail()->cost);
    }

    public function test_not_enough_medicine_is_refused_and_nothing_changes(): void
    {
        $stk = $this->s->stockedItem('medicine', '1', '25');
        $a   = $this->s->animal();

        $this->treat($stk, $a, '5')->assertSessionHasErrors('dose');

        $this->assertEquals(1, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame(0, HealthRecord::count());
        $this->assertSame(0, JournalEntry::where('entry_type', 'health')->count());
    }

    public function test_changing_the_dose_adjusts_the_stock(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();
        $this->treat($stk, $a, '2');
        $hr = HealthRecord::firstOrFail();

        $this->actingAs($this->s->admin())->put(route('health.update', $hr), [
            'animal_id'         => $a->id,
            'farm_id'           => $this->s->farm->id,
            'lot_id'            => $this->s->lot->id,
            'inventory_item_id' => $stk['master']->id,
            'location_id'       => $stk['location']->id,
            'type'              => 'treatment',
            'date'              => '2026-06-10',
            'dose'              => '5',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(95, (float) $stk['stock']->fresh()->qty_on_hand);   // 100 - 5
        $this->assertEquals(125, (float) $hr->fresh()->cost);                   // 5 x 25
    }

    public function test_deleting_a_treatment_gives_stock_back_and_reverses_the_entry(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();
        $this->treat($stk, $a, '2');
        $hr = HealthRecord::firstOrFail();

        $this->actingAs($this->s->admin())->delete(route('health.destroy', $hr))->assertSessionHasNoErrors();

        $this->assertEquals(100, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSoftDeleted('health_records', ['id' => $hr->id]);
        $this->assertSame('0.00', $this->s->net('1310'));     // original + reversal cancel out
        $this->assertSame('0.00', $this->s->net('1330'));
    }

    public function test_a_vaccine_cannot_be_used_as_medicine(): void
    {
        $vac = $this->s->stockedItem('vaccine', '100', '5');
        $a   = $this->s->animal();

        $this->treat($vac, $a, '1')->assertSessionHasErrors('inventory_item_id');
        $this->assertSame(0, HealthRecord::count());
        $this->assertEquals(100, (float) $vac['stock']->fresh()->qty_on_hand);
    }

    public function test_view_only_user_cannot_record_treatment(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();

        $this->treat($stk, $a, '2', [], $this->s->user(['view health']))->assertForbidden();

        $this->assertSame(0, HealthRecord::count());
        $this->assertEquals(100, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_another_companys_user_cannot_treat_this_animal(): void
    {
        $stk = $this->s->stockedItem('medicine', '100', '25');
        $a   = $this->s->animal();
        $outsider = CattleScenario::make()->admin();

        $this->treat($stk, $a, '2', [], $outsider)->assertForbidden();

        $this->assertSame(0, HealthRecord::count());
        $this->assertEquals(100, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    // ── Vaccination ───────────────────────────────────────────────

    public function test_vaccinating_a_group_reduces_stock_and_creates_one_record_per_animal(): void
    {
        $stk = $this->s->stockedItem('vaccine', '100', '4');
        $a1 = $this->s->animal();
        $a2 = $this->s->animal();
        $a3 = $this->s->animal();

        $this->vaccinate($stk, [$a1->id, $a2->id, $a3->id], '2')->assertSessionHasNoErrors();

        $this->assertEquals(94, (float) $stk['stock']->fresh()->qty_on_hand);   // 3 animals x 2
        $this->assertSame(3, VaccinationRecord::count());

        foreach (VaccinationRecord::all() as $rec) {
            $this->assertEquals(8, (float) $rec->cost);                          // 2 x 4 each
            $this->assertEquals($this->s->lot->id, $rec->lot_id);
        }
    }

    public function test_vaccination_posts_one_balanced_entry_for_the_whole_group(): void
    {
        $stk = $this->s->stockedItem('vaccine', '100', '4');
        $a1 = $this->s->animal();
        $a2 = $this->s->animal();

        $this->vaccinate($stk, [$a1->id, $a2->id], '2')->assertSessionHasNoErrors();

        $jeIds = VaccinationRecord::pluck('journal_entry_id')->unique();
        $this->assertCount(1, $jeIds);

        $je = JournalEntry::findOrFail($jeIds->first());
        $this->assertSame('posted', $je->status);
        $this->assertEquals((float) $je->total_debit, (float) $je->total_credit);

        $this->assertSame('16.00', $this->s->net('1310'));     // 2 animals x 8
        $this->assertSame('-16.00', $this->s->net('1340'));
    }

    public function test_not_enough_vaccine_is_refused_and_nothing_changes(): void
    {
        $stk = $this->s->stockedItem('vaccine', '3', '4');
        $a1 = $this->s->animal();
        $a2 = $this->s->animal();

        $this->vaccinate($stk, [$a1->id, $a2->id], '2')->assertSessionHasErrors('dose_given_ml');  // needs 4, has 3

        $this->assertEquals(3, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame(0, VaccinationRecord::count());
        $this->assertSame(0, JournalEntry::where('entry_type', 'health')->count());
    }

    public function test_a_posted_vaccination_cannot_be_deleted_one_animal_at_a_time(): void
    {
        $stk = $this->s->stockedItem('vaccine', '100', '4');
        $a1 = $this->s->animal();
        $this->vaccinate($stk, [$a1->id], '2');
        $rec = VaccinationRecord::firstOrFail();

        $this->actingAs($this->s->admin())
            ->delete(route('vaccinations.destroy', $rec))
            ->assertSessionHasErrors('delete');

        $this->assertNotNull(VaccinationRecord::find($rec->id));
        $this->assertEquals(98, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_view_only_user_cannot_vaccinate(): void
    {
        $stk = $this->s->stockedItem('vaccine', '100', '4');
        $a1 = $this->s->animal();

        $this->vaccinate($stk, [$a1->id], '2', [], $this->s->user(['view vaccinations']))->assertForbidden();

        $this->assertSame(0, VaccinationRecord::count());
    }

    public function test_another_companys_user_cannot_vaccinate_this_lot(): void
    {
        $stk = $this->s->stockedItem('vaccine', '100', '4');
        $a1 = $this->s->animal();
        $outsider = CattleScenario::make()->admin();

        $this->vaccinate($stk, [$a1->id], '2', [], $outsider)->assertForbidden();

        $this->assertSame(0, VaccinationRecord::count());
        $this->assertEquals(100, (float) $stk['stock']->fresh()->qty_on_hand);
    }
}
