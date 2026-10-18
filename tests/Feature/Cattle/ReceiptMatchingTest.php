<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  ReceiptMatchingTest   (covers QA-014e, QA-015 and QA-016)
//
//  Matching goods receipts to a supplier invoice:
//    • receipts can be matched in SEVERAL batches — the books must
//      always hold exactly ONE live "match" entry for the invoice
//      (no double credit to payables, no invented price variance)
//    • unmatching a receipt reverses the entry and re-posts one for
//      whatever is still matched
//    • if the entry cannot be re-posted (closed year) NOTHING changes
//    • a foreign-currency invoice is converted to EGP before it is
//      compared with the (EGP) receipts
//
//  Setup: two received orders from the SAME supplier at 100 EGP/kg
//    Receipt A = 300 kg + 250.5 kg = 55,050.00
//    Receipt B = 100 kg            = 10,000.00
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ApInvoice;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class ReceiptMatchingTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s        = CattleScenario::make();
        $this->supplier = $this->s->supplier();
    }

    // ── helpers ───────────────────────────────────────────────────

    /**
     * Order + receive animals from the test supplier. Returns the ids of
     * the receipt rows that this order created.
     *
     * @param  array<int, float|int>  $weights
     * @return array<int, int>
     */
    private function receive(array $weights): array
    {
        $before = InventoryTransaction::where('type', 'livestock_receipt')->pluck('id')->all();

        $admin = $this->s->admin();
        $po    = $this->s->draftCattlePo($this->supplier);
        $this->actingAs($admin)->patch(route('procurement.confirm-po', $po->id))->assertSessionHasNoErrors();

        $prefix  = 'M' . uniqid();
        $animals = [];
        foreach ($weights as $i => $w) {
            $animals[] = ['tag' => "$prefix-$i", 'sex' => 'male', 'weight_kg' => $w];
        }
        $this->actingAs($admin)->post(route('procurement.confirm-receipt', $po->id), [
            'animals'      => $animals,
            'batch_date'   => '2026-06-10',
            'receipt_mode' => 'individual',
        ])->assertSessionHasNoErrors();

        return array_values(array_diff(
            InventoryTransaction::where('type', 'livestock_receipt')->pluck('id')->all(),
            $before
        ));
    }

    private function invoice(string $total, array $extra = []): ApInvoice
    {
        return ApInvoice::create(array_merge([
            'company_id' => $this->s->company->id, 'supplier_id' => $this->supplier->id,
            'date' => '2026-06-20', 'total' => $total, 'paid' => 0, 'balance' => $total,
            'currency' => 'EGP', 'exchange_rate' => 1, 'status' => 'open', 'invoice_type' => 'cattle',
        ], $extra));
    }

    private function match(ApInvoice $inv, array $ids)
    {
        return $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $inv->id), ['transaction_ids' => $ids]);
    }

    private function unmatch(ApInvoice $inv, int $txId)
    {
        return $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.unmatch-accrual', [$inv->id, $txId]));
    }

    private function matchEntries(ApInvoice $inv)
    {
        return JournalEntry::where('source_type', ApInvoice::class)
            ->where('source_id', $inv->id)
            ->where('entry_type', 'procurement');
    }

    private function livePostedEntries(ApInvoice $inv): int
    {
        return (clone $this->matchEntries($inv))->where('status', 'posted')->count();
    }

    // ── 1. matching in several batches (QA-016) ───────────────────

    public function test_matching_a_second_batch_leaves_one_live_entry_and_no_double_credit(): void
    {
        $a   = $this->receive([300, 250.5]);   // 55,050
        $b   = $this->receive([100]);          // 10,000
        $inv = $this->invoice('65050.00');

        $this->match($inv, $a)->assertSessionHasNoErrors();
        $this->match($inv, $b)->assertSessionHasNoErrors();

        $this->assertSame('-65050.00', $this->s->net('2210'));   // owed ONCE, not twice
        $this->assertSame('0.00', $this->s->net('2230'));        // both accruals cleared
        $this->assertSame('0.00', $this->s->net('5200'));        // no invented variance
        $this->assertSame(1, $this->livePostedEntries($inv));
    }

    public function test_the_first_batch_entry_is_reversed_when_the_second_batch_is_matched(): void
    {
        $a   = $this->receive([300, 250.5]);
        $b   = $this->receive([100]);
        $inv = $this->invoice('65050.00');

        $this->match($inv, $a);
        $firstId = $inv->fresh()->journal_entry_id;
        $this->match($inv, $b);

        $this->assertSame('reversed', JournalEntry::findOrFail($firstId)->status);
        $this->assertNotSame($firstId, $inv->fresh()->journal_entry_id);
        $this->assertSame(1, JournalEntry::where('reversal_of_id', $firstId)->count());
    }

    public function test_a_part_matched_invoice_shows_the_difference_until_the_rest_is_matched(): void
    {
        $a   = $this->receive([300, 250.5]);   // 55,050
        $b   = $this->receive([100]);          // 10,000
        $inv = $this->invoice('65050.00');

        $this->match($inv, $a);
        // invoice 65,050 vs receipts matched so far 55,050 → 10,000 extra cost (temporary)
        $this->assertSame('10000.00', $this->s->net('5200'));
        $this->assertSame('-10000.00', $this->s->net('2230'));   // B's accrual still waiting

        $this->match($inv, $b);
        $this->assertSame('0.00', $this->s->net('5200'));        // variance disappears
        $this->assertSame('0.00', $this->s->net('2230'));
    }

    public function test_every_match_entry_balances_and_its_header_equals_the_lines(): void
    {
        $a   = $this->receive([300, 250.5]);
        $b   = $this->receive([100]);
        $inv = $this->invoice('66000.00');   // 950 above the receipts

        $this->match($inv, $a);
        $this->match($inv, $b);

        foreach ($this->matchEntries($inv)->get() as $je) {
            $d = (string) $je->lines()->sum('debit');
            $c = (string) $je->lines()->sum('credit');
            $this->assertSame(0, bccomp($d, $c, 2), "{$je->reference} must balance");
            $this->assertSame(0, bccomp($d, (string) $je->total_debit, 2), "{$je->reference} header must equal lines");
        }
        $this->assertSame('950.00', $this->s->net('5200'));
        $this->assertSame('-66000.00', $this->s->net('2210'));
    }

    public function test_a_closed_year_blocks_a_second_batch_and_nothing_is_matched(): void
    {
        $a   = $this->receive([300, 250.5]);
        $b   = $this->receive([100]);
        $inv = $this->invoice('65050.00');

        $this->match($inv, $a)->assertSessionHasNoErrors();
        $firstId = $inv->fresh()->journal_entry_id;

        $this->s->fiscalYear->update(['status' => 'closed']);
        $this->match($inv, $b)->assertSessionHasErrors();

        $this->assertSame(0, InventoryTransaction::whereIn('id', $b)->whereNotNull('ap_invoice_id')->count());
        $this->assertSame($firstId, $inv->fresh()->journal_entry_id);
        $this->assertSame('posted', JournalEntry::findOrFail($firstId)->status);
    }

    // ── 2. unmatching (QA-015) ────────────────────────────────────

    public function test_unmatching_the_only_receipt_reverses_the_entry_and_unlinks_it(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');
        $this->match($inv, $a);
        $this->assertSame('-55050.00', $this->s->net('2210'));

        foreach ($a as $txId) {
            $this->unmatch($inv, $txId)->assertSessionHasNoErrors();
        }

        $this->assertSame('0.00', $this->s->net('2210'));          // payable gone
        $this->assertSame(-55050.00, (float) $this->s->net('2230')); // accrual waiting again
        $this->assertSame(0, $this->livePostedEntries($inv));
        $this->assertNull($inv->fresh()->journal_entry_id);
        $this->assertSame(0, InventoryTransaction::where('ap_invoice_id', $inv->id)->count());
    }

    public function test_unmatching_one_of_two_batches_re_posts_an_entry_for_the_other(): void
    {
        $a   = $this->receive([300, 250.5]);   // 55,050
        $b   = $this->receive([100]);          // 10,000
        $inv = $this->invoice('65050.00');
        $this->match($inv, array_merge($a, $b));
        $this->assertSame('0.00', $this->s->net('2230'));

        foreach ($b as $txId) {
            $this->unmatch($inv, $txId)->assertSessionHasNoErrors();
        }

        $this->assertSame('-10000.00', $this->s->net('2230'));   // B's accrual is outstanding again
        $this->assertSame('-65050.00', $this->s->net('2210'));   // invoice still owed in full
        $this->assertSame('10000.00', $this->s->net('5200'));    // invoice exceeds matched receipts
        $this->assertSame(1, $this->livePostedEntries($inv));
        $this->assertNotNull($inv->fresh()->journal_entry_id);
        $this->assertSame(0, InventoryTransaction::whereIn('id', $b)->whereNotNull('ap_invoice_id')->count());
    }

    public function test_a_receipt_can_be_matched_again_after_unmatching(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');
        $this->match($inv, $a);
        foreach ($a as $txId) {
            $this->unmatch($inv, $txId);
        }

        $this->match($inv, $a)->assertSessionHasNoErrors();

        $this->assertSame('-55050.00', $this->s->net('2210'));
        $this->assertSame('0.00', $this->s->net('2230'));
        $this->assertSame(1, $this->livePostedEntries($inv));
    }

    public function test_unmatching_in_a_closed_year_is_refused_and_the_receipt_stays_matched(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');
        $this->match($inv, $a);
        $entryId = $inv->fresh()->journal_entry_id;

        $this->s->fiscalYear->update(['status' => 'closed']);
        $this->unmatch($inv, $a[0])->assertStatus(422);

        $this->assertSame(count($a), InventoryTransaction::where('ap_invoice_id', $inv->id)->count());
        $this->assertSame($entryId, $inv->fresh()->journal_entry_id);
        $this->assertSame('posted', JournalEntry::findOrFail($entryId)->status);
        $this->assertSame('-55050.00', $this->s->net('2210'));
    }

    public function test_a_receipt_that_is_not_matched_to_this_invoice_cannot_be_unmatched(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');   // nothing matched yet

        $this->unmatch($inv, $a[0])->assertStatus(422);
    }

    public function test_a_paid_invoice_cannot_have_receipts_unmatched(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');
        $this->match($inv, $a);
        $inv->update(['status' => 'paid', 'paid' => 55050, 'balance' => 0]);

        $this->unmatch($inv, $a[0])->assertStatus(422);

        $this->assertSame(count($a), InventoryTransaction::where('ap_invoice_id', $inv->id)->count());
    }

    // ── 3. foreign-currency invoices (QA-014e) ────────────────────

    public function test_a_usd_invoice_is_converted_to_egp_before_matching(): void
    {
        $a   = $this->receive([300, 250.5]);   // EGP 55,050
        $inv = $this->invoice('1000.00', ['currency' => 'USD', 'exchange_rate' => '55.05']);

        $this->match($inv, $a)->assertSessionHasNoErrors();

        // USD 1,000 × 55.05 = EGP 55,050 → exactly the receipts → no variance
        $this->assertSame('-55050.00', $this->s->net('2210'));
        $this->assertSame('0.00', $this->s->net('2230'));
        $this->assertSame('0.00', $this->s->net('5200'));
    }

    public function test_a_usd_invoice_above_the_receipts_books_the_difference_in_egp(): void
    {
        $a   = $this->receive([300, 250.5]);   // EGP 55,050
        $inv = $this->invoice('1000.00', ['currency' => 'USD', 'exchange_rate' => '56']);

        $this->match($inv, $a)->assertSessionHasNoErrors();

        // USD 1,000 × 56 = EGP 56,000 → 950 above the receipts
        $this->assertSame('-56000.00', $this->s->net('2210'));
        $this->assertSame('950.00', $this->s->net('5200'));
        $this->assertSame('0.00', $this->s->net('2230'));
    }

    // ── 4. the old guards still hold ──────────────────────────────

    public function test_matching_the_same_receipts_twice_does_not_double_the_books(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');

        $this->match($inv, $a)->assertSessionHasNoErrors();
        $this->match($inv, $a)->assertSessionHasNoErrors();   // same invoice, same receipts

        $this->assertSame('-55050.00', $this->s->net('2210'));
        $this->assertSame('0.00', $this->s->net('2230'));
        $this->assertSame(1, $this->livePostedEntries($inv));
    }

    public function test_a_view_only_user_cannot_match_or_unmatch(): void
    {
        $a   = $this->receive([300, 250.5]);
        $inv = $this->invoice('55050.00');
        $viewer = $this->s->user(['view ap_invoices']);

        $this->actingAs($viewer)->patch(route('ap-invoices.match-accruals', $inv->id), ['transaction_ids' => $a])
            ->assertForbidden();

        $this->match($inv, $a);
        $this->actingAs($viewer)->patch(route('ap-invoices.unmatch-accrual', [$inv->id, $a[0]]))
            ->assertForbidden();

        $this->assertSame(count($a), InventoryTransaction::where('ap_invoice_id', $inv->id)->count());
    }
}
