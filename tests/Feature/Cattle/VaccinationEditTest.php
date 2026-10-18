<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  VaccinationEditTest
//
//  A vaccination that used stock and posted an accounting entry must
//  not have its date / dose / cost changed afterwards (stock and the
//  entry depend on them). Notes, batch number and next-due date can
//  still be corrected. Guards the "edit button did nothing" bug.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\VaccinationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class VaccinationEditTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;
    private VaccinationRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s = CattleScenario::make();

        $item = InventoryItem::factory()->create([
            'company_id' => $this->s->company->id,
            'category'   => 'vaccine',
        ]);
        $location = InventoryLocation::factory()->create(['company_id' => $this->s->company->id]);
        $animal   = $this->s->animal();

        $this->record = VaccinationRecord::create([
            'company_id'        => $this->s->company->id,
            'farm_id'           => $this->s->farm->id,
            'animal_id'         => $animal->id,
            'lot_id'            => $this->s->lot->id,
            'inventory_item_id' => $item->id,
            'location_id'       => $location->id,
            'date'              => '2026-03-01',
            'dose_given_ml'     => 5,
            'cost'              => 25.00,
        ]);
    }

    private function submit(array $data, ?array $permissions = null)
    {
        $user = $permissions === null ? $this->s->admin() : $this->s->user($permissions);
        return $this->actingAs($user)->put(route('vaccinations.update', $this->record->id), $data);
    }

    public function test_notes_batch_and_next_due_date_can_be_corrected(): void
    {
        $this->submit([
            'notes'         => 'Booster needed',
            'batch_number'  => 'B-77',
            'next_due_date' => '2026-04-01',
        ])->assertSessionHasNoErrors();

        $r = $this->record->fresh();
        $this->assertSame('Booster needed', $r->notes);
        $this->assertSame('B-77', $r->batch_number);
        $this->assertSame('2026-04-01', $r->next_due_date->toDateString());
    }

    public function test_dose_cannot_be_changed_after_stock_was_used(): void
    {
        $this->submit(['dose_given_ml' => 9])->assertSessionHasErrors('dose_given_ml');

        $this->assertSame('5.00', number_format((float) $this->record->fresh()->dose_given_ml, 2, '.', ''));
    }

    public function test_cost_and_date_cannot_be_changed_after_stock_was_used(): void
    {
        $this->submit(['cost' => 99.00])->assertSessionHasErrors('cost');
        $this->submit(['date' => '2026-03-05'])->assertSessionHasErrors('date');

        $r = $this->record->fresh();
        $this->assertSame('25.00', number_format((float) $r->cost, 2, '.', ''));
        $this->assertSame('2026-03-01', $r->date->toDateString());
    }

    public function test_resending_the_same_values_is_allowed(): void
    {
        $this->submit([
            'date'          => '2026-03-01',
            'dose_given_ml' => 5,
            'cost'          => 25.00,
            'notes'         => 'same values',
        ])->assertSessionHasNoErrors();

        $this->assertSame('same values', $this->record->fresh()->notes);
    }

    public function test_next_due_date_must_be_after_the_vaccination_date(): void
    {
        $this->submit(['next_due_date' => '2026-02-01'])->assertSessionHasErrors('next_due_date');
    }

    public function test_user_without_edit_permission_is_forbidden(): void
    {
        $this->submit(['notes' => 'x'], ['view vaccinations'])->assertForbidden();

        $this->assertNull($this->record->fresh()->notes);
    }

    public function test_another_companys_user_cannot_edit_it(): void
    {
        $other = CattleScenario::make();

        $this->actingAs($other->admin())
            ->put(route('vaccinations.update', $this->record->id), ['notes' => 'hack'])
            ->assertForbidden();

        $this->assertNull($this->record->fresh()->notes);
    }
}
