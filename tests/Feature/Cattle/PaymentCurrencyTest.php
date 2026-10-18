<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  PaymentCurrencyTest   (covers QA-014 and QA-009)
//
//  Payments and collections in a foreign currency:
//    • the journal entry is booked in EGP = amount × exchange rate
//      (a USD 1,000 payment must NOT post as EGP 1,000)
//    • a foreign payment must carry an exchange rate
//    • allocations are converted between payment and invoice currency
//      (USD 1,000 invoice at 49.50 paid in EGP needs EGP 49,500)
//    • the cash/bank "enough money?" check works in EGP
//    • cents are rounded half-up, never cut
//
//  Rate convention: rate = EGP per 1 unit of the currency.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class PaymentCurrencyTest extends TestCase
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

    private function usdApInvoice(string $total = '1000.00', string $rate = '49.50'): ApInvoice
    {
        $inv = $this->s->openApInvoice($total);
        $inv->update(['currency' => 'USD', 'exchange_rate' => $rate]);
        return $inv->fresh();
    }

    private function usdArInvoice(string $total = '1000.00', string $rate = '49.50'): ArInvoice
    {
        $inv = $this->s->openArInvoice($total);
        $inv->update(['currency' => 'USD', 'exchange_rate' => $rate]);
        return $inv->fresh();
    }

    private function payOut(ApInvoice $inv, string $amount, string $allocated, $cash, array $extra = [])
    {
        return $this->actingAs($this->s->admin())->post(route('payments.store'), array_merge([
            'payment_category' => 'supplier_payment',
            'date'             => '2026-06-20',
            'method'           => 'cash',
            'amount'           => $amount,
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => $allocated]],
        ], $extra));
    }

    private function collect(ArInvoice $inv, string $amount, string $allocated, $cash, array $extra = [])
    {
        return $this->actingAs($this->s->admin())->post(route('payments.store'), array_merge([
            'payment_category' => 'customer_collection',
            'date'             => '2026-06-20',
            'method'           => 'cash',
            'amount'           => $amount,
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => $allocated]],
        ], $extra));
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function entryOf(Payment $p): JournalEntry
    {
        return JournalEntry::findOrFail($p->journal_entry_id);
    }

    // ── 1. the journal is booked in EGP ───────────────────────────

    public function test_a_usd_payment_posts_amount_times_rate_in_egp(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00', '49.50');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $je = $this->entryOf(Payment::firstOrFail());
        $this->assertSame('50000.00', $this->money($je->lines()->sum('debit')));    // NOT 1,000
        $this->assertSame('50000.00', $this->money($je->lines()->sum('credit')));
        $this->assertSame('50000.00', $this->s->net('2210'));                        // Dr payable
        $this->assertSame('50000.00', bcsub('100000.00', $this->s->net($cash), 2));   // cash fell by EGP 50,000
    }

    public function test_the_foreign_payment_entry_balances_and_header_totals_match(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $je = $this->entryOf(Payment::firstOrFail());
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2));
        $this->assertSame(0, bccomp((string) $je->total_debit, (string) $je->lines()->sum('debit'), 2));
    }

    public function test_a_usd_collection_posts_cash_in_and_receivable_down_in_egp(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->usdArInvoice('1000.00', '49.50');

        $this->collect($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $this->assertSame('50000.00', $this->s->net($cash));      // Dr cash
        $this->assertSame('-50000.00', $this->s->net('1600'));    // Cr receivable
        $this->assertSame('paid', $inv->fresh()->status);
    }

    public function test_a_part_usd_payment_settles_part_of_the_usd_invoice(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00');

        $this->payOut($inv, '400.00', '400.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertSame('400.00', $this->money($inv->paid));
        $this->assertSame('600.00', $this->money($inv->balance));
        $this->assertSame('partially_paid', $inv->status);
        $this->assertSame('20000.00', $this->money($this->entryOf(Payment::firstOrFail())->lines()->sum('debit')));
    }

    public function test_an_egp_payment_ignores_a_rate_sent_by_the_browser(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->s->openApInvoice('1000.00');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'EGP', 'exchange_rate' => '9'])
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->assertEquals(1, (float) $payment->exchange_rate);
        $this->assertSame('1000.00', $this->money($this->entryOf($payment)->lines()->sum('debit')));
    }

    // ── 2. the rate is mandatory for foreign money ────────────────

    public function test_a_foreign_payment_without_a_rate_is_refused(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD'])
            ->assertSessionHasErrors('exchange_rate');

        $this->assertSame(0, Payment::count());
        $this->assertSame('1000.00', $this->money($inv->fresh()->balance));
    }

    public function test_a_foreign_payment_with_a_zero_rate_is_refused(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '0'])
            ->assertSessionHasErrors('exchange_rate');

        $this->assertSame(0, Payment::count());
    }

    // ── 3. allocations are converted between currencies ───────────

    public function test_an_egp_payment_for_a_usd_invoice_must_cover_the_converted_amount(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00', '49.50');

        // USD 1,000 at 49.50 = EGP 49,500
        $this->payOut($inv, '49500.00', '1000.00', $cash)->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame('49500.00', $this->s->net('2210'));
        $this->assertSame('49500.00', bcsub('100000.00', $this->s->net($cash), 2));
    }

    public function test_paying_only_egp_1000_for_a_usd_1000_invoice_is_refused(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('1000.00', '49.50');

        $this->payOut($inv, '1000.00', '1000.00', $cash)->assertSessionHasErrors('allocations');

        $this->assertSame(0, Payment::count());
        $this->assertSame('1000.00', $this->money($inv->fresh()->balance));
        $this->assertSame('0.00', $this->s->net('2210'));
    }

    public function test_a_usd_payment_for_an_egp_invoice_is_converted_too(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->s->openApInvoice('5000.00');   // EGP invoice

        // USD 100 at 50 = EGP 5,000 → the allocation (in the invoice's EGP) is 5,000
        $this->payOut($inv, '100.00', '5000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('5000.00', $this->money($this->entryOf(Payment::firstOrFail())->lines()->sum('debit')));
    }

    public function test_a_collection_in_egp_for_a_usd_invoice_is_converted(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->usdArInvoice('1000.00', '49.50');

        $this->collect($inv, '1000.00', '1000.00', $cash)->assertSessionHasErrors('allocations');
        $this->assertSame(0, Payment::count());

        $this->collect($inv, '49500.00', '1000.00', $cash)->assertSessionHasNoErrors();
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('49500.00', $this->s->net($cash));
    }

    // ── 4. "enough cash?" is judged in EGP ────────────────────────

    public function test_the_cash_check_uses_the_egp_value_of_a_foreign_payment(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '40000.00');             // only EGP 40,000 in the safe
        $inv = $this->usdApInvoice('1000.00');

        // USD 1,000 at 50 = EGP 50,000 — more than the safe holds.
        // (Compared as 1,000 it would wrongly pass.)
        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, Payment::count());
        $this->assertSame('40000.00', $this->s->net($cash));
    }

    public function test_the_cash_check_passes_when_the_egp_value_fits(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '50000.00');
        $inv = $this->usdApInvoice('1000.00');

        $this->payOut($inv, '1000.00', '1000.00', $cash, ['currency' => 'USD', 'exchange_rate' => '50'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->s->net($cash));
    }

    // ── 5. cents are rounded, not cut (QA-009) ────────────────────

    public function test_the_egp_value_is_rounded_half_up_not_cut(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '100000.00');
        $inv = $this->usdApInvoice('10.01', '49.555');

        // 10.01 × 49.555 = 496.04555 → 496.05 (cutting would give 496.04)
        $this->payOut($inv, '10.01', '10.01', $cash, ['currency' => 'USD', 'exchange_rate' => '49.555'])
            ->assertSessionHasNoErrors();

        $je = $this->entryOf(Payment::firstOrFail());
        $this->assertSame('496.05', $this->money($je->lines()->sum('debit')));
        $this->assertSame('496.05', $this->money($je->lines()->sum('credit')));
    }

    // ── 6. normal EGP behaviour is unchanged ──────────────────────

    public function test_a_plain_egp_payment_still_posts_the_same_amount(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '10000.00');
        $inv = $this->s->openApInvoice('3000.00');

        $this->payOut($inv, '3000.00', '3000.00', $cash)->assertSessionHasNoErrors();

        $this->assertSame('3000.00', $this->s->net('2210'));
        $this->assertSame('7000.00', $this->s->net($cash));
        $this->assertSame('paid', $inv->fresh()->status);
    }
}
