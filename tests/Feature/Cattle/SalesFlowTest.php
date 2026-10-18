<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  SalesFlowTest
//
//  The most important money flow in the app, driven through the real
//  web routes: confirm a sale → AR invoice → animals sold → revenue
//  entry → cost-of-sales entry; and cancelling it again.
//
//  What this protects: every entry must balance, nothing is posted
//  twice, and the books return to zero after a cancellation.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ArInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class SalesFlowTest extends TestCase
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

    private function confirm($sale, $user, ?int $revenueAccountId = null)
    {
        return $this->actingAs($user)->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $revenueAccountId ?? $this->s->account('4100')->id,
        ]);
    }

    private function net(string $code): string
    {
        $id = $this->s->account($code)->id;
        $d = (string) JournalLine::where('account_id', $id)->sum('debit');
        $c = (string) JournalLine::where('account_id', $id)->sum('credit');
        return bcsub($d, $c, 2);
    }

    public function test_confirming_a_sale_creates_the_invoice_and_marks_animals_sold(): void
    {
        $sale = $this->s->draftSale([
            ['price' => '30000.00', 'cogs' => '21000.00'],
            ['price' => '25000.50', 'cogs' => '18000.25'],
        ]);

        $this->confirm($sale, $this->s->admin())->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('confirmed', $sale->status);
        $this->assertNotNull($sale->ar_invoice_id);

        $invoice = ArInvoice::find($sale->ar_invoice_id);
        $this->assertSame('55000.50', number_format((float) $invoice->total, 2, '.', ''));
        $this->assertSame('open', $invoice->status);
        $this->assertSame($this->s->customer->id, (int) $invoice->customer_id);

        foreach ($sale->lines as $line) {
            $this->assertSame('sold', $line->animal->fresh()->status);
        }
    }

    public function test_revenue_entry_is_balanced_and_uses_receivable_and_chosen_revenue_account(): void
    {
        $sale = $this->s->draftSale([['price' => '40000.00']]);

        $this->confirm($sale, $this->s->admin());

        $entry = JournalEntry::findOrFail($sale->fresh()->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertSame((int) $this->s->fiscalYear->id, (int) $entry->fiscal_year_id);

        $debit  = (string) $entry->lines()->sum('debit');
        $credit = (string) $entry->lines()->sum('credit');
        $this->assertSame(0, bccomp($debit, $credit, 2), 'Revenue entry must balance');
        $this->assertSame('40000.00', bcadd($debit, '0', 2));

        $this->assertSame('40000.00', $this->net('1600'));   // receivable up
        $this->assertSame('-40000.00', $this->net('4100'));  // revenue (credit)
    }

    public function test_cost_of_sales_entry_moves_cost_from_wip_to_cogs(): void
    {
        $sale = $this->s->draftSale([
            ['price' => '30000.00', 'cogs' => '21000.10'],
            ['price' => '25000.00', 'cogs' => '18000.20'],
        ]);

        $this->confirm($sale, $this->s->admin());

        $this->assertSame('39000.30', $this->net('5100'));
        $this->assertSame('-39000.30', $this->net('1310'));

        // every entry created by this sale balances
        foreach (JournalEntry::where('source_id', $sale->id)->get() as $je) {
            $this->assertSame(
                0,
                bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2),
                "Entry {$je->reference} is not balanced"
            );
        }
    }

    public function test_no_cost_entry_is_posted_when_there_is_no_cost_data(): void
    {
        $sale = $this->s->draftSale([['price' => '10000.00']]);

        $this->confirm($sale, $this->s->admin());

        $this->assertSame(1, JournalEntry::where('source_id', $sale->id)->count());
        $this->assertSame('0.00', $this->net('5100'));
    }

    public function test_confirming_twice_does_not_double_post(): void
    {
        $sale  = $this->s->draftSale([['price' => '10000.00', 'cogs' => '7000.00']]);
        $admin = $this->s->admin();

        $this->confirm($sale, $admin);
        $this->confirm($sale->fresh(), $admin);

        $this->assertSame(1, ArInvoice::where('company_id', $this->s->company->id)->count());
        $this->assertSame(2, JournalEntry::where('source_id', $sale->id)->count()); // revenue + cost
        $this->assertSame('10000.00', $this->net('1600'));
    }

    public function test_revenue_account_must_be_a_revenue_account(): void
    {
        $sale = $this->s->draftSale([['price' => '10000.00']]);

        $this->confirm($sale, $this->s->admin(), $this->s->account('1310')->id)
            ->assertSessionHasErrors('revenue_account_id');

        $this->assertSame('draft', $sale->fresh()->status);
        $this->assertSame(0, JournalEntry::where('source_id', $sale->id)->count());
    }

    public function test_cancelling_a_confirmed_sale_returns_the_books_to_zero(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');

        $sale  = $this->s->draftSale([['price' => '30000.00', 'cogs' => '21000.00']]);
        $admin = $this->s->admin();

        $this->confirm($sale, $admin);
        $this->actingAs($admin)->post(route('sales.cancel', $sale->id))->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('cancelled', $sale->status);
        $this->assertSame('cancelled', ArInvoice::find($sale->ar_invoice_id)->status);

        foreach (['1600', '4100', '5100', '1310'] as $code) {
            $this->assertSame('0.00', $this->net($code), "Account {$code} must net to zero after cancel");
        }

        foreach ($sale->lines as $line) {
            $this->assertSame('active', $line->animal->fresh()->status);
        }

        Carbon::setTestNow();
    }

    public function test_a_sale_that_was_paid_cannot_be_cancelled(): void
    {
        $sale  = $this->s->draftSale([['price' => '30000.00']]);
        $admin = $this->s->admin();

        $this->confirm($sale, $admin);
        ArInvoice::where('id', $sale->fresh()->ar_invoice_id)->update(['paid' => 5000, 'balance' => 25000]);

        $this->actingAs($admin)->post(route('sales.cancel', $sale->id))->assertStatus(422);

        $this->assertSame('confirmed', $sale->fresh()->status);
    }

    public function test_cancelling_a_draft_releases_the_reserved_animals(): void
    {
        $sale = $this->s->draftSale([['price' => '10000.00']]);

        $this->actingAs($this->s->admin())->post(route('sales.cancel', $sale->id))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $sale->fresh()->status);
        foreach ($sale->lines as $line) {
            $this->assertSame('active', $line->animal->fresh()->status);
        }
    }

    public function test_only_draft_sales_can_be_deleted(): void
    {
        $sale  = $this->s->draftSale([['price' => '10000.00']]);
        $admin = $this->s->admin();

        $this->confirm($sale, $admin);

        $this->actingAs($admin)->delete(route('sales.destroy', $sale->id))->assertStatus(422);
        $this->assertNotNull(\App\Models\SalesRecord::find($sale->id));
    }
}
