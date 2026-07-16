<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\Animal;
use App\Models\Company;
use App\Models\Farm;
use App\Models\FiscalYear;
use App\Models\Lot;
use App\Models\ProcurementRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WeightRecord;
use App\Services\LivestockReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — LivestockReceiptService Feature Tests
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//  📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
//
//  Covers the Step 4b fix: arrival weight is automatically written
//  to weight_records when animals are received against a PO.
//
//  Uses RefreshDatabase (SQLite in-memory) because confirmReceipt()
//  is a deep DB::transaction — mocking every Eloquent call would be
//  more complex than the code itself.
//
//  Test groups:
//    1. Arrival weight record created automatically
//    2. Arrival weight uses the receipt date, not today
//    3. Each animal gets its own weight record (correct animal_id)
//    4. session_ref is set to PO-ARRIVAL/{reference} for auditability
//    5. No weight record created when weight_kg is absent/null
//    6. Rollback safety — weight record rolls back with the transaction
//    7. Weight record is the w_initial for ADG (integration assertion)
// ══════════════════════════════════════════════════════════════════

class LivestockReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private LivestockReceiptService $svc;
    private ProcurementRecord       $po;
    private User                    $receivingUser;
    private string                  $receiptDate = '2024-03-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(LivestockReceiptService::class);

        // ── Seed minimum required records ──────────────────────────
        $company  = Company::factory()->create();
        $farm     = Farm::factory()->create(['company_id' => $company->id]);
        $lot      = Lot::factory()->create([
            'company_id' => $company->id,
            'farm_id'    => $farm->id,
        ]);
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);

        // Receiving user — the one who scans the PO and weighs animals
        $this->receivingUser = User::factory()->create([
            'company_id' => $company->id,
        ]);
        Auth::login($this->receivingUser);

        // Seed the two accounts required for the T1 journal entry
        // Without these the JE is skipped (soft failure) but receipt still completes
        Account::factory()->create([
            'company_id' => $company->id,
            'code'       => '1310',
            'type'       => 'asset',
            'is_posting' => true,
        ]);
        Account::factory()->create([
            'company_id' => $company->id,
            'code'       => '2230',
            'type'       => 'liability',
            'is_posting' => true,
        ]);

        // Seed an open fiscal year covering the receipt date
        FiscalYear::factory()->create([
            'company_id' => $company->id,
            'status'     => 'open',
            'start_date' => '2024-01-01',
            'end_date'   => '2024-12-31',
        ]);

        // Open PO — the only status that accepts receipts
        $this->po = ProcurementRecord::factory()->create([
            'company_id'            => $company->id,
            'farm_id'               => $farm->id,
            'lot_id'                => $lot->id,
            'supplier_id'           => $supplier->id,
            'po_type'               => 'cattle',
            'po_status'             => 'open',
            'po_reference'          => 'PO/TEST/2024/001',
            'estimated_price_per_kg' => 50.00,
            'min_head'              => 1,
            'max_head'              => 10,
            'received_head'         => 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 1 — Arrival weight record is created automatically
    //
    // Core behaviour: when an animal is received with a weight,
    // a WeightRecord row must be written in the same transaction.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function weight_record_is_created_for_each_received_animal(): void
    {
        $this->assertDatabaseCount('weight_records', 0);

        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 280.0),
            $this->batchMeta()
        );

        $this->assertDatabaseCount('weight_records', 1);
    }

    /** @test */
    public function two_animals_received_produces_two_weight_records(): void
    {
        $animalsData = [
            $this->animalRow(tag: 'TAG-001', weightKg: 280.0),
            $this->animalRow(tag: 'TAG-002', weightKg: 310.0),
        ];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $this->assertDatabaseCount('weight_records', 2);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 2 — Arrival weight uses the receipt date
    //
    // The receiving user's date is the weigh-in date.
    // It must NOT default to today's system date.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function weight_record_date_matches_receipt_date_not_today(): void
    {
        // Receipt date is in the past — 2024-03-15
        // If the system uses today's date instead, this assertion fails
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 280.0),
            $this->batchMeta(date: $this->receiptDate)
        );

        $this->assertDatabaseHas('weight_records', [
            'date' => $this->receiptDate,
        ]);

        $this->assertDatabaseMissing('weight_records', [
            'date' => now()->toDateString(), // must NOT be today unless today = receipt date
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 3 — Each weight record is tied to the correct animal
    //
    // In a batch of two animals, each WeightRecord must reference
    // its own animal_id — not the other animal, not null.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function each_weight_record_references_its_own_animal(): void
    {
        $animalsData = [
            $this->animalRow(tag: 'TAG-001', weightKg: 280.0),
            $this->animalRow(tag: 'TAG-002', weightKg: 315.5),
        ];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $animal1 = Animal::where('tag', 'TAG-001')->firstOrFail();
        $animal2 = Animal::where('tag', 'TAG-002')->firstOrFail();

        // Animal 1 has its own weight record with its own weight
        $this->assertDatabaseHas('weight_records', [
            'animal_id' => $animal1->id,
            'weight_kg' => 280.00,
        ]);

        // Animal 2 has its own weight record with its own weight
        $this->assertDatabaseHas('weight_records', [
            'animal_id' => $animal2->id,
            'weight_kg' => 315.50,
        ]);

        // Animal 1 does NOT have animal 2's weight — no cross-contamination
        $this->assertDatabaseMissing('weight_records', [
            'animal_id' => $animal1->id,
            'weight_kg' => 315.50,
        ]);
    }

    /** @test */
    public function weight_record_weight_matches_what_receiving_user_entered(): void
    {
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 293.75),
            $this->batchMeta()
        );

        $this->assertDatabaseHas('weight_records', [
            'weight_kg' => 293.75,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 4 — session_ref identifies the weight as an arrival weigh-in
    //
    // session_ref = 'PO-ARRIVAL/{po_reference}' makes the weight
    // history auditable — clearly distinguishable from routine
    // mid-period weigh-ins recorded through the weight screen.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function weight_record_session_ref_identifies_po_arrival(): void
    {
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 280.0),
            $this->batchMeta()
        );

        $this->assertDatabaseHas('weight_records', [
            'session_ref' => 'PO-ARRIVAL/' . $this->po->po_reference,
        ]);
    }

    /** @test */
    public function weight_record_notes_reference_the_batch_id(): void
    {
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 280.0),
            $this->batchMeta()
        );

        $record = WeightRecord::first();

        $this->assertStringContainsString('Arrival weight', $record->notes);
        $this->assertStringContainsString('batch', strtolower($record->notes));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 5 — No weight record when weight_kg is absent or null
    //
    // The guard: if (!empty($animalData['weight_kg']))
    // An animal submitted without a weight must not produce a
    // weight record with weight = 0 or null.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function no_weight_record_created_when_weight_kg_is_null(): void
    {
        $animalsData = [[
            'tag'        => 'TAG-001',
            'sex'        => 'male',
            'weight_kg'  => null,    // explicitly null
        ]];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $this->assertDatabaseCount('weight_records', 0);
        // Animal still created — just no weight record
        $this->assertDatabaseCount('animals', 1);
    }

    /** @test */
    public function no_weight_record_created_when_weight_kg_is_missing_from_payload(): void
    {
        $animalsData = [[
            'tag' => 'TAG-001',
            'sex' => 'male',
            // weight_kg key absent entirely
        ]];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $this->assertDatabaseCount('weight_records', 0);
        $this->assertDatabaseCount('animals', 1);
    }

    /** @test */
    public function no_weight_record_created_when_weight_kg_is_zero(): void
    {
        // A weight of 0 kg is nonsensical for a live animal — the guard
        // uses !empty() which treats 0 as falsy, same as null
        $animalsData = [[
            'tag'       => 'TAG-001',
            'sex'       => 'male',
            'weight_kg' => 0,
        ]];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $this->assertDatabaseCount('weight_records', 0);
    }

    /** @test */
    public function mixed_batch_only_creates_weight_records_for_animals_with_weight(): void
    {
        // Animal 1 has a weight, Animal 2 does not
        // Expect exactly 1 weight record — for Animal 1 only
        $animalsData = [
            $this->animalRow(tag: 'TAG-001', weightKg: 280.0),
            ['tag' => 'TAG-002', 'sex' => 'female', 'weight_kg' => null],
        ];

        $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());

        $this->assertDatabaseCount('animals', 2);
        $this->assertDatabaseCount('weight_records', 1);

        $animal1 = Animal::where('tag', 'TAG-001')->firstOrFail();
        $this->assertDatabaseHas('weight_records', ['animal_id' => $animal1->id]);

        $animal2 = Animal::where('tag', 'TAG-002')->firstOrFail();
        $this->assertDatabaseMissing('weight_records', ['animal_id' => $animal2->id]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 6 — Rollback safety
    //
    // The weight record lives inside the same DB::transaction as
    // the animal, the receipt batch, and the journal entry.
    // If anything fails after the weight record is written,
    // the weight record must roll back with everything else.
    //
    // We simulate failure by providing a duplicate tag — this
    // causes Animal::create() on the second iteration to throw a
    // unique constraint violation, rolling back the entire batch.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function weight_records_roll_back_when_transaction_fails(): void
    {
        // Pre-create an animal with TAG-001 to force a unique violation
        // on the second receipt attempt
        Animal::factory()->create([
            'company_id' => $this->po->company_id,
            'tag'        => 'TAG-DUPE',
        ]);

        $animalsData = [
            $this->animalRow(tag: 'TAG-NEW',  weightKg: 280.0),
            $this->animalRow(tag: 'TAG-DUPE', weightKg: 300.0), // duplicate — will throw
        ];

        try {
            $this->svc->confirmReceipt($this->po->id, $animalsData, $this->batchMeta());
        } catch (\Throwable $e) {
            // Expected — transaction rolled back
        }

        // Neither animal row nor weight record should exist
        $this->assertDatabaseMissing('animals', ['tag' => 'TAG-NEW']);
        $this->assertDatabaseCount('weight_records', 0);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 7 — ADG integration assertion
    //
    // The whole point of this fix: after receipt, the animal has
    // exactly one weight record. When a second weight is recorded
    // later (e.g. at sale), adgFcrForAnimal() can compute ADG.
    //
    // This test confirms the weight record created at receipt is
    // the earliest record in the log — i.e., it will be picked up
    // as w_initial by the ADG query (ORDER BY date ASC, first row).
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function arrival_weight_record_is_the_earliest_in_weight_history(): void
    {
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 250.0),
            $this->batchMeta(date: '2024-01-10')
        );

        $animal = Animal::where('tag', 'TAG-001')->firstOrFail();

        // Simulate a later weigh-in (routine mid-period check)
        WeightRecord::create([
            'company_id'  => $this->po->company_id,
            'farm_id'     => $this->po->farm_id,
            'lot_id'      => $this->po->lot_id,
            'animal_id'   => $animal->id,
            'date'        => '2024-03-01',   // later than arrival
            'weight_kg'   => 310.0,
            'recorded_by' => $this->receivingUser->id,
        ]);

        // Fetch weight records in the order ADG uses them (date ASC)
        $records = WeightRecord::where('animal_id', $animal->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(2, $records);

        // First record must be the arrival weight at the receipt date
        $this->assertEquals('2024-01-10', $records->first()->date->toDateString());
        $this->assertEquals(250.0, (float) $records->first()->weight_kg);

        // Second record is the later weigh-in
        $this->assertEquals('2024-03-01', $records->last()->date->toDateString());
        $this->assertEquals(310.0, (float) $records->last()->weight_kg);
    }

    /** @test */
    public function adg_can_be_computed_once_arrival_and_sale_weights_exist(): void
    {
        // Receipt on Jan 10 — weight 250 kg
        $this->svc->confirmReceipt(
            $this->po->id,
            $this->oneAnimalPayload(tag: 'TAG-001', weightKg: 250.0),
            $this->batchMeta(date: '2024-01-10')
        );

        $animal = Animal::where('tag', 'TAG-001')->firstOrFail();

        // Sale weigh-in on Mar 20 (70 days later) — weight 320 kg
        WeightRecord::create([
            'company_id'  => $this->po->company_id,
            'farm_id'     => $this->po->farm_id,
            'lot_id'      => $this->po->lot_id,
            'animal_id'   => $animal->id,
            'date'        => '2024-03-20',
            'weight_kg'   => 320.0,
            'recorded_by' => $this->receivingUser->id,
        ]);

        // Verify the system now has exactly 2 weight records
        $this->assertDatabaseCount('weight_records', 2);

        // Manually compute the expected ADG to confirm the data is correct
        // ADG = (320 - 250) / 70 days = 1.000 kg/day
        $records = WeightRecord::where('animal_id', $animal->id)
            ->orderBy('date')
            ->get();

        $wInitial  = (float) $records->first()->weight_kg;  // 250
        $wFinal    = (float) $records->last()->weight_kg;   // 320
        $dof       = $records->first()->date->diffInDays($records->last()->date); // 70
        $adg       = round(($wFinal - $wInitial) / $dof, 3);

        $this->assertEquals(250.0, $wInitial);
        $this->assertEquals(320.0, $wFinal);
        $this->assertEquals(70,    $dof);
        $this->assertEquals(1.000, $adg);
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    private function oneAnimalPayload(string $tag, float $weightKg): array
    {
        return [$this->animalRow(tag: $tag, weightKg: $weightKg)];
    }

    private function animalRow(string $tag, float $weightKg): array
    {
        return [
            'tag'        => $tag,
            'sex'        => 'male',
            'weight_kg'  => $weightKg,
        ];
    }

    private function batchMeta(string $date = null): array
    {
        return [
            'date'               => $date ?? $this->receiptDate,
            'receipt_mode'       => 'bulk_avg',
            'freight_allocated'  => 0,
            'notes'              => 'Test receipt',
        ];
    }
}
