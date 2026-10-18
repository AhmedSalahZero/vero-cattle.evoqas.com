<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\FeedMixingBatch;
use App\Models\FeedMixingLine;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FeedMixingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FeedMixingService Feature Tests
//
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//  📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//
//  FILE PLACEMENT: tests/Feature/Services/FeedMixingServiceTest.php
//
//  Worked example used throughout (same as the approved methodology):
//    Hay          1,000 kg @ 4.00  = 4,000   (Feed 1320)
//    Concentrate    500 kg @ 10.00 = 5,000   (Feed 1320)
//    Vitamins        10 kg @ 50.00 =   500   (Medicine 1330)
//    Batch cost = 9,500   Ingredients = 1,510 kg
//    Finished 1,480 kg  →  cost per kg = 6.4189
//
//  Groups:
//    1.  start()   stock leaves raw inventory, cost from WAC
//    2.  start()   journal entry Dr 1360 / Cr ingredient accounts
//    3.  start()   guards — stock, fiscal year, account, state, no lines
//    4.  finish()  mix enters stock, cost per kg, WAC blend
//    5.  finish()  journal entry Dr 1320 / Cr 1360, WIP nets to zero
//    6.  finish()  guards
//    7.  cancel()  stock returned at original cost, mirror journal
//    8.  cancel()  guards
//    9.  pure maths and precision
// ══════════════════════════════════════════════════════════════════

class FeedMixingServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeedMixingService $svc;

    private int $companyId;
    private int $farmId;
    private int $locationId;

    private int $hayId;
    private int $concId;
    private int $vitId;
    private int $mixId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FeedMixingService();
        $this->scaffoldWorld();
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 1 — start(): stock leaves raw inventory
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function start_reduces_each_ingredient_stock_by_the_exact_quantity(): void
    {
        $batch = $this->standardBatch();

        $this->svc->start($batch);

        $this->assertEqualsWithDelta(0.0,   $this->qty($this->hayId),  0.0001);
        $this->assertEqualsWithDelta(0.0,   $this->qty($this->concId), 0.0001);
        $this->assertEqualsWithDelta(40.0,  $this->qty($this->vitId),  0.0001);  // 50 - 10
    }

    /** @test */
    public function start_takes_cost_from_stock_wac_and_totals_the_batch(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $this->assertSame('in_progress', $batch->status);
        $this->assertEqualsWithDelta(9500.00, (float) $batch->total_cost, 0.001);
        $this->assertEqualsWithDelta(1510.0,  (float) $batch->total_ingredient_kg, 0.001);

        $hayLine = $batch->lines->firstWhere('item_id', $this->hayId);
        $this->assertEqualsWithDelta(4.0,    (float) $hayLine->unit_cost,  0.0001);
        $this->assertEqualsWithDelta(4000.0, (float) $hayLine->total_cost, 0.001);
        $this->assertSame('1320', $hayLine->account_code);
    }

    /** @test */
    public function start_writes_one_mix_out_transaction_per_ingredient(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $rows = InventoryTransaction::where('ref_type', FeedMixingBatch::class)
            ->where('ref_id', $batch->id)->where('type', 'mix_out')->get();

        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(9500.0, (float) $rows->sum('total'), 0.001);

        $hay = $rows->firstWhere('item_id', $this->stockRowId($this->hayId));
        $this->assertNotNull($hay, 'Transaction must point at the location stock row, not the master');
        $this->assertEqualsWithDelta(0.0, (float) $hay->qty_after, 0.0001);
    }

    /** @test */
    public function start_ignores_nonkg_ingredients_in_the_kg_total_but_keeps_their_cost(): void
    {
        $ml = $this->makeMaster('Liquid Additive', 'medicine', 'ml');
        $this->makeStock($ml, 100, 2.0);

        $batch = $this->makeBatch([[$this->hayId, 100], [$ml, 50]]);
        $batch = $this->svc->start($batch);

        $this->assertEqualsWithDelta(100.0, (float) $batch->total_ingredient_kg, 0.001); // hay only
        $this->assertEqualsWithDelta(500.0, (float) $batch->total_cost, 0.001);          // 400 + 100
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 2 — start(): journal entry
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function start_posts_balanced_entry_debiting_wip_and_crediting_ingredient_accounts(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $je = JournalEntry::with('lines.account')->findOrFail($batch->start_journal_entry_id);

        $this->assertSame('posted', $je->status);
        $this->assertSame('feed_mixing', $je->entry_type);
        $this->assertEqualsWithDelta((float) $je->lines->sum('debit'), (float) $je->lines->sum('credit'), 0.005);

        $this->assertEqualsWithDelta(9500.0, $this->lineSum($je, '1360', 'debit'),  0.001);
        $this->assertEqualsWithDelta(9000.0, $this->lineSum($je, '1320', 'credit'), 0.001);
        $this->assertEqualsWithDelta(500.0,  $this->lineSum($je, '1330', 'credit'), 0.001);
    }

    /** @test */
    public function start_journal_is_dated_on_the_batch_start_date(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $je = JournalEntry::findOrFail($batch->start_journal_entry_id);

        $this->assertSame('2025-06-10', $je->date->toDateString());
        $this->assertSame(6, (int) $je->period);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 3 — start(): guards (nothing may change when blocked)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function start_blocks_when_any_ingredient_is_short_and_changes_nothing(): void
    {
        $this->setQty($this->concId, 100);              // needs 500

        $batch = $this->standardBatch();

        $this->expectBlocked(fn () => $this->svc->start($batch));

        $this->assertEqualsWithDelta(1000.0, $this->qty($this->hayId), 0.0001, 'Hay must not move');
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertSame(0, InventoryTransaction::where('ref_id', $batch->id)->count());
        $this->assertSame(0, JournalEntry::where('source_id', $batch->id)->count());
    }

    /** @test */
    public function start_blocks_when_no_open_fiscal_year_covers_the_date(): void
    {
        FiscalYear::where('company_id', $this->companyId)->update(['status' => 'closed']);
        $batch = $this->standardBatch();

        $this->expectBlocked(fn () => $this->svc->start($batch), 'start_date');

        $this->assertEqualsWithDelta(1000.0, $this->qty($this->hayId), 0.0001);
        $this->assertSame('draft', $batch->fresh()->status);
    }

    /** @test */
    public function start_blocks_when_wip_account_1360_is_missing(): void
    {
        Account::where('company_id', $this->companyId)->where('code', '1360')->delete();
        $batch = $this->standardBatch();

        $this->expectBlocked(fn () => $this->svc->start($batch), 'accounts');

        $this->assertEqualsWithDelta(1000.0, $this->qty($this->hayId), 0.0001);
        $this->assertSame('draft', $batch->fresh()->status);
    }

    /** @test */
    public function start_blocks_when_there_is_no_stock_row_at_the_location(): void
    {
        $other = $this->makeMaster('Straw', 'feed', 'kg');       // never stocked
        $batch = $this->makeBatch([[$other, 10]]);

        $this->expectBlocked(fn () => $this->svc->start($batch));
        $this->assertSame('draft', $batch->fresh()->status);
    }

    /** @test */
    public function start_cannot_run_twice_on_the_same_batch(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $this->expectBlocked(fn () => $this->svc->start($batch), 'status');

        $this->assertEqualsWithDelta(40.0, $this->qty($this->vitId), 0.0001, 'No second deduction');
        $this->assertSame(1, JournalEntry::where('source_id', $batch->id)->count());
    }

    /** @test */
    public function start_blocks_a_batch_with_no_ingredients(): void
    {
        $batch = $this->makeBatch([]);
        $this->expectBlocked(fn () => $this->svc->start($batch), 'lines');
    }

    /** @test */
    public function start_blocks_when_ingredients_have_no_cost(): void
    {
        $this->setCost($this->hayId, 0);
        $batch = $this->makeBatch([[$this->hayId, 10]]);

        $this->expectBlocked(fn () => $this->svc->start($batch), 'lines');
        $this->assertSame('draft', $batch->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 4 — finish(): mix enters stock
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function finish_adds_finished_kg_to_mix_stock_and_sets_cost_per_kg(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $batch = $this->svc->finish($batch, '1480', '2025-06-11');

        $this->assertSame('completed', $batch->status);
        $this->assertEqualsWithDelta(1480.0, $this->qty($this->mixId), 0.0001);
        $this->assertEqualsWithDelta(6.4189, (float) $batch->cost_per_kg, 0.00005);
        $this->assertEqualsWithDelta(6.4189, $this->cost($this->mixId), 0.00005);   // empty stock → WAC = cost/kg
        $this->assertSame('2025-06-11', $batch->finish_date->toDateString());
    }

    /** @test */
    public function finish_blends_with_existing_mix_stock_using_weighted_average(): void
    {
        // The scaffold already created an empty mix stock row — set it to 200 kg @ 6.00
        $this->setQty($this->mixId, 200);
        $this->setCost($this->mixId, 6.00);

        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        $this->assertEqualsWithDelta(1680.0, $this->qty($this->mixId), 0.0001);
        $this->assertEqualsWithDelta(6.3690, $this->cost($this->mixId), 0.00005);    // 10,700 ÷ 1,680
    }

    /** @test */
    public function finish_writes_a_mix_in_transaction_on_the_mix_stock_row(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        $tx = InventoryTransaction::where('type', 'mix_in')->where('ref_id', $batch->id)->first();

        $this->assertNotNull($tx);
        $this->assertSame($this->stockRowId($this->mixId), (int) $tx->item_id);
        $this->assertEqualsWithDelta(1480.0, (float) $tx->qty, 0.0001);
        $this->assertEqualsWithDelta(9500.0, (float) $tx->total, 0.001);
        $this->assertEqualsWithDelta(1480.0, (float) $tx->qty_after, 0.0001);
    }

    /** @test */
    public function finish_creates_the_mix_stock_row_when_the_location_has_none(): void
    {
        InventoryItem::where('master_item_id', $this->mixId)->where('is_master', false)->delete();

        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        $this->assertEqualsWithDelta(1480.0, $this->qty($this->mixId), 0.0001);
    }

    /** @test */
    public function finish_allows_more_kg_than_ingredients_because_water_can_be_added(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $batch = $this->svc->finish($batch, '1600', '2025-06-11');

        $this->assertSame('completed', $batch->status);
        $this->assertEqualsWithDelta(5.9375, (float) $batch->cost_per_kg, 0.00005);   // 9,500 ÷ 1,600
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 5 — finish(): journal entry and WIP balance
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function finish_posts_balanced_entry_debiting_feed_inventory_and_crediting_wip(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $batch = $this->svc->finish($batch, '1480', '2025-06-11');

        $je = JournalEntry::with('lines.account')->findOrFail($batch->finish_journal_entry_id);

        $this->assertSame('posted', $je->status);
        $this->assertEqualsWithDelta(9500.0, $this->lineSum($je, '1320', 'debit'), 0.001);
        $this->assertEqualsWithDelta(9500.0, $this->lineSum($je, '1360', 'credit'), 0.001);
        $this->assertEqualsWithDelta((float) $je->lines->sum('debit'), (float) $je->lines->sum('credit'), 0.005);
    }

    /** @test */
    public function wip_account_is_zero_after_a_completed_batch(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $this->assertEqualsWithDelta(9500.0, $this->accountBalance('1360'), 0.001, 'WIP holds the cost while mixing');

        $this->svc->finish($batch, '1480', '2025-06-11');

        $this->assertEqualsWithDelta(0.0, $this->accountBalance('1360'), 0.001, 'WIP is cleared on finish');
    }

    /** @test */
    public function total_inventory_value_is_conserved_across_start_and_finish(): void
    {
        $before = $this->accountBalance('1320') + $this->accountBalance('1330');

        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        // Raw items (1320/1330) went down by 9,500; finished mix (1320) came back up by 9,500
        $after = $this->accountBalance('1320') + $this->accountBalance('1330');
        $this->assertEqualsWithDelta($before, $after, 0.001);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 6 — finish(): guards
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function finish_blocks_zero_and_negative_quantities(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $this->expectBlocked(fn () => $this->svc->finish($batch, '0', '2025-06-11'), 'finished_qty_kg');
        $this->expectBlocked(fn () => $this->svc->finish($batch, '-5', '2025-06-11'), 'finished_qty_kg');

        $this->assertSame('in_progress', $batch->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->qty($this->mixId), 0.0001);
    }

    /** @test */
    public function finish_blocks_a_batch_that_was_never_started(): void
    {
        $batch = $this->standardBatch();
        $this->expectBlocked(fn () => $this->svc->finish($batch, '100', '2025-06-11'), 'status');
    }

    /** @test */
    public function finish_cannot_run_twice(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        $this->expectBlocked(fn () => $this->svc->finish($batch, '1480', '2025-06-11'), 'status');
        $this->assertEqualsWithDelta(1480.0, $this->qty($this->mixId), 0.0001, 'No double receipt');
    }

    /** @test */
    public function finish_blocks_a_date_before_the_start_date(): void
    {
        $batch = $this->svc->start($this->standardBatch());   // starts 2025-06-10
        $this->expectBlocked(fn () => $this->svc->finish($batch, '1480', '2025-06-01'), 'finish_date');
    }

    /** @test */
    public function finish_blocks_when_no_open_fiscal_year_covers_the_finish_date(): void
    {
        $batch = $this->svc->start($this->standardBatch());

        $this->expectBlocked(fn () => $this->svc->finish($batch, '1480', '2026-02-01'), 'finish_date');

        $this->assertSame('in_progress', $batch->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->qty($this->mixId), 0.0001);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 7 — cancel(): undo START
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function cancel_returns_every_ingredient_to_stock(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $batch = $this->svc->cancel($batch, '2025-06-12');

        $this->assertSame('cancelled', $batch->status);
        $this->assertEqualsWithDelta(1000.0, $this->qty($this->hayId),  0.0001);
        $this->assertEqualsWithDelta(500.0,  $this->qty($this->concId), 0.0001);
        $this->assertEqualsWithDelta(50.0,   $this->qty($this->vitId),  0.0001);
    }

    /** @test */
    public function cancel_restores_the_original_unit_cost(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->svc->cancel($batch, '2025-06-12');

        $this->assertEqualsWithDelta(4.0,  $this->cost($this->hayId),  0.0001);
        $this->assertEqualsWithDelta(10.0, $this->cost($this->concId), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->cost($this->vitId),  0.0001);
    }

    /** @test */
    public function cancel_posts_a_mirror_entry_and_leaves_the_original_posted(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $batch = $this->svc->cancel($batch, '2025-06-12');

        $orig = JournalEntry::findOrFail($batch->start_journal_entry_id);
        $rev  = JournalEntry::with('lines.account')->findOrFail($batch->cancel_journal_entry_id);

        $this->assertSame('posted', $orig->status, 'Original stays posted so reports net to zero');
        $this->assertSame('posted', $rev->status);
        $this->assertSame('reversal', $rev->entry_type);

        $this->assertEqualsWithDelta(9500.0, $this->lineSum($rev, '1360', 'credit'), 0.001);
        $this->assertEqualsWithDelta(9000.0, $this->lineSum($rev, '1320', 'debit'),  0.001);
        $this->assertEqualsWithDelta(500.0,  $this->lineSum($rev, '1330', 'debit'),  0.001);
        $this->assertEqualsWithDelta((float) $rev->lines->sum('debit'), (float) $rev->lines->sum('credit'), 0.005);
    }

    /** @test */
    public function wip_and_inventory_accounts_return_to_their_starting_balances_after_cancel(): void
    {
        $feedBefore = $this->accountBalance('1320');
        $medBefore  = $this->accountBalance('1330');

        $batch = $this->svc->start($this->standardBatch());
        $this->svc->cancel($batch, '2025-06-12');

        $this->assertEqualsWithDelta(0.0,        $this->accountBalance('1360'), 0.001);
        $this->assertEqualsWithDelta($feedBefore, $this->accountBalance('1320'), 0.001);
        $this->assertEqualsWithDelta($medBefore,  $this->accountBalance('1330'), 0.001);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 8 — cancel(): guards
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function cancel_blocks_a_draft_batch(): void
    {
        $batch = $this->standardBatch();
        $this->expectBlocked(fn () => $this->svc->cancel($batch, '2025-06-12'), 'status');
    }

    /** @test */
    public function cancel_blocks_a_completed_batch_and_keeps_the_mix_in_stock(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->svc->finish($batch, '1480', '2025-06-11');

        $this->expectBlocked(fn () => $this->svc->cancel($batch, '2025-06-12'), 'status');
        $this->assertEqualsWithDelta(1480.0, $this->qty($this->mixId), 0.0001);
    }

    /** @test */
    public function cancel_cannot_run_twice(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->svc->cancel($batch, '2025-06-12');

        $this->expectBlocked(fn () => $this->svc->cancel($batch, '2025-06-12'), 'status');
        $this->assertEqualsWithDelta(1000.0, $this->qty($this->hayId), 0.0001, 'No double return');
    }

    /** @test */
    public function cancel_blocks_a_date_before_the_start_date(): void
    {
        $batch = $this->svc->start($this->standardBatch());
        $this->expectBlocked(fn () => $this->svc->cancel($batch, '2025-06-01'), 'cancel_date');
        $this->assertSame('in_progress', $batch->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 9 — pure maths and precision (📌 BcmathMonetaryPrecision)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function round2_rounds_half_away_from_zero(): void
    {
        $this->assertSame('0.01', $this->svc->round2('0.005'));
        $this->assertSame('1.00', $this->svc->round2('1.0049'));
        $this->assertSame('1.01', $this->svc->round2('1.0050'));
        $this->assertSame('-0.01', $this->svc->round2('-0.005'));
    }

    /** @test */
    public function cost_per_kg_and_new_wac_match_the_approved_worked_example(): void
    {
        $this->assertSame('6.4189', $this->svc->costPerKg('9500.00', '1480.000'));
        $this->assertSame('6.3690', $this->svc->newWac('200.000', '6.0000', '9500.00', '1480.000'));
    }

    /** @test */
    public function repeating_decimals_do_not_unbalance_the_journal(): void
    {
        // 100 kg @ 3.3333 → 333.33 ; 7 kg @ 1.1111 → 7.78  (both need rounding)
        $this->setCost($this->hayId, 3.3333);
        $this->setCost($this->concId, 1.1111);
        $this->setQty($this->hayId, 100);
        $this->setQty($this->concId, 7);

        $batch = $this->svc->start($this->makeBatch([[$this->hayId, 100], [$this->concId, 7]]));
        $je = JournalEntry::with('lines')->findOrFail($batch->start_journal_entry_id);

        $this->assertEqualsWithDelta(341.11, (float) $batch->total_cost, 0.001);
        $this->assertEqualsWithDelta((float) $je->lines->sum('debit'), (float) $je->lines->sum('credit'), 0.0001);

        // Finish at an awkward weight — value entering stock must equal value leaving WIP
        $this->svc->finish($batch, '97.777', '2025-06-11');
        $this->assertEqualsWithDelta(0.0, $this->accountBalance('1360'), 0.0001);
    }

    // ══════════════════════════════════════════════════════════════
    // Scaffold and helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * Builds the test world with DIRECT inserts — no model factories.
     * (Only UserFactory exists in this project; the passing suites
     *  FinancialReport / InventoryLocation use the same direct approach.)
     */
    private function scaffoldWorld(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name_en'    => 'Feed Mixing Test Co',
            'name_ar'    => 'شركة اختبار الخلط',
            'country'    => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'company_id' => $this->companyId,
            'name'       => 'Mixing Tester',
            'email'      => 'mixing-test-' . uniqid() . '@vero.test',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login(User::findOrFail($userId));

        $this->farmId = DB::table('farms')->insertGetId([
            'company_id' => $this->companyId,
            'name_en'    => 'Test Farm',
            'name_ar'    => 'مزرعة اختبار',
            'country'    => 'EG',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locationId = DB::table('inventory_locations')->insertGetId([
            'company_id' => $this->companyId,
            'farm_id'    => $this->farmId,
            'name_en'    => 'Feed Store',
            'name_ar'    => 'مخزن العلف',
            'type'       => 'warehouse',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fiscal_years')->insert([
            'company_id' => $this->companyId,
            'name'       => 'FY2025',
            'year'       => 2025,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['1320', '1330', '1360'] as $code) {
            $this->makeAccount($code, 'asset', 'debit');
        }

        // Ingredients (stocked) and the finished mix (stock row created empty)
        $this->hayId  = $this->makeMaster('Hay', 'feed', 'kg');
        $this->concId = $this->makeMaster('Concentrate', 'feed', 'kg');
        $this->vitId  = $this->makeMaster('Vitamins', 'medicine', 'kg');
        $this->mixId  = $this->makeMaster('Fattening Mix', 'feed', 'kg');

        $this->makeStock($this->hayId,  1000, 4.00);
        $this->makeStock($this->concId, 500,  10.00);
        $this->makeStock($this->vitId,  50,   50.00);
        $this->makeStock($this->mixId,  0,    0.00);

        // Opening balances so inventory accounts have a starting value to conserve
        $this->openingBalance('1320', 9000.00);   // hay + concentrate
        $this->openingBalance('1330', 2500.00);   // vitamins 50 × 50
    }

    private function makeAccount(string $code, string $type, string $normalBalance): int
    {
        return DB::table('accounts')->insertGetId([
            'company_id'           => $this->companyId,
            'code'                 => $code,
            'name_en'              => 'Account ' . $code,
            'name_ar'              => 'حساب ' . $code,
            'type'                 => $type,
            'normal_balance'       => $normalBalance,
            'allow_direct_posting' => 1,
            'is_active'            => 1,
            'is_system'            => 0,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    private function makeMaster(string $name, string $category, string $unit): int
    {
        return InventoryItem::create([
            'company_id'     => $this->companyId,
            'farm_id'        => null,
            'location_id'    => null,
            'master_item_id' => null,
            'is_master'      => true,
            'name_en'        => $name,
            'sku'            => strtoupper(substr($category, 0, 4)) . '-' . uniqid(),
            'category'       => $category,
            'unit'           => $unit,
            'unit_cost'      => 0,
            'qty_on_hand'    => 0,
            'is_active'      => true,
        ])->id;
    }

    private function makeStock(int $masterId, float $qty, float $cost): void
    {
        InventoryItem::create([
            'company_id'     => $this->companyId,
            'farm_id'        => $this->farmId,
            'location_id'    => $this->locationId,
            'master_item_id' => $masterId,
            'is_master'      => false,
            'qty_on_hand'    => $qty,
            'unit_cost'      => $cost,
            'is_active'      => true,
        ]);
    }

    private function stockRowId(int $masterId): int
    {
        return (int) InventoryItem::where('master_item_id', $masterId)
            ->where('location_id', $this->locationId)->where('is_master', false)->value('id');
    }

    private function qty(int $masterId): float
    {
        return (float) InventoryItem::find($this->stockRowId($masterId))->qty_on_hand;
    }

    private function cost(int $masterId): float
    {
        return (float) InventoryItem::find($this->stockRowId($masterId))->unit_cost;
    }

    private function setQty(int $masterId, float $qty): void
    {
        InventoryItem::where('id', $this->stockRowId($masterId))->update(['qty_on_hand' => $qty]);
    }

    private function setCost(int $masterId, float $cost): void
    {
        InventoryItem::where('id', $this->stockRowId($masterId))->update(['unit_cost' => $cost]);
    }

    /** @param array<int,array{0:int,1:float|int}> $lines [master_id, qty] */
    private function makeBatch(array $lines): FeedMixingBatch
    {
        $batch = FeedMixingBatch::create([
            'company_id'     => $this->companyId,
            'farm_id'        => $this->farmId,
            'location_id'    => $this->locationId,
            'output_item_id' => $this->mixId,
            'reference'      => 'MIX-TEST-' . uniqid(),
            'status'         => 'draft',
            'start_date'     => '2025-06-10',
        ]);

        foreach ($lines as [$itemId, $qty]) {
            FeedMixingLine::create(['batch_id' => $batch->id, 'item_id' => $itemId, 'qty' => $qty]);
        }

        return $batch;
    }

    /** The approved worked example: hay 1,000 + concentrate 500 + vitamins 10. */
    private function standardBatch(): FeedMixingBatch
    {
        return $this->makeBatch([
            [$this->hayId,  1000],
            [$this->concId, 500],
            [$this->vitId,  10],
        ]);
    }

    /** Post an opening entry straight to the ledger so balances are non-zero. */
    private function openingBalance(string $code, float $amount): void
    {
        $fy     = FiscalYear::where('company_id', $this->companyId)->first();
        $acct   = Account::where('company_id', $this->companyId)->where('code', $code)->first();
        $equityId = $this->makeAccount('3' . $code, 'equity', 'credit');   // throw-away equity account

        $je = JournalEntry::create([
            'company_id' => $this->companyId, 'fiscal_year_id' => $fy->id,
            'date' => '2025-01-01', 'reference' => 'OB-' . $code, 'description' => 'Opening',
            'period' => 1, 'status' => 'posted', 'entry_type' => 'opening_balance',
            'total_debit' => $amount, 'total_credit' => $amount,
            'posted_by' => Auth::id(), 'posted_at' => now(),
        ]);
        $je->lines()->create(['account_id' => $acct->id,   'debit' => $amount, 'credit' => 0, 'memo' => 'Opening']);
        $je->lines()->create(['account_id' => $equityId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Opening']);
    }

    /** Debit minus credit on an account, posted entries only (matches the reports). */
    private function accountBalance(string $code): float
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('a.company_id', $this->companyId)
            ->where('a.code', $code)
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.debit),0) as d, COALESCE(SUM(jl.credit),0) as c')
            ->first();

        return (float) $row->d - (float) $row->c;
    }

    private function lineSum(JournalEntry $je, string $code, string $side): float
    {
        return (float) $je->lines
            ->filter(fn ($l) => $l->account->code === $code)
            ->sum($side);
    }

    /** Assert the call is blocked with a ValidationException (optionally on a given field). */
    private function expectBlocked(callable $fn, ?string $field = null): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $e->errors(), "Expected an error on '{$field}'");
            }
            $this->assertTrue(true);
            return;
        }

        $this->fail('Expected the operation to be blocked with a ValidationException.');
    }
}
