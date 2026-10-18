<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  ReportsAgreeWithBooksTest
//
//  Runs a small business through the real screens (funding, buying
//  feed, receiving it, matching the supplier invoice, feeding the lot,
//  selling animals, cancelling a sale) and then proves the financial
//  reports tell the same story as the journal:
//    • Trial Balance balances and every closing balance equals the ledger
//    • Profit & Loss revenue / cost / profit equal the ledger
//    • Balance Sheet balances and its assets equal the ledger
//    • date windows are respected, cancelled sales net to zero
//    • another company's postings never leak in
//    • reports need the "view reports" permission
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Account;
use App\Models\ApInvoice;
use App\Models\InventoryTransaction;
use App\Models\JournalLine;
use App\Models\ProcurementItemLine;
use App\Models\ProcurementRecord;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class ReportsAgreeWithBooksTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;
    private FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s       = CattleScenario::make();
        $this->reports = app(FinancialReportService::class);
    }

    // ── the story ─────────────────────────────────────────────────

    /** Raw ledger movement of an account for a company: debit - credit (posted only). */
    private function ledger(Account $account, ?string $from = null, ?string $to = null): string
    {
        $q = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereNull('je.deleted_at');
        if ($from) $q->whereDate('je.date', '>=', $from);
        if ($to)   $q->whereDate('je.date', '<=', $to);

        return bcsub((string) $q->sum('journal_lines.debit'), (string) $q->sum('journal_lines.credit'), 2);
    }

    /** Funding, a feed purchase, feeding, a sale. Returns the confirmed sale. */
    private function runBusiness(?CattleScenario $s = null): void
    {
        $s ??= $this->s;
        $admin = $s->admin();

        // 1. owner puts 100,000 into the cash safe (5 Jan)
        $s->fund($s->cashAccount('1401'), '100000.00', '2026-01-05');

        // 2. buy 100 kg feed at 10, receive it, match the invoice (1,000)
        $stk      = $s->stockedItem('feed', '0', '0');
        $supplier = $s->supplier();
        $this->actingAs($admin)->post(route('procurement.store-inventory-po'), [
            'farm_id' => $s->farm->id, 'supplier_id' => $supplier->id, 'date' => '2026-02-01',
            'item_lines' => [[
                'inventory_item_id' => $stk['master']->id, 'location_id' => $stk['location']->id,
                'qty_ordered' => 100, 'unit_cost_estimated' => 10,
            ]],
        ])->assertSessionHasNoErrors();
        $po = ProcurementRecord::where('company_id', $s->company->id)->where('po_type', 'inventory')->latest('id')->firstOrFail();
        $this->actingAs($admin)->patch(route('procurement.confirm-inventory-po', $po->id))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('procurement.confirm-inventory-receipt', $po->id), [
            'batch_date' => '2026-02-10',
            'lines' => [[
                'procurement_item_line_id' => ProcurementItemLine::where('procurement_id', $po->id)->firstOrFail()->id,
                'qty_received' => 100, 'unit_cost_actual' => 10, 'location_id' => $stk['location']->id,
            ]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('ap-invoices.store'), [
            'supplier_id' => $supplier->id, 'invoice_type' => 'inventory', 'date' => '2026-02-20', 'total' => 1000,
        ])->assertSessionHasNoErrors();
        $invoice = ApInvoice::where('company_id', $s->company->id)->latest('id')->firstOrFail();
        $this->actingAs($admin)->patch(route('ap-invoices.match-accruals', $invoice->id), [
            'transaction_ids' => InventoryTransaction::where('company_id', $s->company->id)
                ->where('type', 'receipt')->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        // 3. feed 40 kg to the lot (10 March) -> 400 into WIP
        $animal = $s->animal();
        $this->actingAs($admin)->post(route('feed.consumption.batch'), [
            'farm_id' => $s->farm->id, 'lot_id' => $s->lot->id, 'date' => '2026-03-10',
            'animal_ids' => [$animal->id],
            'rows' => [['inventory_item_id' => $stk['master']->id, 'location_id' => $stk['location']->id, 'qty_kg' => 40]],
        ])->assertSessionHasNoErrors();

        // 4. sell one animal for 40,000 that cost 30,000 (15 June)
        $sale = $s->draftSale([['price' => '40000.00', 'cogs' => '30000.00']], '2026-06-15');
        $this->actingAs($admin)->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $s->account('4100')->id,
        ])->assertSessionHasNoErrors();
    }

    // ── Trial Balance ─────────────────────────────────────────────

    public function test_trial_balance_balances_and_every_closing_balance_equals_the_ledger(): void
    {
        $this->runBusiness();

        $tb = $this->reports->trialBalance($this->s->company->id, '2026-01-01', '2026-12-31');

        $this->assertTrue($tb['balanced'], 'Trial balance must balance');
        $this->assertEquals($tb['totals']['period_debit'], $tb['totals']['period_credit']);
        $this->assertNotEmpty($tb['rows']);

        foreach ($tb['rows'] as $row) {
            $acc = Account::findOrFail($row['account_id']);
            $net = $this->ledger($acc);
            $expected = $acc->normal_balance === 'debit' ? $net : bcmul($net, '-1', 2);
            $this->assertEquals((float) $expected, $row['closing'], "Closing balance of {$acc->code}");
        }
    }

    public function test_trial_balance_for_a_later_period_carries_the_earlier_balances_as_opening(): void
    {
        $this->runBusiness();

        $tb = $this->reports->trialBalance($this->s->company->id, '2026-03-01', '2026-12-31');
        $cash = collect($tb['rows'])->firstWhere('code', '1401');

        $this->assertEquals(100000, $cash['opening']);       // funded in January
        $this->assertTrue($tb['balanced']);
    }

    // ── Profit & Loss ─────────────────────────────────────────────

    public function test_profit_and_loss_equals_the_ledger(): void
    {
        $this->runBusiness();

        $pl = $this->reports->profitAndLoss($this->s->company->id, '2026-01-01', '2026-12-31')['current'];

        $this->assertEquals(40000, $pl['total_revenue']);
        $this->assertEquals(30000, $pl['total_cogs']);
        $this->assertEquals(10000, $pl['gross_profit']);
        $this->assertEquals(10000, $pl['net_income']);       // no expenses booked
        $this->assertEquals(25, round((float) $pl['gross_margin_pct'], 2));

        $this->assertEquals(-(float) $this->ledger($this->s->account('4100')), $pl['total_revenue']);
        $this->assertEquals((float) $this->ledger($this->s->account('5100')), $pl['total_cogs']);
    }

    public function test_profit_and_loss_respects_the_date_window(): void
    {
        $this->runBusiness();

        $before = $this->reports->profitAndLoss($this->s->company->id, '2026-01-01', '2026-05-31')['current'];
        $this->assertEquals(0, $before['total_revenue']);
        $this->assertEquals(0, $before['total_cogs']);

        $june = $this->reports->profitAndLoss($this->s->company->id, '2026-06-01', '2026-06-30')['current'];
        $this->assertEquals(40000, $june['total_revenue']);
    }

    public function test_a_cancelled_sale_nets_out_of_the_profit_and_loss(): void
    {
        $this->runBusiness();
        $second = $this->s->draftSale([['price' => '5000.00', 'cogs' => '3000.00']], '2026-07-01');
        $admin  = $this->s->admin();

        $this->actingAs($admin)->patch(route('sales.confirm', $second->id), [
            'revenue_account_id' => $this->s->account('4100')->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('sales.cancel', $second->id))->assertSessionHasNoErrors();

        $pl = $this->reports->profitAndLoss($this->s->company->id, '2026-01-01', '2026-12-31')['current'];

        $this->assertEquals(40000, $pl['total_revenue']);    // the 5,000 sale cancelled out
        $this->assertEquals(30000, $pl['total_cogs']);

        // A reversed entry stays in the ledger beside its mirror: the books still balance
        $tb = $this->reports->trialBalance($this->s->company->id, '2026-01-01', '2026-12-31');
        $this->assertTrue($tb['balanced']);
        foreach ($tb['rows'] as $row) {
            $acc = Account::findOrFail($row['account_id']);
            $net = $this->ledger($acc);
            $this->assertEquals((float) ($acc->normal_balance === 'debit' ? $net : bcmul($net, '-1', 2)), $row['closing'], $acc->code);
        }
    }

    // ── Balance Sheet ─────────────────────────────────────────────

    public function test_balance_sheet_balances_and_assets_equal_the_ledger(): void
    {
        $this->runBusiness();

        $bs = $this->reports->balanceSheet($this->s->company->id, '2026-12-31', $this->s->fiscalYear->id);

        $this->assertTrue($bs['balanced'], 'Balance sheet must balance (imbalance ' . $bs['imbalance'] . ')');
        $this->assertEqualsWithDelta(0, $bs['imbalance'], 0.01);

        $ledgerAssets = '0';
        foreach (Account::where('company_id', $this->s->company->id)->where('type', 'asset')->get() as $acc) {
            $ledgerAssets = bcadd($ledgerAssets, $this->ledger($acc), 2);
        }
        $this->assertEquals((float) $ledgerAssets, $bs['total_assets']);
    }

    public function test_balance_sheet_as_of_an_early_date_ignores_later_postings(): void
    {
        $this->runBusiness();

        $bs = $this->reports->balanceSheet($this->s->company->id, '2026-01-31', $this->s->fiscalYear->id);

        $this->assertEquals(100000, $bs['total_assets']);    // only the owner's funding so far
        $this->assertTrue($bs['balanced']);
    }

    // ── Other companies ───────────────────────────────────────────

    public function test_another_companys_postings_never_leak_into_the_reports(): void
    {
        $this->runBusiness();
        $before = $this->reports->profitAndLoss($this->s->company->id, '2026-01-01', '2026-12-31')['current'];

        $other = CattleScenario::make();
        $this->runBusiness($other);

        $after = $this->reports->profitAndLoss($this->s->company->id, '2026-01-01', '2026-12-31')['current'];
        $tb    = $this->reports->trialBalance($this->s->company->id, '2026-01-01', '2026-12-31');

        $this->assertEquals($before['total_revenue'], $after['total_revenue']);
        $this->assertEquals($before['net_income'], $after['net_income']);
        $this->assertEquals(100000, collect($tb['rows'])->firstWhere('code', '1401')['closing']);
    }

    // ── The screens ───────────────────────────────────────────────

    public function test_report_pages_open_for_a_user_with_view_reports(): void
    {
        $this->runBusiness();
        $user = $this->s->user(['view reports']);

        $this->actingAs($user)->get(route('reports.trial-balance', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']))->assertOk();
        $this->actingAs($user)->get(route('reports.profit-loss', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertOk();
        $this->actingAs($user)->get(route('reports.balance-sheet', ['as_of_date' => '2026-12-31']))->assertOk();
    }

    public function test_report_pages_are_refused_without_view_reports(): void
    {
        $this->runBusiness();
        $user = $this->s->user(['view sales']);

        $this->actingAs($user)->get(route('reports.trial-balance', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.profit-loss', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.balance-sheet', ['as_of_date' => '2026-12-31']))->assertForbidden();
    }

    public function test_a_report_with_a_backwards_date_range_is_refused(): void
    {
        $user = $this->s->user(['view reports']);

        $this->actingAs($user)
            ->get(route('reports.trial-balance', ['period_start' => '2026-12-31', 'period_end' => '2026-01-01']))
            ->assertSessionHasErrors('period_end');
    }
}
