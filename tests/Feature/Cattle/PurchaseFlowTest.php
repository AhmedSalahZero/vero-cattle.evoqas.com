<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  PurchaseFlowTest
//
//  Buying cattle, step by step, through the real routes:
//    1. confirm the purchase order
//    2. receive the animals      → animals created, cost accrued
//    3. supplier invoice arrives → matched to the receipt
//    (and an invoice for "other" costs that needs no purchase order)
//
//  Accounting trail being protected:
//    receipt : Dr Livestock WIP 1310        / Cr AP Accruals 2230
//    match   : Dr AP Accruals 2230          / Cr Accounts Payable 2210
//    price difference → Purchase Price Variance 5200
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Animal;
use App\Models\ApInvoice;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
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

    private function net(string $code): string
    {
        $id = $this->s->account($code)->id;
        $d = (string) JournalLine::where('account_id', $id)->sum('debit');
        $c = (string) JournalLine::where('account_id', $id)->sum('credit');
        return bcsub($d, $c, 2);
    }

    private function openPo(array $overrides = [])
    {
        $po = $this->s->draftCattlePo($this->s->supplier(), $overrides);
        $this->actingAs($this->s->admin())->patch(route('procurement.confirm-po', $po->id))
            ->assertSessionHasNoErrors();
        return $po->fresh();
    }

    private function receive($po, array $weights, ?string $tagPrefix = null)
    {
        $tagPrefix ??= 'R' . uniqid();
        $animals = [];
        foreach ($weights as $i => $w) {
            $animals[] = ['tag' => $tagPrefix . '-' . $i, 'sex' => 'male', 'weight_kg' => $w];
        }

        return $this->actingAs($this->s->admin())->post(route('procurement.confirm-receipt', $po->id), [
            'animals'      => $animals,
            'batch_date'   => '2026-06-10',
            'receipt_mode' => 'individual',
        ]);
    }

    private function invoice(array $data)
    {
        return $this->actingAs($this->s->admin())->post(route('ap-invoices.store'), array_merge([
            'date'  => '2026-06-20',
            'total' => 1000,
        ], $data));
    }

    // ── 1. confirming the order ───────────────────────────────────

    public function test_confirming_a_draft_order_opens_it_and_gives_it_a_reference(): void
    {
        $po = $this->openPo();

        $this->assertSame('open', $po->po_status);
        $this->assertNotEmpty($po->po_reference);
    }

    public function test_an_order_cannot_be_confirmed_twice(): void
    {
        $po = $this->openPo();

        $this->actingAs($this->s->admin())->patch(route('procurement.confirm-po', $po->id))->assertStatus(422);
    }

    public function test_an_order_without_a_price_cannot_be_confirmed(): void
    {
        $po = $this->s->draftCattlePo($this->s->supplier(), ['estimated_price_per_kg' => null]);

        $this->actingAs($this->s->admin())->patch(route('procurement.confirm-po', $po->id))->assertStatus(422);
        $this->assertSame('draft', $po->fresh()->po_status);
    }

    public function test_animals_cannot_be_received_against_a_draft_order(): void
    {
        $po = $this->s->draftCattlePo($this->s->supplier());

        $this->receive($po, [300])->assertStatus(422);
        $this->assertSame(0, Animal::where('company_id', $this->s->company->id)->count());
    }

    // ── 2. receiving the animals ──────────────────────────────────

    public function test_receiving_creates_the_animals_priced_by_weight_times_the_order_price(): void
    {
        $po = $this->openPo();

        $this->receive($po, [300, 250.5], 'T')->assertSessionHasNoErrors();

        $animals = Animal::where('company_id', $this->s->company->id)->orderBy('tag')->get();
        $this->assertCount(2, $animals);
        $this->assertSame('30000.00', number_format((float) $animals[0]->purchase_price, 2, '.', ''));
        $this->assertSame('25050.00', number_format((float) $animals[1]->purchase_price, 2, '.', ''));
        $this->assertSame(2, (int) $po->fresh()->received_head);
    }

    public function test_receiving_accrues_the_cost_balanced_to_wip_and_ap_accruals(): void
    {
        $po = $this->openPo();

        $this->receive($po, [300, 250.5]);

        $this->assertSame('55050.00', $this->net('1310'));
        $this->assertSame('-55050.00', $this->net('2230'));

        foreach (JournalEntry::where('company_id', $this->s->company->id)->get() as $je) {
            $this->assertSame(
                0,
                bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2),
                "Entry {$je->reference} is not balanced"
            );
        }
    }

    public function test_receipt_total_equals_the_sum_of_animal_prices_even_with_odd_cents(): void
    {
        $po = $this->openPo(['estimated_price_per_kg' => 33.33]);

        $this->receive($po, [301.7, 289.3, 275.55]);

        $sum = (string) Animal::where('company_id', $this->s->company->id)->sum('purchase_price');
        $this->assertSame(0, bccomp($sum, bcmul('-1', $this->net('2230'), 2), 2));
    }

    public function test_a_duplicate_tag_is_refused(): void
    {
        $po = $this->openPo();
        $this->receive($po, [300], 'DUP');

        $this->receive($po->fresh(), [310], 'DUP')->assertSessionHasErrors('animals.0.tag');
        $this->assertSame(1, Animal::where('company_id', $this->s->company->id)->count());
    }

    public function test_an_order_is_closed_once_the_maximum_is_received(): void
    {
        $po = $this->openPo(['min_head' => 1, 'max_head' => 2, 'head_count' => 2]);

        $this->receive($po, [300, 310]);
        $this->assertSame('fully_received', $po->fresh()->po_status);

        $this->receive($po->fresh(), [320])->assertStatus(422);
    }

    public function test_receiving_creates_an_arrival_weight_for_each_animal(): void
    {
        $po = $this->openPo();

        $this->receive($po, [300, 250]);

        $this->assertSame(2, \App\Models\WeightRecord::where('company_id', $this->s->company->id)->count());
    }

    // ── 3. the supplier invoice ───────────────────────────────────

    private function receivedPo(array $weights = [300, 250.5])
    {
        $po = $this->openPo();
        $this->receive($po, $weights);
        return $po->fresh();
    }

    private function receiptIds(int $supplierId): array
    {
        return InventoryTransaction::where('supplier_id', $supplierId)
            ->where('type', 'livestock_receipt')->pluck('id')->all();
    }

    public function test_a_cattle_invoice_needs_an_unmatched_receipt_from_the_same_supplier(): void
    {
        $this->invoice(['supplier_id' => $this->s->supplier()->id, 'invoice_type' => 'cattle'])
            ->assertStatus(422);

        $this->assertSame(0, ApInvoice::count());
    }

    public function test_matching_an_equal_invoice_clears_the_accrual_into_accounts_payable(): void
    {
        $po = $this->receivedPo();
        $this->invoice(['supplier_id' => $po->supplier_id, 'invoice_type' => 'cattle', 'total' => 55050])
            ->assertSessionHasNoErrors();
        $invoice = ApInvoice::firstOrFail();

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $invoice->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->net('2230'));
        $this->assertSame('-55050.00', $this->net('2210'));
        $this->assertSame('0.00', $this->net('5200'));
        $this->assertNotNull($invoice->fresh()->journal_entry_id);
    }

    public function test_an_invoice_higher_than_the_receipt_books_the_difference_to_price_variance(): void
    {
        $po = $this->receivedPo();
        $this->invoice(['supplier_id' => $po->supplier_id, 'invoice_type' => 'cattle', 'total' => 56000]);
        $invoice = ApInvoice::firstOrFail();

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $invoice->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ]);

        $this->assertSame('950.00', $this->net('5200'));      // extra cost
        $this->assertSame('-56000.00', $this->net('2210'));   // we owe the invoice amount
        $this->assertSame('0.00', $this->net('2230'));

        $je = JournalEntry::findOrFail($invoice->fresh()->journal_entry_id);
        $debit  = (string) $je->lines()->sum('debit');
        $credit = (string) $je->lines()->sum('credit');
        $this->assertSame(0, bccomp($debit, $credit, 2), 'Match entry must balance');
        $this->assertSame(0, bccomp($debit, (string) $je->total_debit, 2), 'Header total must equal the lines');
    }

    public function test_an_invoice_lower_than_the_receipt_books_a_price_gain(): void
    {
        $po = $this->receivedPo();
        $this->invoice(['supplier_id' => $po->supplier_id, 'invoice_type' => 'cattle', 'total' => 55000]);
        $invoice = ApInvoice::firstOrFail();

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $invoice->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ]);

        $this->assertSame('-50.00', $this->net('5200'));
        $this->assertSame('0.00', $this->net('2230'));
    }

    public function test_a_receipt_cannot_be_matched_to_a_second_invoice(): void
    {
        $po  = $this->receivedPo();
        $ids = $this->receiptIds($po->supplier_id);

        $this->invoice(['supplier_id' => $po->supplier_id, 'invoice_type' => 'cattle', 'total' => 55050]);
        $first = ApInvoice::firstOrFail();
        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $first->id), ['transaction_ids' => $ids]);

        $second = ApInvoice::create([
            'company_id' => $this->s->company->id, 'supplier_id' => $po->supplier_id,
            'date' => '2026-06-25', 'total' => 100, 'paid' => 0, 'balance' => 100,
            'currency' => 'EGP', 'exchange_rate' => 1, 'status' => 'open', 'invoice_type' => 'cattle',
        ]);

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $second->id), ['transaction_ids' => $ids])
            ->assertStatus(422);
    }

    public function test_a_receipt_cannot_be_matched_to_another_suppliers_invoice(): void
    {
        $po = $this->receivedPo();

        $other = ApInvoice::create([
            'company_id' => $this->s->company->id, 'supplier_id' => $this->s->supplier()->id,
            'date' => '2026-06-25', 'total' => 100, 'paid' => 0, 'balance' => 100,
            'currency' => 'EGP', 'exchange_rate' => 1, 'status' => 'open', 'invoice_type' => 'cattle',
        ]);

        $this->actingAs($this->s->admin())
            ->patch(route('ap-invoices.match-accruals', $other->id), [
                'transaction_ids' => $this->receiptIds($po->supplier_id),
            ])->assertStatus(422);

        $this->assertSame('-55050.00', $this->net('2230'));
    }

    // ── "other" invoices (no purchase order) ──────────────────────

    public function test_an_other_invoice_posts_expense_and_payable_immediately(): void
    {
        $this->invoice([
            'supplier_id'        => $this->s->supplier()->id,
            'invoice_type'       => 'other',
            'expense_account_id' => $this->s->account('6910')->id,
            'total'              => 1500,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1500.00', $this->net('6910'));
        $this->assertSame('-1500.00', $this->net('2210'));
    }

    public function test_an_other_invoice_needs_an_expense_account(): void
    {
        $this->invoice(['supplier_id' => $this->s->supplier()->id, 'invoice_type' => 'other'])
            ->assertStatus(422);
    }

    public function test_an_invoice_cannot_use_another_companys_supplier_or_account(): void
    {
        $other = CattleScenario::make();

        $this->invoice([
            'supplier_id'        => $other->supplier()->id,
            'invoice_type'       => 'other',
            'expense_account_id' => $this->s->account('6910')->id,
        ])->assertStatus(422);

        $this->invoice([
            'supplier_id'        => $this->s->supplier()->id,
            'invoice_type'       => 'other',
            'expense_account_id' => $other->account('6910')->id,
        ])->assertStatus(422);

        $this->assertSame(0, ApInvoice::count());
    }

    public function test_a_view_only_user_cannot_create_confirm_or_receive(): void
    {
        $po     = $this->s->draftCattlePo($this->s->supplier());
        $viewer = $this->s->user(['view procurement', 'view ap_invoices']);

        $this->actingAs($viewer)->patch(route('procurement.confirm-po', $po->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('ap-invoices.store'), [
            'supplier_id' => $po->supplier_id, 'invoice_type' => 'other', 'date' => '2026-06-20', 'total' => 10,
        ])->assertForbidden();
    }

    public function test_another_company_cannot_confirm_or_receive_against_this_order(): void
    {
        $po    = $this->s->draftCattlePo($this->s->supplier());
        $other = CattleScenario::make();

        $this->actingAs($other->admin())->patch(route('procurement.confirm-po', $po->id))->assertForbidden();
        $this->actingAs($other->admin())->post(route('procurement.confirm-receipt', $po->id), [
            'animals' => [['tag' => 'X1', 'sex' => 'male', 'weight_kg' => 300]],
            'batch_date' => '2026-06-10', 'receipt_mode' => 'individual',
        ])->assertForbidden();

        $this->assertSame('draft', $po->fresh()->po_status);
    }
}
