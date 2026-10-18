<?php

namespace Tests\Feature\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Services\InventoryLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — InventoryLocationService Feature Tests
//
//  📌 METHODOLOGY REFERENCE: InventoryLocationTransfer (#15)
//  📌 METHODOLOGY REFERENCE: WAC (#10)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//
//  Uses RefreshDatabase (SQLite in-memory) because all five public
//  methods perform multi-row DB writes inside transactions.
//
//  Test groups:
//    1.  fanOutNewItem()      — basic fan-out creates stock rows for every active location
//    2.  fanOutNewItem()      — company scoping (other companies untouched)
//    3.  fanOutNewItem()      — inactive locations skipped
//    4.  syncNewLocation()    — basic sync creates stock rows for every active master item
//    5.  syncNewLocation()    — idempotency (duplicate stock rows never created)
//    6.  syncNewLocation()    — inactive master items skipped
//    7.  recordTransfer()     — happy path: correct qty changes on both sides
//    8.  recordTransfer()     — two transaction rows, correct types, shared batch_ref
//    9.  recordTransfer()     — qty_after values on both rows are correct
//    10. recordTransfer()     — insufficient stock: ValidationException, no DB change
//    11. recordTransfer()     — zero / negative qty guard fires before any write
//    12. recordTransfer()     — exact depletion (transfer full balance) succeeds
//    13. recordTransfer()     — mid-transaction failure rolls back completely
//    14. recordTransfer()     — total = qty × unit_cost computed with bcmath
//    15. syncMasterEdit()     — deactivating master deactivates all location rows
//    16. getStockSummary()    — total_qty is sum of all location rows
//    17. getStockSummary()    — has_low_stock flag set correctly
//    18. bcmath precision     — repeating-decimal WAC total stays exact
// ══════════════════════════════════════════════════════════════════

class InventoryLocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLocationService $svc;

    // ── Shared scaffold IDs ───────────────────────────────────────
    private int $companyId;
    private int $companyBId;   // second company for scoping tests
    private int $locAId;       // location A (active)
    private int $locBId;       // location B (active)
    private int $locInactiveId;// location (inactive)
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new InventoryLocationService();
        $this->buildWorld();
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 1 — fanOutNewItem() basic
    //
    // When a master item is created and fanOutNewItem() is called,
    // every ACTIVE location in the same company must get one
    // zero-stock location row.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function fan_out_creates_one_stock_row_per_active_location(): void
    {
        $master = $this->makeMasterItem();

        $this->svc->fanOutNewItem($master);

        // Two active locations → two stock rows
        $this->assertDatabaseCount('inventory_items', 3); // 1 master + 2 stock rows
    }

    /** @test */
    public function fan_out_stock_rows_start_with_zero_qty(): void
    {
        $master = $this->makeMasterItem();
        $this->svc->fanOutNewItem($master);

        $stockRows = InventoryItem::where('master_item_id', $master->id)->get();

        foreach ($stockRows as $row) {
            $this->assertEquals(0.0, (float) $row->qty_on_hand,
                "Stock row for location {$row->location_id} should start at zero");
        }
    }

    /** @test */
    public function fan_out_links_stock_rows_to_correct_locations(): void
    {
        $master = $this->makeMasterItem();
        $this->svc->fanOutNewItem($master);

        $locationIds = InventoryItem::where('master_item_id', $master->id)
            ->pluck('location_id')
            ->sort()
            ->values()
            ->toArray();

        $expected = collect([$this->locAId, $this->locBId])->sort()->values()->toArray();

        $this->assertEquals($expected, $locationIds);
    }

    /** @test */
    public function fan_out_copies_category_and_unit_from_master(): void
    {
        $master = $this->makeMasterItem(['category' => 'feed', 'unit' => 'kg']);
        $this->svc->fanOutNewItem($master);

        InventoryItem::where('master_item_id', $master->id)->each(function ($row) {
            $this->assertEquals('feed', $row->category);
            $this->assertEquals('kg', $row->unit);
        });
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 2 — fanOutNewItem() company scoping
    //
    // Fan-out must be completely isolated to the master item's own
    // company. Locations belonging to any other company must receive
    // zero stock rows — even if they are active.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function fan_out_does_not_touch_other_company_locations(): void
    {
        // Company B has its own active location
        $locOther = InventoryLocation::create([
            'company_id' => $this->companyBId,
            'farm_id'    => null,
            'name_en'    => 'Other Co Location',
            'name_ar'    => 'موقع شركة أخرى',
            'type'       => 'warehouse',
            'is_active'  => true,
        ]);

        $master = $this->makeMasterItem(); // belongs to company A
        $this->svc->fanOutNewItem($master);

        // Company B's location must have zero stock rows for this master
        $this->assertDatabaseMissing('inventory_items', [
            'master_item_id' => $master->id,
            'location_id'    => $locOther->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 3 — fanOutNewItem() inactive locations skipped
    //
    // is_active = false locations are permanently ignored during
    // fan-out. Items should not accumulate stock at closed locations.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function fan_out_skips_inactive_locations(): void
    {
        $master = $this->makeMasterItem();
        $this->svc->fanOutNewItem($master);

        // The inactive location must have no stock row
        $this->assertDatabaseMissing('inventory_items', [
            'master_item_id' => $master->id,
            'location_id'    => $this->locInactiveId,
        ]);

        // Only the two active locations got rows
        $count = InventoryItem::where('master_item_id', $master->id)->count();
        $this->assertEquals(2, $count);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 4 — syncNewLocation() basic
    //
    // When a brand-new location is added to the company, every
    // existing active master item in that company must get a
    // zero-stock row pointing at the new location.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function sync_new_location_creates_stock_rows_for_all_active_master_items(): void
    {
        // Pre-create two master items (no fan-out yet — simulating existing items)
        $master1 = $this->makeMasterItem(['name_en' => 'Barley']);
        $master2 = $this->makeMasterItem(['name_en' => 'Maize']);

        // New location arrives
        $newLoc = InventoryLocation::create([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'New Warehouse',
            'name_ar'    => 'مستودع جديد',
            'type'       => 'warehouse',
            'is_active'  => true,
        ]);

        $this->svc->syncNewLocation($newLoc);

        // Both masters get a stock row at the new location
        $this->assertDatabaseHas('inventory_items', [
            'master_item_id' => $master1->id,
            'location_id'    => $newLoc->id,
            'qty_on_hand'    => 0,
        ]);
        $this->assertDatabaseHas('inventory_items', [
            'master_item_id' => $master2->id,
            'location_id'    => $newLoc->id,
            'qty_on_hand'    => 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 5 — syncNewLocation() idempotency
    //
    // If a stock row already exists for a given master+location pair,
    // syncNewLocation() must skip it — never create a duplicate.
    // This protects against race conditions or double-calls.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function sync_new_location_skips_existing_stock_rows(): void
    {
        $master = $this->makeMasterItem();

        $newLoc = InventoryLocation::create([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'Repeat Warehouse',
            'name_ar'    => 'مستودع متكرر',
            'type'       => 'warehouse',
            'is_active'  => true,
        ]);

        // Pre-create the stock row manually
        InventoryItem::create([
            'company_id'     => $this->companyId,
            'farm_id'        => null,
            'location_id'    => $newLoc->id,
            'master_item_id' => $master->id,
            'is_master'      => false,
            'category'       => $master->category,
            'unit'           => $master->unit,
            'unit_cost'      => 0,
            'qty_on_hand'    => 50, // non-zero to detect if it gets overwritten
            'is_active'      => true,
        ]);

        // Calling sync should NOT create a second row or overwrite the first
        $this->svc->syncNewLocation($newLoc);

        $count = InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $newLoc->id)
            ->count();

        $this->assertEquals(1, $count, 'Should not create duplicate stock row');

        // Existing qty must be preserved
        $row = InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $newLoc->id)
            ->first();

        $this->assertEquals(50, (float) $row->qty_on_hand, 'Existing qty must not be reset to zero');
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 6 — syncNewLocation() inactive items skipped
    //
    // Master items with is_active = false are retired stock-keeping
    // units. A new location should not get stock rows for them.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function sync_new_location_skips_inactive_master_items(): void
    {
        $activeMaster   = $this->makeMasterItem(['name_en' => 'Active Feed',   'is_active' => true]);
        $inactiveMaster = $this->makeMasterItem(['name_en' => 'Retired Feed',  'is_active' => false]);

        $newLoc = InventoryLocation::create([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'New Store',
            'name_ar'    => 'مخزن جديد',
            'type'       => 'store_room',
            'is_active'  => true,
        ]);

        $this->svc->syncNewLocation($newLoc);

        // Active master → stock row created
        $this->assertDatabaseHas('inventory_items', [
            'master_item_id' => $activeMaster->id,
            'location_id'    => $newLoc->id,
        ]);

        // Inactive master → no stock row
        $this->assertDatabaseMissing('inventory_items', [
            'master_item_id' => $inactiveMaster->id,
            'location_id'    => $newLoc->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 7 — recordTransfer() happy path
    //
    // After a valid transfer of 30 units from Location A to B:
    //   source qty_on_hand must decrease by 30
    //   dest   qty_on_hand must increase by 30
    //   net stock in the company must remain unchanged
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_decrements_source_and_increments_destination(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario(
            sourceQty: 100.0, destQty: 20.0
        );

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              30.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $this->assertEqualsWithDelta(70.0, (float) $sourceStock->fresh()->qty_on_hand, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $destStock->fresh()->qty_on_hand,   0.001);
    }

    /** @test */
    public function transfer_preserves_total_stock_in_company(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario(
            sourceQty: 100.0, destQty: 20.0
        );

        $totalBefore = 100.0 + 20.0;

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              30.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $totalAfter = InventoryItem::where('master_item_id', $master->id)
            ->where('is_master', false)
            ->sum('qty_on_hand');

        $this->assertEqualsWithDelta($totalBefore, (float) $totalAfter, 0.001);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 8 — recordTransfer() transaction rows
    //
    // Exactly two InventoryTransaction rows must be created:
    //   one 'transfer_out' on the source side
    //   one 'transfer_in'  on the destination side
    // Both must share the same transfer_batch_ref (audit trail).
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_creates_exactly_two_transaction_rows(): void
    {
        [$master] = $this->makeTransferScenario();

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              10.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    /** @test */
    public function transfer_creates_transfer_out_and_transfer_in_types(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario();

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              10.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $this->assertDatabaseHas('inventory_transactions', [
            'type'        => 'transfer_out',
            'location_id' => $this->locAId,
            'item_id'     => $sourceStock->id,
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'type'        => 'transfer_in',
            'location_id' => $this->locBId,
            'item_id'     => $destStock->id,
        ]);
    }

    /** @test */
    public function transfer_both_rows_share_the_same_batch_ref(): void
    {
        [$master] = $this->makeTransferScenario();

        $result = $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              10.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $batchRef = $result['batch_ref'];
        $this->assertNotEmpty($batchRef);

        $count = InventoryTransaction::where('transfer_batch_ref', $batchRef)->count();
        $this->assertEquals(2, $count, 'Both rows must share the same transfer_batch_ref');
    }

    /** @test */
    public function transfer_rows_have_correct_counterpart_location_ids(): void
    {
        [$master] = $this->makeTransferScenario();

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              10.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        // The transfer_out row points to the destination as counterpart
        $this->assertDatabaseHas('inventory_transactions', [
            'type'                    => 'transfer_out',
            'location_id'             => $this->locAId,
            'counterpart_location_id' => $this->locBId,
        ]);

        // The transfer_in row points back to the source as counterpart
        $this->assertDatabaseHas('inventory_transactions', [
            'type'                    => 'transfer_in',
            'location_id'             => $this->locBId,
            'counterpart_location_id' => $this->locAId,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 9 — recordTransfer() qty_after accuracy
    //
    // The qty_after column on each transaction row is a running
    // balance snapshot — it must reflect the post-transfer balance,
    // not the pre-transfer balance.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_out_row_has_correct_qty_after(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 100.0, destQty: 20.0);

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              30.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $outTx = InventoryTransaction::where('type', 'transfer_out')->first();
        $this->assertEqualsWithDelta(70.0, (float) $outTx->qty_after, 0.001,
            'transfer_out qty_after should be 100 - 30 = 70');
    }

    /** @test */
    public function transfer_in_row_has_correct_qty_after(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 100.0, destQty: 20.0);

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              30.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $inTx = InventoryTransaction::where('type', 'transfer_in')->first();
        $this->assertEqualsWithDelta(50.0, (float) $inTx->qty_after, 0.001,
            'transfer_in qty_after should be 20 + 30 = 50');
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 10 — recordTransfer() insufficient stock guard
    //
    // Attempting to transfer more than the source has must throw
    // a ValidationException BEFORE any data is written.
    // Stock on both sides must remain exactly as before.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_throws_when_source_has_insufficient_stock(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 50.0, destQty: 10.0);

        $this->expectException(ValidationException::class);

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              60.0,  // 10 more than available
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );
    }

    /** @test */
    public function transfer_leaves_stock_unchanged_when_guard_fires(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario(
            sourceQty: 50.0, destQty: 10.0
        );

        try {
            $this->svc->recordTransfer(
                masterItemId:     $master->id,
                sourceLocationId: $this->locAId,
                destLocationId:   $this->locBId,
                qty:              60.0,
                date:             '2025-06-01',
                recordedBy:       $this->userId,
            );
        } catch (ValidationException) {
            // expected
        }

        $this->assertEqualsWithDelta(50.0, (float) $sourceStock->fresh()->qty_on_hand, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $destStock->fresh()->qty_on_hand,   0.001);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 11 — recordTransfer() zero / negative qty guard
    //
    // A zero-qty transfer would silently create ghost transactions.
    // A negative-qty transfer would reverse the direction invisibly.
    // Both must be rejected before any data is touched.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_rejects_zero_qty(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 100.0);

        $this->expectException(ValidationException::class);

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              0.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );
    }

    /** @test */
    public function transfer_rejects_negative_qty(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 100.0);

        $this->expectException(ValidationException::class);

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              -5.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );
    }

    /** @test */
    public function transfer_guard_fires_before_any_db_write_for_zero_qty(): void
    {
        [$master] = $this->makeTransferScenario(sourceQty: 100.0);

        try {
            $this->svc->recordTransfer(
                masterItemId:     $master->id,
                sourceLocationId: $this->locAId,
                destLocationId:   $this->locBId,
                qty:              0.0,
                date:             '2025-06-01',
                recordedBy:       $this->userId,
            );
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 12 — recordTransfer() exact depletion
    //
    // Transferring exactly qty_on_hand (draining the source to zero)
    // is a valid and common operation — e.g. consolidating stock
    // before closing a location.
    // Source lands at exactly 0.000, destination increases fully.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_succeeds_when_qty_equals_source_balance(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario(
            sourceQty: 75.0, destQty: 25.0
        );

        // Transfer exactly the source balance
        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              75.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $this->assertEqualsWithDelta(0.0,   (float) $sourceStock->fresh()->qty_on_hand, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $destStock->fresh()->qty_on_hand,   0.001);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 13 — recordTransfer() rollback on failure
    //
    // The entire transfer is wrapped in DB::transaction().
    // If any step fails after stock has been decremented,
    // the transaction must roll back completely — no partial state.
    //
    // We simulate this by making the InventoryTransaction::create()
    // fail with a RuntimeException thrown from a DB listener.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_rolls_back_stock_changes_if_transaction_row_fails(): void
    {
        [$master, $sourceStock, $destStock] = $this->makeTransferScenario(
            sourceQty: 100.0, destQty: 20.0
        );

        // Force failure after the first transaction insert by listening
        // to the 'creating' event on InventoryTransaction and throwing
        // on the second call (which would be the transfer_in row).
        $callCount = 0;
        InventoryTransaction::creating(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new \RuntimeException('Simulated DB failure on transfer_in');
            }
        });

        try {
            $this->svc->recordTransfer(
                masterItemId:     $master->id,
                sourceLocationId: $this->locAId,
                destLocationId:   $this->locBId,
                qty:              30.0,
                date:             '2025-06-01',
                recordedBy:       $this->userId,
            );
        } catch (\RuntimeException) {
            // expected
        }

        // Stock must be exactly as before — rollback restored both sides
        $this->assertEqualsWithDelta(100.0, (float) $sourceStock->fresh()->qty_on_hand, 0.001,
            'Source stock must be restored after rollback');
        $this->assertEqualsWithDelta(20.0, (float) $destStock->fresh()->qty_on_hand, 0.001,
            'Dest stock must be unchanged after rollback');

        // No orphan transaction rows
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 14 — recordTransfer() total = qty × unit_cost (bcmath)
    //
    // The total stored on both transaction rows must equal
    // qty × unit_cost computed precisely with bcmath.
    //
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_total_equals_qty_times_unit_cost(): void
    {
        [$master, $sourceStock] = $this->makeTransferScenario(
            sourceQty: 100.0, destQty: 0.0, unitCost: 45.50
        );

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              20.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        // Expected: 20 × 45.50 = 910.00
        $expectedTotal = (float) \App\Support\Money::round(bcmul('20', '45.5', 4), 2);

        $outTx = InventoryTransaction::where('type', 'transfer_out')->first();
        $inTx  = InventoryTransaction::where('type', 'transfer_in')->first();

        $this->assertEqualsWithDelta($expectedTotal, (float) $outTx->total, 0.005);
        $this->assertEqualsWithDelta($expectedTotal, (float) $inTx->total,  0.005);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 15 — syncMasterEdit()
    //
    // When the master item is deactivated (is_active = false),
    // all location-stock rows for that master must also become
    // inactive — effectively hiding the item across all locations.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function sync_master_edit_deactivates_all_location_rows(): void
    {
        $master = $this->makeMasterItem(['is_active' => true]);
        $this->svc->fanOutNewItem($master);

        // Confirm two active stock rows exist
        $this->assertEquals(2, InventoryItem::where('master_item_id', $master->id)
            ->where('is_active', true)->count());

        // Deactivate the master
        $master->update(['is_active' => false]);
        $this->svc->syncMasterEdit($master);

        // All stock rows must now be inactive
        $this->assertEquals(0, InventoryItem::where('master_item_id', $master->id)
            ->where('is_active', true)->count());

        $this->assertEquals(2, InventoryItem::where('master_item_id', $master->id)
            ->where('is_active', false)->count());
    }

    /** @test */
    public function sync_master_edit_reactivates_all_location_rows(): void
    {
        $master = $this->makeMasterItem(['is_active' => false]);
        $this->svc->fanOutNewItem($master);

        // Reactivate master
        $master->update(['is_active' => true]);
        $this->svc->syncMasterEdit($master);

        $this->assertEquals(2, InventoryItem::where('master_item_id', $master->id)
            ->where('is_active', true)->count());
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 16 — getStockSummary() totals
    //
    // The total_qty on each master item in the summary must equal
    // the sum of qty_on_hand across all its location-stock rows.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function get_stock_summary_total_qty_is_sum_of_all_locations(): void
    {
        $master = $this->makeMasterItem(['name_en' => 'Corn Silage']);
        $this->svc->fanOutNewItem($master);

        // Give each location row some qty
        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locAId)
            ->update(['qty_on_hand' => 80.0]);

        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locBId)
            ->update(['qty_on_hand' => 45.0]);

        $summary = $this->svc->getStockSummary($this->companyId);

        $item = $summary->firstWhere('name_en', 'Corn Silage');
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(125.0, $item['total_qty'], 0.001);
    }

    /** @test */
    public function get_stock_summary_returns_per_location_breakdown(): void
    {
        $master = $this->makeMasterItem(['name_en' => 'Hay']);
        $this->svc->fanOutNewItem($master);

        $summary  = $this->svc->getStockSummary($this->companyId);
        $item     = $summary->firstWhere('name_en', 'Hay');

        $this->assertNotNull($item);
        $this->assertCount(2, $item['locations']);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 17 — getStockSummary() has_low_stock flag
    //
    // has_low_stock must be true only when at least one location's
    // qty_on_hand is at or below its reorder_point.
    // has_out_of_stock must be true when at least one location is zero.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function get_stock_summary_has_low_stock_false_when_all_above_reorder(): void
    {
        $master = $this->makeMasterItem(['name_en' => 'Vitamins', 'reorder_point' => 10.0]);
        $this->svc->fanOutNewItem($master);

        // Both locations well above reorder point
        InventoryItem::where('master_item_id', $master->id)->update([
            'qty_on_hand'  => 50.0,
            'reorder_point'=> 10.0,
        ]);

        $summary = $this->svc->getStockSummary($this->companyId);
        $item    = $summary->firstWhere('name_en', 'Vitamins');

        $this->assertFalse($item['has_low_stock']);
    }

    /** @test */
    public function get_stock_summary_has_low_stock_true_when_any_location_is_below_reorder(): void
    {
        $master = $this->makeMasterItem(['name_en' => 'Vaccines']);
        $this->svc->fanOutNewItem($master);

        // Location A: below reorder point
        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locAId)
            ->update(['qty_on_hand' => 3.0, 'reorder_point' => 10.0]);

        // Location B: fine
        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locBId)
            ->update(['qty_on_hand' => 50.0, 'reorder_point' => 10.0]);

        $summary = $this->svc->getStockSummary($this->companyId);
        $item    = $summary->firstWhere('name_en', 'Vaccines');

        $this->assertTrue($item['has_low_stock']);
    }

    /** @test */
    public function get_stock_summary_has_out_of_stock_true_when_any_location_is_zero(): void
    {
        $master = $this->makeMasterItem(['name_en' => 'Antibiotics']);
        $this->svc->fanOutNewItem($master);

        // Location A: zero
        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locAId)
            ->update(['qty_on_hand' => 0.0]);

        // Location B: has stock
        InventoryItem::where('master_item_id', $master->id)
            ->where('location_id', $this->locBId)
            ->update(['qty_on_hand' => 100.0]);

        $summary = $this->svc->getStockSummary($this->companyId);
        $item    = $summary->firstWhere('name_en', 'Antibiotics');

        $this->assertTrue($item['has_out_of_stock']);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 18 — bcmath precision
    //
    // A transfer involving a repeating-decimal unit cost must
    // produce a total that is arithmetically exact — no float drift.
    //
    // Classic example: 100 EGP / 3 units = 33.3333... EGP per unit
    // Transferring 3 units should yield total = 100.00, not 99.99 or 100.01
    //
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function transfer_total_is_exact_with_repeating_decimal_unit_cost(): void
    {
        // WAC = 100/3 = 33.333... EGP per unit
        $unitCost = (float) bcdiv('100', '3', 4); // = 33.3333

        [$master] = $this->makeTransferScenario(
            sourceQty: 9.0, destQty: 0.0, unitCost: $unitCost
        );

        $this->svc->recordTransfer(
            masterItemId:     $master->id,
            sourceLocationId: $this->locAId,
            destLocationId:   $this->locBId,
            qty:              3.0,
            date:             '2025-06-01',
            recordedBy:       $this->userId,
        );

        $outTx = InventoryTransaction::where('type', 'transfer_out')->first();

        // bcmath: 3 × 33.3333 = 99.9999 → rounded to 2dp = 100.00
        // PHP float: 3 * 33.3333 = 99.999900000001... which rounds to 100.00 too,
        // but with compound operations the drift accumulates. bcmath stays exact.
        $this->assertEqualsWithDelta(100.00, (float) $outTx->total, 0.005,
            'Total with repeating-decimal WAC must be exact to 2dp');
    }

    /** @test */
    public function multiple_transfers_do_not_accumulate_float_drift(): void
    {
        // Each unit costs 1/3 EGP — classic repeating decimal
        $unitCost = (float) bcdiv('1', '3', 6); // = 0.333333

        [$master, $sourceStock] = $this->makeTransferScenario(
            sourceQty: 90.0, destQty: 0.0, unitCost: $unitCost
        );

        // Perform 10 transfers of 3 units each
        for ($i = 0; $i < 10; $i++) {
            // Re-fetch source to get current qty (it decrements each loop)
            $currentSourceQty = (float) InventoryItem::find($sourceStock->id)->qty_on_hand;
            if ($currentSourceQty < 3.0) break;

            $this->svc->recordTransfer(
                masterItemId:     $master->id,
                sourceLocationId: $this->locAId,
                destLocationId:   $this->locBId,
                qty:              3.0,
                date:             '2025-06-01',
                recordedBy:       $this->userId,
            );
        }

        // After 10 × 3 = 30 units transferred, source should have exactly 60 left
        $this->assertEqualsWithDelta(60.0, (float) $sourceStock->fresh()->qty_on_hand, 0.001,
            'Repeated transfers must not accumulate qty drift');
    }

    // ══════════════════════════════════════════════════════════════
    // SCAFFOLD HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Build the minimum world shared by all tests:
     *   Company A (primary) + Company B (for scoping tests)
     *   Location A (active, company A)
     *   Location B (active, company A)
     *   Location Inactive (inactive, company A)
     *   One User
     */
    private function buildWorld(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name_en'    => 'Test Farm Co',
            'name_ar'    => 'شركة المزرعة',
            'country'    => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->companyBId = DB::table('companies')->insertGetId([
            'name_en'    => 'Other Co',
            'name_ar'    => 'شركة أخرى',
            'country'    => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locAId = DB::table('inventory_locations')->insertGetId([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'Central Warehouse A',
            'name_ar'    => 'المستودع المركزي أ',
            'type'       => 'warehouse',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locBId = DB::table('inventory_locations')->insertGetId([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'Central Warehouse B',
            'name_ar'    => 'المستودع المركزي ب',
            'type'       => 'warehouse',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locInactiveId = DB::table('inventory_locations')->insertGetId([
            'company_id' => $this->companyId,
            'farm_id'    => null,
            'name_en'    => 'Closed Store',
            'name_ar'    => 'مخزن مغلق',
            'type'       => 'store_room',
            'is_active'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'company_id' => $this->companyId,
            'name'       => 'Test User',
            'email'      => 'test@vero.test',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create a master InventoryItem with sensible defaults.
     * Does NOT call fanOutNewItem() — tests that need stock rows
     * must call it explicitly or use makeTransferScenario().
     */
    private function makeMasterItem(array $overrides = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'company_id'    => $this->companyId,
            'farm_id'       => null,
            'location_id'   => null,
            'master_item_id'=> null,
            'is_master'     => true,
            // The inventory_items table has unique indexes on (company, category, name_en)
            // and (company, category, name_ar) — so two masters in one test need different
            // names in BOTH languages, even when a test only overrides name_en.
            'name_en'       => 'Test Feed Item ' . uniqid(),
            'name_ar'       => 'عنصر علف تجريبي ' . uniqid(),
            'sku'           => 'FEED-' . uniqid(),
            'category'      => 'feed',
            'unit'          => 'kg',
            'unit_cost'     => 20.00,
            'qty_on_hand'   => 0,
            'reorder_point' => null,
            'is_active'     => true,
        ], $overrides));
    }

    /**
     * Create a master item + two location-stock rows pre-loaded
     * with specific quantities, ready for a transfer test.
     *
     * Returns: [$master, $sourceStockRow, $destStockRow]
     */
    private function makeTransferScenario(
        float $sourceQty = 100.0,
        float $destQty   = 20.0,
        float $unitCost  = 25.00
    ): array {
        $master = $this->makeMasterItem(['unit_cost' => $unitCost]);

        // Create source stock row (Location A)
        $sourceStock = InventoryItem::create([
            'company_id'     => $this->companyId,
            'farm_id'        => null,
            'location_id'    => $this->locAId,
            'master_item_id' => $master->id,
            'is_master'      => false,
            'category'       => $master->category,
            'unit'           => $master->unit,
            'unit_cost'      => $unitCost,
            'qty_on_hand'    => $sourceQty,
            'is_active'      => true,
        ]);

        // Create destination stock row (Location B)
        $destStock = InventoryItem::create([
            'company_id'     => $this->companyId,
            'farm_id'        => null,
            'location_id'    => $this->locBId,
            'master_item_id' => $master->id,
            'is_master'      => false,
            'category'       => $master->category,
            'unit'           => $master->unit,
            'unit_cost'      => $unitCost,
            'qty_on_hand'    => $destQty,
            'is_active'      => true,
        ]);

        return [$master, $sourceStock, $destStock];
    }
}
