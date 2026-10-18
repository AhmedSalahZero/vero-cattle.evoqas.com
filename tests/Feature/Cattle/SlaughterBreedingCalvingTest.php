<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  SlaughterBreedingCalvingTest
//
//  Slaughter: only an active animal, dressing %, animal status follows
//  the record and returns on delete.
//  Breeding: dam female / sire male of the same company, due date = +283 days.
//  Calving: links only to this dam's own breeding record and company's calf;
//  marks the breeding record "calved".
//  All three: view-only and other-company users are refused.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\BreedingRecord;
use App\Models\CalvingRecord;
use App\Models\SlaughterRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class SlaughterBreedingCalvingTest extends TestCase
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

    // ── Slaughter ─────────────────────────────────────────────────

    private function slaughter($animal, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('slaughter.store'), array_merge([
            'animal_id'      => $animal->id,
            'farm_id'        => $this->s->farm->id,
            'date'           => '2026-06-10',
            'liveweight_kg'  => 500,
            'deadweight_kg'  => 300,
            'slaughter_cost' => 150,
        ], $extra));
    }

    public function test_slaughtering_records_it_works_out_dressing_percentage_and_updates_the_animal(): void
    {
        $a = $this->s->animal();

        $this->slaughter($a)->assertSessionHasNoErrors();

        $rec = SlaughterRecord::firstOrFail();
        $this->assertEquals(60, (float) $rec->dressing_pct);                 // 300 / 500
        $this->assertSame('slaughtered', $a->fresh()->status);
    }

    public function test_dead_weight_must_be_below_live_weight(): void
    {
        $a = $this->s->animal();

        $this->slaughter($a, ['deadweight_kg' => 500])->assertStatus(422);

        $this->assertSame(0, SlaughterRecord::count());
        $this->assertSame('active', $a->fresh()->status);
    }

    public function test_the_slaughter_cost_is_required(): void
    {
        $a = $this->s->animal();

        $this->slaughter($a, ['slaughter_cost' => null])->assertSessionHasErrors('slaughter_cost');
        $this->assertSame(0, SlaughterRecord::count());
    }

    public function test_an_animal_that_is_sold_slaughtered_or_reserved_cannot_be_slaughtered(): void
    {
        foreach (['sold', 'slaughtered', 'reserved'] as $status) {
            $a = $this->s->animal(['status' => $status]);
            $this->slaughter($a)->assertSessionHasErrors('animal_id');
            $this->assertSame($status, $a->fresh()->status);
        }

        $this->assertSame(0, SlaughterRecord::count());
    }

    public function test_an_animal_cannot_be_slaughtered_twice(): void
    {
        $a = $this->s->animal();
        $this->slaughter($a)->assertSessionHasNoErrors();

        $this->slaughter($a)->assertSessionHasErrors('animal_id');

        $this->assertSame(1, SlaughterRecord::count());
    }

    public function test_farm_and_lot_come_from_the_animal(): void
    {
        $a     = $this->s->animal();
        $other = \App\Models\Farm::factory()->create(['company_id' => $this->s->company->id]);

        $this->slaughter($a, ['farm_id' => $other->id])->assertSessionHasNoErrors();

        $rec = SlaughterRecord::firstOrFail();
        $this->assertSame($this->s->farm->id, (int) $rec->farm_id);
        $this->assertSame($this->s->lot->id, (int) $rec->lot_id);
    }

    public function test_deleting_a_slaughter_record_brings_the_animal_back(): void
    {
        $a = $this->s->animal();
        $this->slaughter($a);
        $rec = SlaughterRecord::firstOrFail();

        $this->actingAs($this->s->admin())->delete(route('slaughter.destroy', $rec))->assertSessionHasNoErrors();

        $this->assertSame('active', $a->fresh()->status);
    }

    public function test_slaughter_is_refused_for_view_only_and_other_company_users(): void
    {
        $a = $this->s->animal();

        $this->slaughter($a, [], $this->s->user(['view slaughter']))->assertForbidden();
        $this->slaughter($a, [], CattleScenario::make()->admin())->assertForbidden();

        $this->assertSame(0, SlaughterRecord::count());
        $this->assertSame('active', $a->fresh()->status);
    }

    // ── Breeding ──────────────────────────────────────────────────

    private function cow(array $o = [])  { return $this->s->animal(array_merge(['sex' => 'female'], $o)); }
    private function bull(array $o = []) { return $this->s->animal(array_merge(['sex' => 'male'], $o)); }

    private function breed($dam, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('breeding.store'), array_merge([
            'dam_id'        => $dam->id,
            'farm_id'       => $this->s->farm->id,
            'method'        => 'natural',
            'breeding_date' => '2026-01-01',
        ], $extra));
    }

    public function test_breeding_sets_the_expected_calving_date_283_days_later(): void
    {
        $dam = $this->cow();
        $sire = $this->bull();

        $this->breed($dam, ['sire_id' => $sire->id])->assertSessionHasNoErrors();

        $rec = BreedingRecord::firstOrFail();
        $this->assertSame('2026-10-11', $rec->expected_calving_date->toDateString());   // 1 Jan + 283 days
        $this->assertSame($this->s->company->id, (int) $rec->company_id);
    }

    public function test_changing_the_breeding_date_moves_the_due_date(): void
    {
        $dam = $this->cow();
        $this->breed($dam);
        $rec = BreedingRecord::firstOrFail();

        $this->actingAs($this->s->admin())->put(route('breeding.update', $rec), [
            'dam_id' => $dam->id, 'farm_id' => $this->s->farm->id, 'method' => 'ai', 'breeding_date' => '2026-02-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-11', $rec->fresh()->expected_calving_date->toDateString());
    }

    public function test_the_dam_must_be_female_and_the_sire_male(): void
    {
        $this->breed($this->bull())->assertSessionHasErrors('dam_id');
        $this->breed($this->cow(), ['sire_id' => $this->cow()->id])->assertSessionHasErrors('sire_id');

        $this->assertSame(0, BreedingRecord::count());
    }

    public function test_a_sire_from_another_company_is_refused(): void
    {
        $theirs = CattleScenario::make();
        $theirBull = $theirs->animal(['sex' => 'male']);

        $this->breed($this->cow(), ['sire_id' => $theirBull->id])->assertSessionHasErrors('sire_id');

        $this->assertSame(0, BreedingRecord::count());
    }

    public function test_the_confirmation_date_must_follow_the_breeding_date(): void
    {
        $this->breed($this->cow(), ['confirmation_date' => '2025-12-01'])->assertSessionHasErrors('confirmation_date');
    }

    public function test_breeding_is_refused_for_view_only_and_other_company_users(): void
    {
        $dam = $this->cow();

        $this->breed($dam, [], $this->s->user(['view breeding']))->assertForbidden();
        $this->breed($dam, [], CattleScenario::make()->admin())->assertForbidden();

        $this->assertSame(0, BreedingRecord::count());
    }

    // ── Calving ───────────────────────────────────────────────────

    private function calve($dam, array $extra = [], $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('calvings.store'), array_merge([
            'dam_id'       => $dam->id,
            'farm_id'      => $this->s->farm->id,
            'calving_date' => '2026-10-10',
            'condition'    => 'healthy',
        ], $extra));
    }

    public function test_calving_is_recorded_and_closes_the_breeding_record(): void
    {
        $dam = $this->cow();
        $this->breed($dam);
        $breeding = BreedingRecord::firstOrFail();
        $calf = $this->s->animal();

        $this->calve($dam, ['breeding_record_id' => $breeding->id, 'calf_id' => $calf->id, 'birth_weight_kg' => 35])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CalvingRecord::count());
        $this->assertSame('calved', $breeding->fresh()->pregnancy_status);
    }

    public function test_a_breeding_record_of_another_dam_or_company_cannot_be_linked(): void
    {
        $dam      = $this->cow();
        $otherDam = $this->cow();
        $this->breed($otherDam);
        $theirs = CattleScenario::make();
        $theirDam = $theirs->animal(['sex' => 'female']);
        $this->actingAs($theirs->admin())->post(route('breeding.store'), [
            'dam_id' => $theirDam->id, 'farm_id' => $theirs->farm->id, 'method' => 'natural', 'breeding_date' => '2026-01-01',
        ]);

        foreach (BreedingRecord::all() as $b) {
            $this->calve($dam, ['breeding_record_id' => $b->id])->assertSessionHasErrors('breeding_record_id');
        }

        $this->assertSame(0, CalvingRecord::count());
        $this->assertSame(0, BreedingRecord::where('pregnancy_status', 'calved')->count());
    }

    public function test_a_calf_of_another_company_cannot_be_linked(): void
    {
        $dam = $this->cow();
        $theirCalf = CattleScenario::make()->animal();

        $this->calve($dam, ['calf_id' => $theirCalf->id])->assertSessionHasErrors('calf_id');

        $this->assertSame(0, CalvingRecord::count());
    }

    public function test_the_calving_condition_must_be_a_known_value(): void
    {
        $this->calve($this->cow(), ['condition' => 'great'])->assertSessionHasErrors('condition');
        $this->assertSame(0, CalvingRecord::count());
    }

    public function test_calving_can_be_deleted_by_an_admin_only(): void
    {
        $dam = $this->cow();
        $this->calve($dam);
        $rec = CalvingRecord::firstOrFail();

        $this->actingAs($this->s->user(['view calvings']))->delete(route('calvings.destroy', $rec))->assertForbidden();
        $this->assertNotNull(CalvingRecord::find($rec->id));

        $this->actingAs($this->s->admin())->delete(route('calvings.destroy', $rec))->assertSessionHasNoErrors();
        $this->assertNull(CalvingRecord::find($rec->id));
    }

    public function test_calving_is_refused_for_view_only_and_other_company_users(): void
    {
        $dam = $this->cow();

        $this->calve($dam, [], $this->s->user(['view calvings']))->assertForbidden();
        $this->calve($dam, [], CattleScenario::make()->admin())->assertForbidden();

        $this->assertSame(0, CalvingRecord::count());
    }
}
