<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  InventoryPurchaseFlowTest
//
//  Buying feed / medicine / vaccine, from order to invoice:
//    order (draft) -> confirm -> receive (stock + average cost + accrual)
//    -> supplier invoice matched -> Accounts Payable
//
//  Checks: stock and weighted-average cost after each receipt, the
//  balanced accrual entry on the right inventory account, partial /
//  full / over receipt status, force close, invoice matching with a
//  price difference, and the guards (draft order, other company,
//  view-only user, other company's items / store rooms).
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ApInvoice;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\ProcurementItemLine;
use App\Models\ProcurementRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class InventoryPurchaseFlowTest extends TestCase
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

    /** Create a draft inventory PO through the real route. */
    private function createPo(array $stk, string $qty = '100', string $price = '10', $supplier = null, $user = null)
    {
        $supplier ??= $this->s->supplier();

        $this->actingAs($user ?? $this->s->admin())->post(route('procurement.store-inventory-po'), [
            'farm_id'     => $this->s->farm->id,
            'supplier_id' => $supplier->id,
            'date'        => '2026-06-01',
            'item_lines'  => [[
                'inventory_item_id'   => $stk['master']->id,
                'location_id'         => $stk['location']->id,
                'qty_ordered'         => $qty,
                'unit_cost_estimated' => $price,
            ]],
        ]);

        return ProcurementRecord::where('po_type', 'inventory')->latest('id')->first();
    }

    private function openPo(array $stk, string $qty = '100', string $price = '10', $supplier = null): ProcurementRecord
    {
        $po = $this->createPo($stk, $qty, $price, $supplier);
        $this->actingAs($this->s->admin())
            ->patch(route('procurement.confirm-inventory-po', $po->id))
            ->assertSessionHasNoErrors();
        return $po->fresh();
    }

    private function receive(ProcurementRecord $po, array $stk, string $qty, string $cost, string $date = '2026-06-10', $user = null, $locationId = null)
    {
        $line = ProcurementItemLine::where('procurement_id', $po->id)->firstOrFail();

        return $this->actingAs($user ?? $this->s->admin())
            ->post(route('procurement.confirm-inventory-receipt', $po->id), [
                'batch_date' => $date,
                'lines'      => [[
                    'procurement_item_line_id' => $line->id,
                    'qty_received'             => $qty,
                    'unit_cost_actual'         => $cost,
                    'location_id'              => $locationId ?? $stk['location']->id,
                ]],
            ]);
    }

    private function invoice(array $data)
    {
        return $this->actingAs($this->s->admin())->post(route('ap-invoices.store'), array_merge([
            'date'         => '2026-06-20',
            'invoice_type' => 'inventory',
        ], $data));
    }

    // ── 1. the order ──────────────────────────────────────────────

    public function test_an_order_is_created_as_a_draft_with_its_lines_and_estimates(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->createPo($stk, '100', '10.5');

        $this->assertNotNull($po);
        $this->assertSame('draft', $po->po_status);

        $line = ProcurementItemLine::where('procurement_id', $po->id)->firstOrFail();
        $this->assertEquals(100, (float) $line->qty_ordered);
        $this->assertEquals(1050, (float) $line->total_estimated);
    }

    public function test_confirming_opens_the_order_and_gives_it_a_reference(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk);

        $this->assertSame('open', $po->po_status);
        $this->assertNotEmpty($po->po_reference);
    }

    public function test_an_order_cannot_be_confirmed_twice(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk);

        $this->actingAs($this->s->admin())
            ->patch(route('procurement.confirm-inventory-po', $po->id))
            ->assertStatus(422);
    }

    public function test_goods_cannot_be_received_against_a_draft_order(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->createPo($stk);

        $this->receive($po, $stk, '10', '10')->assertStatus(422);

        $this->assertEquals(0, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame(0, InventoryTransaction::where('type', 'receipt')->count());
    }

    // ── 2. receiving: stock, average cost, accrual ────────────────

    public function test_receiving_adds_stock_and_sets_the_cost(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, '100', '10');

        $this->receive($po, $stk, '100', '10')->assertSessionHasNoErrors();

        $stock = $stk['stock']->fresh();
        $this->assertEquals(100, (float) $stock->qty_on_hand);
        $this->assertEquals(10, (float) $stock->unit_cost);
        $this->assertSame('fully_received', $po->fresh()->po_status);
    }

    public function test_a_second_receipt_at_a_new_price_gives_the_weighted_average_cost(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, '300', '10');

        $this->receive($po, $stk, '100', '10')->assertSessionHasNoErrors();
        $this->receive($po, $stk, '100', '20')->assertSessionHasNoErrors();

        $stock = $stk['stock']->fresh();
        $this->assertEquals(200, (float) $stock->qty_on_hand);
        $this->assertEquals(15, (float) $stock->unit_cost);       // (100x10 + 100x20) / 200
        $this->assertSame('partially_received', $po->fresh()->po_status);
    }

    public function test_the_average_cost_is_rounded_to_four_decimals(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, '300', '10');

        $this->receive($po, $stk, '100', '10');
        $this->receive($po, $stk, '50', '11');                    // (1000 + 550) / 150 = 10.3333...

        $this->assertEquals(10.3333, round((float) $stk['stock']->fresh()->unit_cost, 4));
    }

    public function test_receiving_posts_a_balanced_accrual_to_the_right_inventory_account(): void
    {
        $feed = $this->s->stockedItem('feed', '0', '0');
        $med  = $this->s->stockedItem('medicine', '0', '0');
        $vac  = $this->s->stockedItem('vaccine', '0', '0');

        foreach ([[$feed, '1320'], [$med, '1330'], [$vac, '1340']] as [$stk, $code]) {
            $po = $this->openPo($stk, '10', '7');
            $this->receive($po, $stk, '10', '7')->assertSessionHasNoErrors();
            $this->assertSame('70.00', $this->s->net($code), "Account $code");
        }

        $this->assertSame('-210.00', $this->s->net('2230'));      // all three accrued

        foreach (JournalEntry::where('entry_type', 'procurement')->get() as $je) {
            $this->assertSame('posted', $je->status);
            $this->assertEquals((float) $je->total_debit, (float) $je->total_credit);
        }
    }

    public function test_the_receipt_is_dated_and_linked_to_its_entry(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk);
        $this->receive($po, $stk, '100', '10', '2026-06-12');

        $txn = InventoryTransaction::where('type', 'receipt')->firstOrFail();
        $this->assertNotNull($txn->journal_entry_id);
        $this->assertNull($txn->ap_invoice_id);                    // not invoiced yet
        $this->assertSame($po->supplier_id, (int) $txn->supplier_id);
        $this->assertEquals(1000, (float) $txn->total);
        $this->assertSame('2026-06-12', $txn->date->toDateString());
    }

    public function test_a_receipt_date_outside_any_open_year_is_refused(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk);

        $this->receive($po, $stk, '100', '10', '2025-06-10')->assertSessionHasErrors('batch_date');

        $this->assertEquals(0, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertSame(0, InventoryTransaction::where('type', 'receipt')->count());
    }

    public function test_receiving_into_a_location_without_a_stock_row_creates_it(): void
    {
        $stk   = $this->s->stockedItem('feed', '0', '0');
        $other = \App\Models\InventoryLocation::factory()->create([
            'company_id' => $this->s->company->id,
            'farm_id'    => $this->s->farm->id,
        ]);
        $po = $this->openPo($stk, '50', '10');

        $this->receive($po, $stk, '50', '10', '2026-06-10', null, $other->id)->assertSessionHasNoErrors();

        $row = \App\Models\InventoryItem::where('master_item_id', $stk['master']->id)
            ->where('location_id', $other->id)->where('is_master', false)->firstOrFail();
        $this->assertEquals(50, (float) $row->qty_on_hand);
        $this->assertEquals(10, (float) $row->unit_cost);
    }

    // ── 3. order status ───────────────────────────────────────────

    public function test_receiving_more_than_ordered_marks_the_order_over_received(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, '100', '10');

        $this->receive($po, $stk, '120', '10')->assertSessionHasNoErrors();

        $this->assertSame('over_received', $po->fresh()->po_status);
    }

    public function test_force_closing_needs_a_receipt_and_a_real_reason(): void
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, '100', '10');
        $admin = $this->s->admin();

        $this->actingAs($admin)->patch(route('procurement.force-close-inventory', $po->id), [
            'closure_reason' => 'Supplier cancelled the rest',
        ])->assertStatus(422);                                     // nothing received yet

        $this->receive($po, $stk, '40', '10');

        $this->actingAs($admin)->patch(route('procurement.force-close-inventory', $po->id), [
            'closure_reason' => 'short',
        ])->assertSessionHasErrors('closure_reason');

        $this->actingAs($admin)->patch(route('procurement.force-close-inventory', $po->id), [
            'closure_reason' => 'Supplier cancelled the rest',
        ])->assertSessionHasNoErrors();

        $this->assertSame('force_closed', $po->fresh()->po_status);
        $this->receive($po, $stk, '10', '10')->assertStatus(422);  // closed for good
    }

    // ── 4. supplier invoice ───────────────────────────────────────

    private function receivedPo(string $qty = '100', string $cost = '10'): array
    {
        $stk = $this->s->stockedItem('feed', '0', '0');
        $po  = $this->openPo($stk, $qty, $cost);
        $this->receive($po, $stk, $qty, $cost)->assertSessionHasNoErrors();
        return [$po->fresh(), $stk];
    }

    private function receiptIds(int $supplierId): array
    {
        return InventoryTransaction::where('supplier_id', $supplierId)->where('type', 'receipt')->pluck('id')->all();
    }

    public function test_an_inventory_invoice_needs_an_unmatched_receipt_from_the_same_supplier(): void
    {
        $this->invoice(['supplier_id' => $this->s->supplier()->id, 'total' => 1000])->assertStatus(422);

        $this->assertSame(0, ApInvoice::count());
    }

    public function test_matching_an_equal_invoice_moves_the_accrual_into_accounts_payable(): void
    {
        [$po] = $this->receivedPo('100', '10');
        $this->invoice(['supplier_id' => $po->supplier_id, 'total' => 1000])->assertSessionHasNoErrors();
        $invoice = ApInvoice::firstOrFail();

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $invoice->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->s->net('2230'));
        $this->assertSame('-1000.00', $this->s->net('2210'));
        $this->assertSame('0.00', $this->s->net('5200'));
        $this->assertSame('1000.00', $this->s->net('1320'));       // stock value unchanged

        $this->assertNotNull(InventoryTransaction::where('type', 'receipt')->first()->ap_invoice_id);
    }

    public function test_an_invoice_above_the_receipt_books_the_difference_to_price_variance(): void
    {
        [$po] = $this->receivedPo('100', '10');
        $this->invoice(['supplier_id' => $po->supplier_id, 'total' => 1100]);
        $invoice = ApInvoice::firstOrFail();

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $invoice->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ]);

        $this->assertSame('100.00', $this->s->net('5200'));
        $this->assertSame('-1100.00', $this->s->net('2210'));
        $this->assertSame('0.00', $this->s->net('2230'));

        $je = JournalEntry::findOrFail($invoice->fresh()->journal_entry_id);
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2));
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->total_debit, 2));
    }

    public function test_a_receipt_cannot_be_invoiced_twice(): void
    {
        [$po] = $this->receivedPo('100', '10');
        $ids = $this->receiptIds($po->supplier_id);
        $admin = $this->s->admin();

        $this->invoice(['supplier_id' => $po->supplier_id, 'total' => 1000]);
        $first = ApInvoice::firstOrFail();
        $this->actingAs($admin)->patch(route('ap-invoices.match-accruals', $first->id), ['transaction_ids' => $ids]);

        $this->invoice(['supplier_id' => $po->supplier_id, 'total' => 1000]);
        $second = ApInvoice::where('id', '!=', $first->id)->latest('id')->first();

        if ($second) {
            $this->actingAs($admin)
                ->patch(route('ap-invoices.match-accruals', $second->id), ['transaction_ids' => $ids])
                ->assertStatus(422);
        }

        $this->assertSame('-1000.00', $this->s->net('2210'));      // booked once only
    }

    // ── 5. guards ─────────────────────────────────────────────────

    public function test_a_view_only_user_cannot_create_confirm_or_receive(): void
    {
        $stk    = $this->s->stockedItem('feed', '0', '0');
        $viewer = $this->s->user(['view procurement']);

        $before = ProcurementRecord::count();
        $this->createPo($stk, '10', '10', null, $viewer);
        $this->assertSame($before, ProcurementRecord::count());

        $po = $this->openPo($stk);
        $this->actingAs($viewer)->patch(route('procurement.confirm-inventory-po', $po->id))->assertForbidden();
        $this->receive($po, $stk, '10', '10', '2026-06-10', $viewer)->assertForbidden();

        $this->assertEquals(0, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_another_company_cannot_confirm_or_receive_against_this_order(): void
    {
        $stk   = $this->s->stockedItem('feed', '0', '0');
        $po    = $this->openPo($stk);
        $other = CattleScenario::make()->admin();

        $this->actingAs($other)->patch(route('procurement.confirm-inventory-po', $po->id))->assertForbidden();
        $this->receive($po, $stk, '10', '10', '2026-06-10', $other)->assertForbidden();

        $this->assertEquals(0, (float) $stk['stock']->fresh()->qty_on_hand);
    }

    public function test_an_order_cannot_use_another_companys_item_or_supplier(): void
    {
        $mine   = $this->s->stockedItem('feed', '0', '0');
        $theirs = CattleScenario::make();
        $theirStk = $theirs->stockedItem('feed', '0', '0');

        // another company's item
        $before = ProcurementRecord::count();
        $this->createPo($theirStk);
        $this->assertSame($before, ProcurementRecord::count());

        // another company's supplier
        $this->createPo($mine, '10', '10', $theirs->supplier());
        $this->assertSame($before, ProcurementRecord::count());
    }

    public function test_goods_cannot_be_received_into_another_companys_store_room(): void
    {
        $stk      = $this->s->stockedItem('feed', '0', '0');
        $theirs   = CattleScenario::make();
        $theirStk = $theirs->stockedItem('feed', '0', '0');
        $po       = $this->openPo($stk);

        $this->receive($po, $stk, '10', '10', '2026-06-10', null, $theirStk['location']->id)
            ->assertSessionHasErrors();

        $this->assertEquals(0, (float) $stk['stock']->fresh()->qty_on_hand);
        $this->assertEquals(0, (float) $theirStk['stock']->fresh()->qty_on_hand);
    }
}
