<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  AnimalLotHistoryTest
//
//  Every animal on the farm must have one OPEN lot-history row; the
//  monthly labour/overhead accrual counts animal-days from it.
//    • receiving purchased animals opens a row on the receipt date
//    • confirming a sale closes it on the sale date ('sold')
//    • cancelling the sale re-opens it
//    • moving animals to another lot closes the old row and opens a new one
//    • the service is safe to call twice (no duplicate open rows)
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AnimalLotHistory;
use App\Models\Lot;
use App\Services\AnimalLotHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class AnimalLotHistoryTest extends TestCase
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

    private function open(int $animalId)
    {
        return AnimalLotHistory::where('animal_id', $animalId)->whereNull('exited_at');
    }

    public function test_receiving_animals_opens_a_history_row_on_the_receipt_date(): void
    {
        $admin = $this->s->admin();
        $po    = $this->s->draftCattlePo($this->s->supplier());
        $this->actingAs($admin)->patch(route('procurement.confirm-po', $po->id))->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('procurement.confirm-receipt', $po->id), [
            'animals'      => [
                ['tag' => 'LH-1', 'sex' => 'male', 'weight_kg' => 300],
                ['tag' => 'LH-2', 'sex' => 'male', 'weight_kg' => 310],
            ],
            'batch_date'   => '2026-06-10',
            'receipt_mode' => 'individual',
        ])->assertSessionHasNoErrors();

        $animals = \App\Models\Animal::whereIn('tag', ['LH-1', 'LH-2'])->get();
        $this->assertCount(2, $animals);
        foreach ($animals as $a) {
            $row = $this->open($a->id)->get();
            $this->assertCount(1, $row);
            $this->assertSame('2026-06-10', $row[0]->entered_at->toDateString());
            $this->assertSame((int) $a->lot_id, (int) $row[0]->lot_id);
        }
    }

    public function test_confirming_a_sale_closes_the_row_and_cancelling_reopens_it(): void
    {
        $admin  = $this->s->admin();
        $animal = $this->s->animal();
        app(AnimalLotHistoryService::class)->open($animal, '2026-01-10');

        $sale = $this->s->draftSale([['animal' => $animal, 'price' => '50000.00', 'cogs' => '40000.00']], '2026-06-15');

        $this->actingAs($admin)->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $this->s->account('4100')->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->open($animal->id)->count());
        $closed = AnimalLotHistory::where('animal_id', $animal->id)->first();
        $this->assertSame('2026-06-15', $closed->exited_at->toDateString());
        $this->assertSame('sold', $closed->exit_reason);

        $this->actingAs($admin)->post(route('sales.cancel', $sale->id))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->open($animal->id)->count());
        $this->assertSame(1, AnimalLotHistory::where('animal_id', $animal->id)->count());
    }

    public function test_moving_to_another_lot_closes_the_old_row_and_opens_a_new_one(): void
    {
        $animal = $this->s->animal();
        $svc    = app(AnimalLotHistoryService::class);
        $svc->open($animal, '2026-01-10');

        $lot2 = Lot::factory()->create(['company_id' => $this->s->company->id, 'farm_id' => $this->s->farm->id]);
        $animal->update(['lot_id' => $lot2->id]);
        $svc->move([$animal->id], $lot2->id, '2026-03-01');

        $rows = AnimalLotHistory::where('animal_id', $animal->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-03-01', $rows[0]->exited_at->toDateString());
        $this->assertSame('transferred', $rows[0]->exit_reason);
        $this->assertNull($rows[1]->exited_at);
        $this->assertSame((int) $lot2->id, (int) $rows[1]->lot_id);
    }

    public function test_opening_twice_does_not_create_duplicate_open_rows(): void
    {
        $animal = $this->s->animal();
        $svc    = app(AnimalLotHistoryService::class);
        $svc->open($animal, '2026-01-10');
        $svc->open($animal, '2026-02-10');

        $this->assertSame(1, AnimalLotHistory::where('animal_id', $animal->id)->count());
    }

    public function test_exit_date_is_never_before_the_entry_date(): void
    {
        $animal = $this->s->animal();
        $svc    = app(AnimalLotHistoryService::class);
        $svc->open($animal, '2026-05-10');
        $svc->close([$animal->id], '2026-05-01', 'dead');

        $this->assertSame('2026-05-10', AnimalLotHistory::where('animal_id', $animal->id)->first()->exited_at->toDateString());
    }
}
