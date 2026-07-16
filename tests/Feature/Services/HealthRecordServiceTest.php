<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\Animal;
use App\Models\Company;
use App\Models\Farm;
use App\Models\FiscalYear;
use App\Models\HealthRecord;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lot;
use App\Models\User;
use App\Services\HealthRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — HealthRecordService Feature Tests
//
//  📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
//  📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//
//  FILE PLACEMENT: tests/Feature/Services/HealthRecordServiceTest.php
//
//  Why Feature (not Unit):
//    create(), update(), delete() are wrapped in DB::transaction()
//    and call lockForUpdate() — real SQLite/MySQL is required.
//    Mocking every Eloquent call would be more complex than the
//    service itself and would test the mock, not the logic.
//
//  Test groups:
//    1.  create() happy path           — stock decremented, record created
//    2.  create() WAC cost override    — user cost ignored; WAC × dose wins
//    3.  create() stock guard          — dose > qty_on_hand throws
//    4.  create() dose validation      — zero / negative dose throws
//    5.  create() inventory transaction — consumption row written correctly
//    6.  create() T3 journal entry     — balanced Dr WIP / Cr Medicine
//    7.  create() missing CoA accounts — soft-fail, record still created
//    8.  create() no open fiscal year  — soft-fail, record still created
//    9.  create() rollback safety      — mid-txn failure leaves stock intact
//   10.  update() same item/location   — restore + re-decrement on same row
//   11.  update() location change      — old stock restored, new stock decremented
//   12.  update() SEC-GAP-5 lock       — single whereIn() covers both rows
//   13.  delete() stock restored       — full dose returned to location stock
//   14.  delete() journal reversed     — posted T3 reversed, not deleted
//   15.  bcmath precision              — repeating-decimal WAC stays exact
//
// ══════════════════════════════════════════════════════════════════

class HealthRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    private HealthRecordService $svc;

    // ── Shared scaffold ───────────────────────────────────────────
    private int $companyId;
    private int $farmId;
    private int $lotId;
    private int $animalId;
    private int $locationId;
    private int $masterItemId;
    private int $stockRowId;
    private int $wipAccountId;
    private int $medicineAccountId;
    private int $fiscalYearId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new HealthRecordService();
        $this->scaffoldWorld();
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 1 — create() happy path
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
    //
    // Business scenario:
    //   A vet administers 2 units of a medicine from Location A.
    //   The location stock starts at 10 units.
    //   After the treatment: stock should be 8, record should exist.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_decrements_location_stock_by_exact_dose(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);

        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 8.0,
        ]);
    }

    /** @test */
    public function create_persists_health_record_with_correct_fields(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData([
            'dose'      => 2.0,
            'date'      => '2025-03-15',
            'diagnosis' => 'Foot rot',
        ]), $this->companyId);

        $this->assertInstanceOf(HealthRecord::class, $record);
        $this->assertDatabaseHas('health_records', [
            'id'         => $record->id,
            'animal_id'  => $this->animalId,
            'company_id' => $this->companyId,
            'dose'       => 2.0,
            'date'       => '2025-03-15',
        ]);
    }

    /** @test */
    public function create_denormalizes_medicine_name_from_master_item(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        InventoryItem::where('id', $this->masterItemId)
            ->update(['name_en' => 'Oxytetracycline']);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $this->assertSame('Oxytetracycline', $record->medicine_name);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 2 — create() WAC cost override
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14) §6
    //
    // Business scenario:
    //   The user submits a cost field in the form.
    //   The system must IGNORE it and use WAC × dose instead.
    //   If WAC = 75 EGP/unit and dose = 4, cost must be exactly 300.00.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_ignores_user_submitted_cost_and_uses_wac(): void
    {
        $this->setStock(qty: 20.0, unitCost: 75.0);

        $data = $this->makeData(['dose' => 4.0, 'cost' => 9999.99]); // user submits garbage
        $record = $this->svc->create($data, $this->companyId);

        // 75 × 4 = 300.00
        $this->assertEqualsWithDelta(300.00, $record->cost, 0.005);
    }

    /** @test */
    public function create_cost_is_wac_times_dose_rounded_to_two_decimals(): void
    {
        // WAC = 33.333 EGP/unit — typical repeating decimal scenario
        $this->setStock(qty: 20.0, unitCost: 33.333);

        $record = $this->svc->create($this->makeData(['dose' => 3.0]), $this->companyId);

        // bcmath: 33.333 × 3 = 99.999 → rounded to 100.00
        // The service uses bcmul(..., 4) then bcadd('0', 2) which gives 99.9990 → 100.00
        $this->assertEqualsWithDelta(100.00, $record->cost, 0.005);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 3 — create() stock guard
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14) §2
    //
    // Business scenario:
    //   Location A has 5 units. Vet tries to administer 6.
    //   System must throw a ValidationException immediately — no partial
    //   decrement, no record created, no journal entry.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_throws_when_dose_exceeds_qty_on_hand(): void
    {
        $this->setStock(qty: 5.0, unitCost: 50.0);

        $this->expectException(ValidationException::class);

        $this->svc->create($this->makeData(['dose' => 6.0]), $this->companyId);
    }

    /** @test */
    public function create_does_not_touch_stock_when_dose_guard_fires(): void
    {
        $this->setStock(qty: 5.0, unitCost: 50.0);

        try {
            $this->svc->create($this->makeData(['dose' => 6.0]), $this->companyId);
        } catch (ValidationException) {
            // expected
        }

        // Stock must be exactly as it was — nothing was decremented
        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 5.0,
        ]);
    }

    /** @test */
    public function create_does_not_create_health_record_when_stock_guard_fires(): void
    {
        $this->setStock(qty: 5.0, unitCost: 50.0);

        try {
            $this->svc->create($this->makeData(['dose' => 6.0]), $this->companyId);
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('health_records', 0);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 4 — create() dose validation
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14) §2
    //
    // Business scenario:
    //   A zero or negative dose makes no physical sense.
    //   The service must reject it before touching any data.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_throws_when_dose_is_zero(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $this->expectException(ValidationException::class);

        $this->svc->create($this->makeData(['dose' => 0.0]), $this->companyId);
    }

    /** @test */
    public function create_throws_when_dose_is_negative(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $this->expectException(ValidationException::class);

        $this->svc->create($this->makeData(['dose' => -1.0]), $this->companyId);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 5 — create() inventory transaction
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
    //
    // Business scenario:
    //   Every medicine treatment must leave a full audit trail.
    //   An InventoryTransaction row (type=consumption) must be written
    //   with the correct item, quantity, cost, and reference back to
    //   the health record.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_writes_consumption_inventory_transaction(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0, 'date' => '2025-04-01']), $this->companyId);

        $this->assertDatabaseHas('inventory_transactions', [
            'ref_type'   => 'health_record',
            'ref_id'     => $record->id,
            'type'       => 'consumption',
            'qty'        => 2.0,
            'unit_cost'  => 50.0,
            'total'      => 100.0,
            'date'       => '2025-04-01',
            'company_id' => $this->companyId,
        ]);
    }

    /** @test */
    public function create_transaction_references_location_stock_row_not_master(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $tx = InventoryTransaction::where('ref_type', 'health_record')
            ->where('ref_id', $record->id)
            ->first();

        // item_id must be the location stock row — NOT the master
        $this->assertSame($this->stockRowId, (int) $tx->item_id);
        $this->assertNotSame($this->masterItemId, (int) $tx->item_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 6 — create() T3 journal entry
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §9
    // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
    //
    // Business scenario:
    //   Every treatment posts a balanced journal entry:
    //   Dr Livestock WIP (1310) — animal_id stamped for per-animal COGS tracing
    //   Cr Medicine Inventory (1330)
    //   Total debit must equal total credit to the cent.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_posts_balanced_t3_journal_entry(): void
    {
        $this->setStock(qty: 10.0, unitCost: 80.0);

        $record = $this->svc->create($this->makeData(['dose' => 3.0]), $this->companyId);

        $this->assertNotNull($record->journal_entry_id);

        $je = JournalEntry::find($record->journal_entry_id);
        $this->assertSame('posted', $je->status);
        $this->assertSame('health', $je->entry_type);

        // Balanced: 80 × 3 = 240.00 on both sides
        $this->assertEqualsWithDelta(240.00, $je->total_debit,  0.005);
        $this->assertEqualsWithDelta(240.00, $je->total_credit, 0.005);
    }

    /** @test */
    public function create_t3_debit_line_is_wip_account_with_animal_id_stamped(): void
    {
        $this->setStock(qty: 10.0, unitCost: 80.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $debitLine = JournalLine::where('entry_id', $record->journal_entry_id)
            ->where('debit', '>', 0)
            ->first();

        $this->assertSame($this->wipAccountId, (int) $debitLine->account_id);
        $this->assertSame($this->animalId,     (int) $debitLine->animal_id);
    }

    /** @test */
    public function create_t3_credit_line_is_medicine_inventory_account(): void
    {
        $this->setStock(qty: 10.0, unitCost: 80.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $creditLine = JournalLine::where('entry_id', $record->journal_entry_id)
            ->where('credit', '>', 0)
            ->first();

        $this->assertSame($this->medicineAccountId, (int) $creditLine->account_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 7 — create() soft-fail when CoA accounts missing
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §9
    //
    // Business scenario:
    //   A company is still setting up their Chart of Accounts.
    //   A treatment must still be recorded even if 1310 or 1330 are absent —
    //   the system flashes a warning but does NOT throw an exception.
    //   The health record and stock decrement are still committed.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_soft_fails_when_wip_account_is_missing(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        // Remove the WIP account
        Account::where('id', $this->wipAccountId)->delete();

        // Must NOT throw — should complete gracefully
        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $this->assertNotNull($record->id);
        $this->assertNull($record->journal_entry_id); // No JE posted
    }

    /** @test */
    public function create_soft_fails_when_medicine_account_is_missing(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        Account::where('id', $this->medicineAccountId)->delete();

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $this->assertNotNull($record->id);
        $this->assertNull($record->journal_entry_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 8 — create() soft-fail when no open fiscal year
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §9
    //
    // Business scenario:
    //   Fiscal year for the treatment date is closed or doesn't exist.
    //   Same soft-fail rule: record created, no journal entry posted.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_soft_fails_when_no_open_fiscal_year_covers_date(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        // Close the fiscal year
        FiscalYear::where('id', $this->fiscalYearId)->update(['status' => 'closed']);

        $record = $this->svc->create(
            $this->makeData(['dose' => 1.0, 'date' => '2025-06-01']),
            $this->companyId
        );

        $this->assertNotNull($record->id);
        $this->assertNull($record->journal_entry_id);
    }

    /** @test */
    public function create_soft_fails_when_treatment_date_outside_fiscal_year_range(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        // Fiscal year is 2025 only — treatment date is 2026
        $record = $this->svc->create(
            $this->makeData(['dose' => 1.0, 'date' => '2026-01-01']),
            $this->companyId
        );

        $this->assertNotNull($record->id);
        $this->assertNull($record->journal_entry_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 9 — create() rollback safety
    //
    // Business scenario:
    //   If something fails mid-transaction (e.g. HealthRecord::create()
    //   throws due to a DB constraint), the stock decrement must roll back.
    //   The farm's medicine inventory must be exactly as it started.
    //
    // We simulate failure by passing an invalid animal_id (NULL) that
    //   violates a NOT NULL constraint on health_records.animal_id.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function create_rolls_back_stock_decrement_on_failure(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        try {
            // Pass null animal_id to force a DB constraint failure
            $data               = $this->makeData(['dose' => 2.0]);
            $data['animal_id']  = null;
            $this->svc->create($data, $this->companyId);
        } catch (\Throwable) {
            // any error — we only care that stock did not change
        }

        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 10.0, // unchanged
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 10 — update() same item and location
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
    //
    // Business scenario:
    //   Original treatment: 2 units administered. Stock: 8 remaining.
    //   Vet corrects the dose to 3 units.
    //   Expected: stock goes back up to 10, then down to 7.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function update_restores_old_dose_and_applies_new_dose_on_same_stock_row(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);
        // Stock is now 8

        $newData          = $this->makeData(['dose' => 3.0]);
        $newData['lot_id'] = $record->lot_id;
        $this->svc->update($record, $newData);

        // Restore 2 → stock = 10, then decrement 3 → stock = 7
        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 7.0,
        ]);
    }

    /** @test */
    public function update_recalculates_cost_from_new_dose_using_wac(): void
    {
        $this->setStock(qty: 20.0, unitCost: 40.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);

        $newData = $this->makeData(['dose' => 5.0]);
        $updated = $this->svc->update($record, $newData);

        // 40 WAC × 5 dose = 200.00
        $this->assertEqualsWithDelta(200.00, $updated->cost, 0.005);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 11 — update() location change
    //
    // Business scenario:
    //   Vet originally pulled from Location A.
    //   On correction, she specifies Location B instead.
    //   Location A stock must be fully restored (old dose returned).
    //   Location B stock must be decremented by the new dose.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function update_restores_old_location_and_decrements_new_location(): void
    {
        // Location A — original
        $this->setStock(qty: 10.0, unitCost: 50.0); // stockRowId = location A stock

        // Location B — target after update
        $locationB = $this->createLocation('Location B');
        $stockRowB = $this->createStockRow($locationB->id, qty: 20.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);
        // Location A stock: 8

        $newData              = $this->makeData(['dose' => 3.0]);
        $newData['location_id'] = $locationB->id;
        $this->svc->update($record, $newData);

        // Location A: 8 + 2 (restore) = 10
        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 10.0,
        ]);

        // Location B: 20 − 3 = 17
        $this->assertDatabaseHas('inventory_items', [
            'id'          => $stockRowB->id,
            'qty_on_hand' => 17.0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 12 — update() SEC-GAP-5 single lock
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14) §SEC-GAP-5
    //
    // Business scenario:
    //   Two concurrent update requests (race condition).
    //   Old and new stock rows must be locked in ONE whereIn() call,
    //   not two separate find() calls with a gap between them.
    //
    //   We verify the outcome (no phantom decrement), not the internal
    //   implementation detail (can't inspect query logs in SQLite easily).
    //   The invariant tested: after update, total stock across both
    //   locations = starting stock − net dose.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function update_produces_correct_net_stock_across_both_locations(): void
    {
        // Location A: 10 units
        $this->setStock(qty: 10.0, unitCost: 50.0);

        // Location B: 15 units
        $locationB = $this->createLocation('Location B');
        $stockRowB = $this->createStockRow($locationB->id, qty: 15.0, unitCost: 50.0);

        // Create against Location A: dose = 2
        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);
        // A=8, B=15

        // Update: move to Location B, dose = 4
        $newData              = $this->makeData(['dose' => 4.0]);
        $newData['location_id'] = $locationB->id;
        $this->svc->update($record, $newData);
        // Expected: A = 8+2 = 10, B = 15−4 = 11

        $stockA = InventoryItem::find($this->stockRowId)->qty_on_hand;
        $stockB = InventoryItem::find($stockRowB->id)->qty_on_hand;

        // Total across both locations must be (10 + 15) − 4 (net new dose) = 21
        $this->assertEqualsWithDelta(21.0, (float) $stockA + (float) $stockB, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $stockA, 0.001);
        $this->assertEqualsWithDelta(11.0, (float) $stockB, 0.001);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 13 — delete() stock restored
    //
    // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
    //
    // Business scenario:
    //   A health record was entered by mistake. It gets deleted.
    //   The full dose must return to the location stock row.
    //   The InventoryTransaction row must also be removed.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function delete_restores_full_dose_to_location_stock(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 3.0]), $this->companyId);
        // Stock: 7

        $this->svc->delete($record);

        // Stock back to 10
        $this->assertDatabaseHas('inventory_items', [
            'id'          => $this->stockRowId,
            'qty_on_hand' => 10.0,
        ]);
    }

    /** @test */
    public function delete_removes_linked_inventory_transaction(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);

        $this->svc->delete($record);

        $this->assertDatabaseMissing('inventory_transactions', [
            'ref_type' => 'health_record',
            'ref_id'   => $record->id,
        ]);
    }

    /** @test */
    public function delete_removes_the_health_record_itself(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);
        $id     = $record->id;

        $this->svc->delete($record);

        $this->assertDatabaseMissing('health_records', ['id' => $id]);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 14 — delete() journal reversal
    //
    // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
    //
    // Business scenario:
    //   A posted T3 journal entry cannot be deleted — it must be reversed.
    //   After delete(), the original entry must be status='reversed'
    //   and a mirror reversal entry must exist (Dr and Cr swapped).
    //   The reversal itself must be balanced.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function delete_marks_posted_journal_entry_as_reversed(): void
    {
        $this->setStock(qty: 10.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);
        $jeId   = $record->journal_entry_id;

        $this->svc->delete($record);

        $this->assertDatabaseHas('journal_entries', [
            'id'     => $jeId,
            'status' => 'reversed',
        ]);
    }

    /** @test */
    public function delete_creates_a_balanced_reversal_journal_entry(): void
    {
        $this->setStock(qty: 10.0, unitCost: 80.0);

        $record = $this->svc->create($this->makeData(['dose' => 2.0]), $this->companyId);
        // T3 amount = 80 × 2 = 160.00

        $jeId = $record->journal_entry_id;

        $this->svc->delete($record);

        // A reversal entry must exist that links back to the original
        $reversal = JournalEntry::where('reversal_of_id', $jeId)->first();
        $this->assertNotNull($reversal);
        $this->assertSame('posted', $reversal->status);

        // Reversal must be balanced
        $this->assertEqualsWithDelta(160.00, $reversal->total_debit,  0.005);
        $this->assertEqualsWithDelta(160.00, $reversal->total_credit, 0.005);
    }

    /** @test */
    public function delete_reversal_has_swapped_debit_and_credit_lines(): void
    {
        $this->setStock(qty: 10.0, unitCost: 80.0);

        $record = $this->svc->create($this->makeData(['dose' => 1.0]), $this->companyId);
        $jeId   = $record->journal_entry_id;

        $this->svc->delete($record);

        $reversal     = JournalEntry::where('reversal_of_id', $jeId)->first();
        $reversalLines = JournalLine::where('entry_id', $reversal->id)->get();

        // Original: Dr WIP / Cr Medicine
        // Reversal: Dr Medicine / Cr WIP
        $drLine = $reversalLines->firstWhere(fn($l) => $l->debit > 0);
        $crLine = $reversalLines->firstWhere(fn($l) => $l->credit > 0);

        $this->assertSame($this->medicineAccountId, (int) $drLine->account_id); // Medicine now Dr
        $this->assertSame($this->wipAccountId,      (int) $crLine->account_id); // WIP now Cr
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 15 — bcmath precision
    //
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    //
    // Business scenario:
    //   WAC is often a repeating decimal (e.g. 100 EGP / 3 units = 33.333...).
    //   PHP float arithmetic would silently drift.
    //   bcmath must keep the result exact to 2 decimal places.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function cost_calculation_uses_bcmath_for_repeating_decimal_wac(): void
    {
        // 100 EGP for 3 units = WAC of 33.333...
        $this->setStock(qty: 9.0, unitCost: 33.333333);

        $record = $this->svc->create($this->makeData(['dose' => 3.0]), $this->companyId);

        // bcmath: 33.333333 × 3 = 99.999999 → rounded to 2dp = 100.00
        $this->assertEqualsWithDelta(100.00, $record->cost, 0.005);
    }

    /** @test */
    public function stock_decrement_does_not_drift_with_float_dose(): void
    {
        // 0.1 + 0.2 is a classic float pitfall (= 0.30000000000000004 in PHP)
        // The service stores qty as float on the model but bcmath protects the math
        $this->setStock(qty: 1.0, unitCost: 50.0);

        $record = $this->svc->create($this->makeData(['dose' => 0.1]), $this->companyId);

        $remaining = (float) InventoryItem::find($this->stockRowId)->qty_on_hand;

        // Must be 0.9 — not 0.8999999... from float drift
        $this->assertEqualsWithDelta(0.9, $remaining, 0.001);
    }

    // ══════════════════════════════════════════════════════════════════
    // SCAFFOLD HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Build the minimum world required for every test:
     * Company → Farm → Lot → Animal
     * InventoryLocation → master InventoryItem
     * Accounts (WIP 1310, Medicine 1330)
     * FiscalYear (open, covering 2025)
     * Authenticated User
     */
    private function scaffoldWorld(): void
    {
        // User (authenticated for posted_by / recorded_by)
        $user = User::factory()->create();
        Auth::login($user);
        $this->userId = $user->id;

        // Company
        $company          = Company::factory()->create();
        $this->companyId  = $company->id;

        // Farm
        $farm           = Farm::factory()->create(['company_id' => $this->companyId]);
        $this->farmId   = $farm->id;

        // Lot
        $lot           = Lot::factory()->create([
            'company_id' => $this->companyId,
            'farm_id'    => $this->farmId,
        ]);
        $this->lotId = $lot->id;

        // Animal
        $animal           = Animal::factory()->create([
            'company_id' => $this->companyId,
            'farm_id'    => $this->farmId,
            'lot_id'     => $this->lotId,
        ]);
        $this->animalId = $animal->id;

        // Inventory Location (farm-level)
        $location           = $this->createLocation('Main Pharmacy');
        $this->locationId   = $location->id;

        // Master InventoryItem (is_master = true, category = medicine)
        $master               = InventoryItem::factory()->create([
            'company_id' => $this->companyId,
            'is_master'  => true,
            'category'   => 'medicine',
            'name_en'    => 'Test Medicine',
            'unit'       => 'vial',
        ]);
        $this->masterItemId = $master->id;

        // Location stock row (is_master = false) — qty and cost set per-test via setStock()
        $stockRow = InventoryItem::factory()->create([
            'company_id'     => $this->companyId,
            'farm_id'        => $this->farmId,
            'location_id'    => $this->locationId,
            'master_item_id' => $this->masterItemId,
            'is_master'      => false,
            'category'       => 'medicine',
            'qty_on_hand'    => 0,
            'unit_cost'      => 0,
        ]);
        $this->stockRowId = $stockRow->id;

        // WIP Account (1310)
        $wipAccount = Account::factory()->create([
            'company_id'           => $this->companyId,
            'code'                 => '1310',
            'type'                 => 'asset',
            'allow_direct_posting' => true,
        ]);
        $this->wipAccountId = $wipAccount->id;

        // Medicine Inventory Account (1330)
        $medAccount = Account::factory()->create([
            'company_id'           => $this->companyId,
            'code'                 => '1330',
            'type'                 => 'asset',
            'allow_direct_posting' => true,
        ]);
        $this->medicineAccountId = $medAccount->id;

        // Fiscal Year (open, covers all of 2025)
        $fy = FiscalYear::factory()->create([
            'company_id' => $this->companyId,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'open',
        ]);
        $this->fiscalYearId = $fy->id;
    }

    /**
     * Set qty_on_hand and unit_cost on the location stock row.
     * Called at the start of each test that needs specific stock levels.
     */
    private function setStock(float $qty, float $unitCost): void
    {
        InventoryItem::where('id', $this->stockRowId)->update([
            'qty_on_hand' => $qty,
            'unit_cost'   => $unitCost,
        ]);
    }

    /**
     * Build the minimal data array for svc->create().
     * Merges $overrides on top of sensible defaults.
     */
    private function makeData(array $overrides = []): array
    {
        return array_merge([
            'animal_id'         => $this->animalId,
            'lot_id'            => $this->lotId,
            'farm_id'           => $this->farmId,
            'inventory_item_id' => $this->masterItemId,
            'location_id'       => $this->locationId,
            'dose'              => 1.0,
            'date'              => '2025-06-15',
            'diagnosis'         => 'Routine treatment',
            'treatment'         => 'Administered medicine',
        ], $overrides);
    }

    /**
     * Create an InventoryLocation for this company/farm.
     */
    private function createLocation(string $nameEn): InventoryLocation
    {
        return InventoryLocation::factory()->create([
            'company_id' => $this->companyId,
            'farm_id'    => $this->farmId,
            'name_en'    => $nameEn,
        ]);
    }

    /**
     * Create a location-specific stock row for the master item at a given location.
     */
    private function createStockRow(int $locationId, float $qty, float $unitCost): InventoryItem
    {
        return InventoryItem::factory()->create([
            'company_id'     => $this->companyId,
            'farm_id'        => $this->farmId,
            'location_id'    => $locationId,
            'master_item_id' => $this->masterItemId,
            'is_master'      => false,
            'category'       => 'medicine',
            'qty_on_hand'    => $qty,
            'unit_cost'      => $unitCost,
        ]);
    }
}