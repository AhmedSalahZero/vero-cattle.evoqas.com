<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  WeightRecordTest
//
//  Weighing animals, through the real routes:
//    • gain, days and daily gain (ADG) are worked out between weigh-ins
//    • they are re-worked when a weight is edited, added or deleted
//    • sold / slaughtered animals cannot be weighed, edited or deleted
//    • view-only users and other companies are refused
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\WeightRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class WeightRecordTest extends TestCase
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

    private function weigh($animal, string $date, string $kg, $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('weights.store'), [
            'animal_id' => $animal->id,
            'lot_id'    => $this->s->lot->id,
            'date'      => $date,
            'weight_kg' => $kg,
        ]);
    }

    private function rec($animal, string $date): WeightRecord
    {
        return WeightRecord::where('animal_id', $animal->id)->whereDate('date', $date)->firstOrFail();
    }

    public function test_gain_days_and_adg_are_worked_out_between_weighings(): void
    {
        $a = $this->s->animal();

        $this->weigh($a, '2026-01-01', '320')->assertSessionHasNoErrors();
        $this->weigh($a, '2026-02-01', '485')->assertSessionHasNoErrors();

        $first = $this->rec($a, '2026-01-01');
        $this->assertEquals(165, (float) $first->total_gain_kg);     // 485 - 320
        $this->assertEquals(31, (int) $first->days_on_feed);         // Jan 1 -> Feb 1
        $this->assertEquals(5.323, round((float) $first->adg_kg, 3)); // 165 / 31
    }

    public function test_the_latest_weighing_counts_days_until_today(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '320');
        $this->weigh($a, '2026-02-01', '485');

        $last = $this->rec($a, '2026-02-01');
        $expected = (int) \Carbon\Carbon::parse('2026-02-01')->diffInDays(now()->startOfDay());
        $this->assertSame($expected, (int) $last->days_on_feed);
    }

    public function test_adding_a_weighing_in_between_reworks_the_numbers(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '300');
        $this->weigh($a, '2026-03-01', '400');
        $this->weigh($a, '2026-02-01', '350');     // slipped in between

        $this->assertEquals(50, (float) $this->rec($a, '2026-01-01')->total_gain_kg);   // 350 - 300
        $this->assertEquals(31, (int) $this->rec($a, '2026-01-01')->days_on_feed);
        $this->assertEquals(50, (float) $this->rec($a, '2026-02-01')->total_gain_kg);   // 400 - 350
        $this->assertEquals(28, (int) $this->rec($a, '2026-02-01')->days_on_feed);
    }

    public function test_editing_a_weight_reworks_the_numbers(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '300');
        $this->weigh($a, '2026-02-01', '400');
        $second = $this->rec($a, '2026-02-01');

        $this->actingAs($this->s->admin())->patch(route('weights.update', $second), [
            'date'      => '2026-02-01',
            'weight_kg' => '430',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(130, (float) $this->rec($a, '2026-01-01')->total_gain_kg);
    }

    public function test_deleting_a_weight_reworks_the_numbers(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '300');
        $this->weigh($a, '2026-02-01', '400');
        $this->weigh($a, '2026-03-01', '460');

        $this->actingAs($this->s->admin())
            ->delete(route('weights.destroy', $this->rec($a, '2026-02-01')))
            ->assertSessionHasNoErrors();

        $first = $this->rec($a, '2026-01-01');
        $this->assertEquals(160, (float) $first->total_gain_kg);   // 460 - 300
        $this->assertEquals(59, (int) $first->days_on_feed);       // Jan 1 -> Mar 1
    }

    public function test_batch_weighing_records_every_animal_and_skips_sold_ones(): void
    {
        $a1   = $this->s->animal();
        $a2   = $this->s->animal();
        $sold = $this->s->animal(['status' => 'sold']);

        $this->actingAs($this->s->admin())->post(route('weights.batch'), [
            'lot_id'   => $this->s->lot->id,
            'date'     => '2026-06-01',
            'readings' => [
                ['animal_id' => $a1->id,   'weight_kg' => 400],
                ['animal_id' => $a2->id,   'weight_kg' => 410],
                ['animal_id' => $sold->id, 'weight_kg' => 420],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, WeightRecord::where('animal_id', $a1->id)->count());
        $this->assertSame(1, WeightRecord::where('animal_id', $a2->id)->count());
        $this->assertSame(0, WeightRecord::where('animal_id', $sold->id)->count());
    }

    public function test_sold_animals_cannot_be_weighed(): void
    {
        $sold = $this->s->animal(['status' => 'sold']);

        $this->weigh($sold, '2026-06-01', '400')->assertStatus(422);
        $this->assertSame(0, WeightRecord::count());
    }

    public function test_weights_of_a_sold_animal_cannot_be_edited_or_deleted(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '300');
        $rec = $this->rec($a, '2026-01-01');
        $a->update(['status' => 'sold']);

        $admin = $this->s->admin();

        $this->actingAs($admin)->patch(route('weights.update', $rec), [
            'date' => '2026-01-01', 'weight_kg' => '999',
        ])->assertStatus(422);
        $this->assertEquals(300, (float) $rec->fresh()->weight_kg);

        $this->actingAs($admin)->delete(route('weights.destroy', $rec))->assertStatus(422);
        $this->assertNotNull(WeightRecord::find($rec->id));
    }

    public function test_an_impossible_weight_is_refused(): void
    {
        $a = $this->s->animal();

        $this->weigh($a, '2026-06-01', '5000')->assertSessionHasErrors('weight_kg');
        $this->weigh($a, '2026-06-01', '-5')->assertSessionHasErrors('weight_kg');
        $this->assertSame(0, WeightRecord::count());
    }

    public function test_view_only_user_cannot_weigh_edit_or_delete(): void
    {
        $a = $this->s->animal();
        $this->weigh($a, '2026-01-01', '300');
        $rec = $this->rec($a, '2026-01-01');
        $viewer = $this->s->user(['view weight']);

        $this->weigh($a, '2026-02-01', '350', $viewer)->assertForbidden();
        $this->actingAs($viewer)->patch(route('weights.update', $rec), [
            'date' => '2026-01-01', 'weight_kg' => '1',
        ])->assertForbidden();
        $this->actingAs($viewer)->delete(route('weights.destroy', $rec))->assertForbidden();

        $this->assertSame(1, WeightRecord::count());
        $this->assertEquals(300, (float) $rec->fresh()->weight_kg);
    }

    public function test_another_companys_user_cannot_weigh_this_animal(): void
    {
        $a = $this->s->animal();
        $outsider = CattleScenario::make()->admin();

        $this->weigh($a, '2026-01-01', '300', $outsider)->assertForbidden();
        $this->assertSame(0, WeightRecord::count());
    }
}
