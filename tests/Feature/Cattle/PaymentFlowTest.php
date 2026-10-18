<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  PaymentFlowTest
//
//  Money in (collections from customers) and money out (payments to
//  suppliers), through the real route:
//    • the invoice balance and status follow the payment
//    • one payment can settle several invoices
//    • the journal entry is posted and balanced
//    • the guards: wrong account type, overpayment, mismatched
//      allocations, not enough cash, other company's invoice
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
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

    private function pay(array $data, $user = null)
    {
        return $this->actingAs($user ?? $this->s->admin())->post(route('payments.store'), array_merge([
            'date'   => '2026-06-20',
            'method' => 'cash',
        ], $data));
    }

    private function collect(ArInvoice $inv, string $amount, $cash, array $extra = [])
    {
        return $this->pay(array_merge([
            'payment_category' => 'customer_collection',
            'amount'           => $amount,
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => $amount]],
        ], $extra));
    }

    private function payOut(ApInvoice $inv, string $amount, $cash, array $extra = [])
    {
        return $this->pay(array_merge([
            'payment_category' => 'supplier_payment',
            'amount'           => $amount,
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => $amount]],
        ], $extra));
    }

    // ── collections (money in) ────────────────────────────────────

    public function test_a_part_collection_reduces_the_invoice_balance(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('10000.00');

        $this->collect($inv, '4000.00', $cash)->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertSame('4000.00', number_format((float) $inv->paid, 2, '.', ''));
        $this->assertSame('6000.00', number_format((float) $inv->balance, 2, '.', ''));
        $this->assertSame('partially_paid', $inv->status);
    }

    public function test_a_full_collection_marks_the_invoice_paid(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('10000.00');

        $this->collect($inv, '10000.00', $cash);

        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame('0.00', number_format((float) $inv->balance, 2, '.', ''));
    }

    public function test_a_collection_posts_cash_in_and_receivable_down(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('10000.00');

        $this->collect($inv, '4000.00', $cash);

        $payment = Payment::firstOrFail();
        $this->assertSame('incoming', $payment->type);

        $je = JournalEntry::findOrFail($payment->journal_entry_id);
        $this->assertSame('posted', $je->status);
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2));

        $this->assertSame('4000.00', $this->s->net($cash));
        $this->assertSame('-4000.00', $this->s->net('1600'));
    }

    public function test_one_collection_can_settle_several_invoices(): void
    {
        $cash = $this->s->cashAccount();
        $a = $this->s->openArInvoice('3000.00');
        $b = $this->s->openArInvoice('2000.00');

        $this->pay([
            'payment_category' => 'customer_collection',
            'amount'           => '5000.00',
            'bank_account_id'  => $cash->id,
            'allocations'      => [
                ['invoice_id' => $a->id, 'allocated_amount' => '3000.00'],
                ['invoice_id' => $b->id, 'allocated_amount' => '2000.00'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_allocations_must_add_up_to_the_payment_amount(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('10000.00');

        $this->pay([
            'payment_category' => 'customer_collection',
            'amount'           => '5000.00',
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => '4000.00']],
        ])->assertSessionHasErrors('allocations');

        $this->assertSame(0, Payment::count());
        $this->assertSame('10000.00', number_format((float) $inv->fresh()->balance, 2, '.', ''));
    }

    public function test_a_collection_cannot_exceed_the_invoice_balance(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '1500.00', $cash)->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertSame('open', $inv->fresh()->status);
    }

    public function test_a_collection_needs_at_least_one_invoice(): void
    {
        $cash = $this->s->cashAccount();

        $this->pay([
            'payment_category' => 'customer_collection',
            'amount'           => '100.00',
            'bank_account_id'  => $cash->id,
        ])->assertSessionHasErrors('allocations');
    }

    // ── payments (money out) ──────────────────────────────────────

    public function test_paying_a_supplier_reduces_cash_and_payable(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '20000.00');
        $inv = $this->s->openApInvoice('8000.00');

        $this->payOut($inv, '8000.00', $cash)->assertSessionHasNoErrors();

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('12000.00', $this->s->net($cash));
        $this->assertSame('8000.00', $this->s->net('2210'));   // Dr payable (settles the debt)

        $payment = Payment::firstOrFail();
        $this->assertSame('outgoing', $payment->type);
    }

    public function test_a_payment_cannot_exceed_the_cash_available(): void
    {
        $cash = $this->s->cashAccount();
        $this->s->fund($cash, '1000.00');
        $inv = $this->s->openApInvoice('8000.00');

        $this->payOut($inv, '8000.00', $cash)->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, Payment::count());
        $this->assertSame('1000.00', $this->s->net($cash));
        $this->assertSame('open', $inv->fresh()->status);
    }

    // ── account rules ─────────────────────────────────────────────

    public function test_cash_must_go_through_a_cash_safe_account(): void
    {
        $bank = $this->s->bankAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '500.00', $bank)->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_bank_transfer_must_go_through_a_bank_account_and_name_the_bank(): void
    {
        $cash = $this->s->cashAccount();
        $bank = $this->s->bankAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '500.00', $cash, ['method' => 'bank_transfer', 'bank_name' => 'NBE'])
            ->assertSessionHasErrors('bank_account_id');

        $this->collect($inv, '500.00', $bank, ['method' => 'bank_transfer'])->assertStatus(422);

        $this->collect($inv, '500.00', $bank, ['method' => 'bank_transfer', 'bank_name' => 'NBE'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());
    }

    public function test_the_date_must_fall_inside_a_fiscal_year(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '500.00', $cash, ['date' => '2031-01-01'])->assertSessionHasErrors('date');
    }

    public function test_a_cheque_starts_in_the_safe(): void
    {
        $bank = $this->s->bankAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '500.00', $bank, [
            'method' => 'cheque', 'check_bank' => 'CIB', 'check_number' => '777',
        ])->assertSessionHasNoErrors();

        $this->assertSame('in_safe', Payment::firstOrFail()->check_status);
    }

    // ── deleting ──────────────────────────────────────────────────

    public function test_a_payment_with_a_posted_entry_cannot_be_deleted(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('1000.00');
        $this->collect($inv, '500.00', $cash);

        $this->actingAs($this->s->admin())
            ->delete(route('payments.destroy', Payment::firstOrFail()->id))
            ->assertStatus(422);

        $this->assertSame(1, Payment::count());
        $this->assertSame('500.00', number_format((float) $inv->fresh()->paid, 2, '.', ''));
    }

    // ── security ──────────────────────────────────────────────────

    public function test_a_view_only_user_cannot_record_a_payment(): void
    {
        $cash = $this->s->cashAccount();
        $inv  = $this->s->openArInvoice('1000.00');

        $this->pay([
            'payment_category' => 'customer_collection', 'amount' => '500.00',
            'bank_account_id'  => $cash->id,
            'allocations'      => [['invoice_id' => $inv->id, 'allocated_amount' => '500.00']],
        ], $this->s->user(['view payments']))->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_cannot_be_applied_to_another_companys_invoice(): void
    {
        $other     = CattleScenario::make();
        $theirInv  = $other->openArInvoice('1000.00');
        $myCash    = $this->s->cashAccount();

        // The app refuses with a validation error ("invoice not found for this company"),
        // sends the user back to the form, and saves nothing.
        $this->collect($theirInv, '500.00', $myCash)
            ->assertRedirect()
            ->assertSessionHasErrors('allocations');

        $this->assertSame(0, Payment::count());
        $this->assertSame('1000.00', number_format((float) $theirInv->fresh()->balance, 2, '.', ''));
    }

    public function test_a_payment_cannot_use_another_companys_cash_account(): void
    {
        $other     = CattleScenario::make();
        $theirCash = $other->cashAccount();
        $inv       = $this->s->openArInvoice('1000.00');

        $this->collect($inv, '500.00', $theirCash)->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }
}
