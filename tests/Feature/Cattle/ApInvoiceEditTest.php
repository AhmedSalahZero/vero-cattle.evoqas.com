<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  ApInvoiceEditTest   (covers QA-012 and QA-013)
//
//  Editing a supplier invoice that already has a posted accounting
//  entry:
//    • changing the total, rate, date or expense account REVERSES the
//      old entry (a new REV/ entry, original marked "reversed") and
//      posts a fresh one, so the books always show the new figures
//    • changing only a note / reference does NOT touch the books
//    • if the new entry cannot be posted (closed period, no fiscal
//      year) NOTHING is saved
//    • the supplier / expense account must belong to the same company
//
//  The ledger check uses the net of ALL lines (reversals included),
//  exactly as the reports do.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ApInvoice;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class ApInvoiceEditTest extends TestCase
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

    /** Create an "other" invoice through the real route (posts Dr expense / Cr 2210). */
    private function createOther(string $total = '1000.00', array $extra = []): ApInvoice
    {
        $this->actingAs($this->s->admin())->post(route('ap-invoices.store'), array_merge([
            'supplier_id'        => $this->s->supplier()->id,
            'invoice_type'       => 'other',
            'expense_account_id' => $this->s->account('6910')->id,
            'date'               => '2026-06-20',
            'total'              => $total,
        ], $extra))->assertSessionHasNoErrors();

        return ApInvoice::latest('id')->firstOrFail();
    }

    /** Send an edit exactly as the form would: every field, with $changes on top. */
    private function edit(ApInvoice $inv, array $changes = [])
    {
        $inv = $inv->fresh();

        return $this->actingAs($this->s->admin())->put(route('ap-invoices.update', $inv->id), array_merge([
            'supplier_id'        => $inv->supplier_id,
            'invoice_type'       => $inv->invoice_type,
            'expense_account_id' => $inv->expense_account_id,
            'date'               => Carbon::parse($inv->date)->toDateString(),
            'total'              => (string) $inv->total,
            'currency'           => $inv->currency,
            'exchange_rate'      => (string) $inv->exchange_rate,
        ], $changes));
    }

    private function entriesOf(ApInvoice $inv)
    {
        return JournalEntry::where('source_type', ApInvoice::class)->where('source_id', $inv->id);
    }

    // ── 1. changing the money re-posts the books ──────────────────

    public function test_a_new_invoice_total_replaces_the_old_figures_in_the_books(): void
    {
        $inv = $this->createOther('1000.00');
        $this->assertSame('1000.00', $this->s->net('6910'));

        $this->edit($inv, ['total' => '1500.00'])->assertSessionHasNoErrors();

        $this->assertSame('1500.00', $this->s->net('6910'));
        $this->assertSame('-1500.00', $this->s->net('2210'));
        $this->assertSame('1500.00', number_format((float) $inv->fresh()->total, 2, '.', ''));
    }

    public function test_the_old_entry_is_reversed_not_deleted_and_a_new_one_is_linked(): void
    {
        $inv   = $this->createOther('1000.00');
        $oldId = $inv->journal_entry_id;
        $this->assertNotNull($oldId);

        $this->edit($inv, ['total' => '1500.00'])->assertSessionHasNoErrors();

        $old = JournalEntry::findOrFail($oldId);
        $this->assertSame('reversed', $old->status);

        $reversal = JournalEntry::where('reversal_of_id', $oldId)->firstOrFail();
        $this->assertSame('posted', $reversal->status);
        $this->assertStringStartsWith('REV/', $reversal->reference);

        $new = JournalEntry::findOrFail($inv->fresh()->journal_entry_id);
        $this->assertNotSame($oldId, $new->id);
        $this->assertSame('posted', $new->status);
    }

    public function test_the_reversal_and_the_new_entry_both_balance(): void
    {
        $inv   = $this->createOther('1000.00');
        $oldId = $inv->journal_entry_id;

        $this->edit($inv, ['total' => '1234.56'])->assertSessionHasNoErrors();

        foreach ([JournalEntry::where('reversal_of_id', $oldId)->firstOrFail(), JournalEntry::findOrFail($inv->fresh()->journal_entry_id)] as $je) {
            $this->assertSame(
                0,
                bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2),
                "Entry {$je->reference} must balance"
            );
        }
    }

    public function test_the_open_balance_follows_the_new_total(): void
    {
        $inv = $this->createOther('1000.00');

        $this->edit($inv, ['total' => '1800.00'])->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertSame('1800.00', number_format((float) $inv->balance, 2, '.', ''));
        $this->assertSame('open', $inv->status);
    }

    public function test_changing_the_expense_account_moves_the_cost(): void
    {
        $inv = $this->createOther('1000.00');

        $this->edit($inv, ['expense_account_id' => $this->s->account('6920')->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->s->net('6910'));
        $this->assertSame('1000.00', $this->s->net('6920'));
        $this->assertSame('-1000.00', $this->s->net('2210'));
    }

    public function test_changing_the_date_re_posts_the_entry_on_the_new_date(): void
    {
        $inv = $this->createOther('1000.00');

        $this->edit($inv, ['date' => '2026-08-05'])->assertSessionHasNoErrors();

        $new = JournalEntry::findOrFail($inv->fresh()->journal_entry_id);
        $this->assertSame('2026-08-05', Carbon::parse($new->date)->toDateString());
        $this->assertSame(8, (int) $new->period);
        $this->assertSame('1000.00', $this->s->net('6910'));   // nothing doubled
    }

    public function test_a_new_exchange_rate_re_prices_a_foreign_invoice_in_egp(): void
    {
        $inv = $this->createOther('100.00', ['currency' => 'USD', 'exchange_rate' => '50']);
        $this->assertSame('5000.00', $this->s->net('6910'));

        $this->edit($inv, ['exchange_rate' => '55'])->assertSessionHasNoErrors();

        $this->assertSame('5500.00', $this->s->net('6910'));
        $this->assertSame('-5500.00', $this->s->net('2210'));
    }

    public function test_editing_twice_leaves_only_the_latest_figures_in_the_books(): void
    {
        $inv = $this->createOther('1000.00');

        $this->edit($inv, ['total' => '1500.00'])->assertSessionHasNoErrors();
        $this->edit($inv, ['total' => '700.00'])->assertSessionHasNoErrors();

        $this->assertSame('700.00', $this->s->net('6910'));
        $this->assertSame('-700.00', $this->s->net('2210'));
        // original + 2 reversals + 2 new entries... but only ONE entry is live
        $this->assertSame(1, $this->entriesOf($inv)->where('status', 'posted')->count());
    }

    // ── 2. harmless edits leave the books alone ───────────────────

    public function test_changing_only_a_note_does_not_touch_the_books(): void
    {
        $inv   = $this->createOther('1000.00');
        $jeId  = $inv->journal_entry_id;
        $count = JournalEntry::count();

        $this->edit($inv, ['notes' => 'Delivered late', 'reference' => 'SUP-77', 'description' => 'Feed freight'])
            ->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertSame('Delivered late', $inv->notes);
        $this->assertSame('SUP-77', $inv->reference);
        $this->assertSame($jeId, $inv->journal_entry_id);
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame('posted', JournalEntry::findOrFail($jeId)->status);
    }

    // ── 3. an edit that cannot be re-posted saves NOTHING ─────────

    public function test_an_edit_into_a_date_with_no_open_fiscal_year_is_refused_and_nothing_changes(): void
    {
        $inv   = $this->createOther('1000.00');
        $jeId  = $inv->journal_entry_id;
        $count = JournalEntry::count();

        $this->edit($inv, ['date' => '2027-02-01', 'total' => '2000.00'])
            ->assertSessionHasErrors('total');

        $inv->refresh();
        $this->assertSame('1000.00', number_format((float) $inv->total, 2, '.', ''));
        $this->assertSame('2026-06-20', Carbon::parse($inv->date)->toDateString());
        $this->assertSame($jeId, $inv->journal_entry_id);
        $this->assertSame('posted', JournalEntry::findOrFail($jeId)->status);   // reversal rolled back
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame('1000.00', $this->s->net('6910'));
    }

    public function test_an_edit_is_refused_when_the_original_entry_sits_in_a_closed_year(): void
    {
        $inv   = $this->createOther('1000.00');
        $jeId  = $inv->journal_entry_id;
        $count = JournalEntry::count();

        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->edit($inv, ['total' => '2000.00'])->assertSessionHasErrors('date');

        $this->assertSame('1000.00', number_format((float) $inv->fresh()->total, 2, '.', ''));
        $this->assertSame('posted', JournalEntry::findOrFail($jeId)->status);
        $this->assertSame($count, JournalEntry::count());
    }

    // ── 4. the same-company rules (QA-012) ────────────────────────

    public function test_an_edit_cannot_switch_to_another_companys_supplier(): void
    {
        $inv   = $this->createOther('1000.00');
        $other = CattleScenario::make();

        $this->edit($inv, ['supplier_id' => $other->supplier()->id])
            ->assertSessionHasErrors('supplier_id');

        $this->assertNotSame(
            $other->company->id,
            $inv->fresh()->supplier->company_id
        );
    }

    public function test_an_edit_cannot_use_another_companys_expense_account(): void
    {
        $inv   = $this->createOther('1000.00');
        $other = CattleScenario::make();

        $this->edit($inv, ['expense_account_id' => $other->account('6910')->id])
            ->assertSessionHasErrors('expense_account_id');

        $this->assertSame('1000.00', $this->s->net('6910'));
    }

    public function test_an_edit_cannot_use_a_group_header_as_the_expense_account(): void
    {
        $inv = $this->createOther('1000.00');

        // 6900 "G&A Expenses" is a group (no direct posting)
        $this->edit($inv, ['expense_account_id' => $this->s->account('6900')->id])
            ->assertSessionHasErrors('expense_account_id');
    }

    // ── 5. who can edit what ──────────────────────────────────────

    public function test_a_paid_invoice_cannot_be_edited(): void
    {
        $inv = $this->createOther('1000.00');
        $inv->update(['status' => 'paid', 'paid' => 1000, 'balance' => 0]);

        $this->edit($inv, ['total' => '2000.00'])->assertStatus(422);

        $this->assertSame('1000.00', $this->s->net('6910'));
    }

    public function test_a_user_without_edit_permission_cannot_edit(): void
    {
        $inv = $this->createOther('1000.00');

        $this->actingAs($this->s->user(['view ap_invoices']))
            ->put(route('ap-invoices.update', $inv->id), [
                'supplier_id' => $inv->supplier_id, 'invoice_type' => 'other',
                'expense_account_id' => $inv->expense_account_id,
                'date' => '2026-06-20', 'total' => '2000.00',
            ])->assertForbidden();

        $this->assertSame('1000.00', $this->s->net('6910'));
    }

    public function test_another_company_cannot_edit_this_invoice(): void
    {
        $inv   = $this->createOther('1000.00');
        $other = CattleScenario::make();

        $this->actingAs($other->admin())
            ->put(route('ap-invoices.update', $inv->id), [
                'supplier_id' => $inv->supplier_id, 'invoice_type' => 'other',
                'expense_account_id' => $inv->expense_account_id,
                'date' => '2026-06-20', 'total' => '2000.00',
            ])->assertForbidden();

        $this->assertSame('1000.00', $this->s->net('6910'));
    }

    // ── 6. an invoice matched to receipts ─────────────────────────

    /** A cattle invoice of $total matched to one received PO of 55,050 (300 kg + 250.5 kg at 100). */
    private function matchedCattleInvoice(string $total = '55050.00'): ApInvoice
    {
        $supplier = $this->s->supplier();
        $admin    = $this->s->admin();
        $po       = $this->s->draftCattlePo($supplier);

        $this->actingAs($admin)->patch(route('procurement.confirm-po', $po->id))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('procurement.confirm-receipt', $po->id), [
            'animals' => [
                ['tag' => 'E' . uniqid() . '-0', 'sex' => 'male', 'weight_kg' => 300],
                ['tag' => 'E' . uniqid() . '-1', 'sex' => 'male', 'weight_kg' => 250.5],
            ],
            'batch_date'   => '2026-06-10',
            'receipt_mode' => 'individual',
        ])->assertSessionHasNoErrors();

        $inv = ApInvoice::create([
            'company_id' => $this->s->company->id, 'supplier_id' => $supplier->id,
            'date' => '2026-06-20', 'total' => $total, 'paid' => 0, 'balance' => $total,
            'currency' => 'EGP', 'exchange_rate' => 1, 'status' => 'open', 'invoice_type' => 'cattle',
        ]);

        $this->actingAs($admin)->patch(route('ap-invoices.match-accruals', $inv->id), [
            'transaction_ids' => InventoryTransaction::where('supplier_id', $supplier->id)
                ->where('type', 'livestock_receipt')->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        return $inv->fresh();
    }

    public function test_editing_the_total_of_a_matched_invoice_re_posts_the_match_entry(): void
    {
        $inv = $this->matchedCattleInvoice('55050.00');
        $this->assertSame('0.00', $this->s->net('5200'));

        $this->edit($inv, ['total' => '56000.00'])->assertSessionHasNoErrors();

        $this->assertSame('-56000.00', $this->s->net('2210'));   // we now owe the new invoice total
        $this->assertSame('950.00', $this->s->net('5200'));      // price difference vs the receipts
        $this->assertSame('0.00', $this->s->net('2230'));        // accrual still cleared, not doubled
        $this->assertSame(
            1,
            $this->entriesOf($inv)->where('entry_type', 'procurement')->where('status', 'posted')->count()
        );
    }

    public function test_editing_a_matched_invoice_keeps_the_receipts_matched(): void
    {
        $inv = $this->matchedCattleInvoice('55050.00');
        $matchedBefore = InventoryTransaction::where('ap_invoice_id', $inv->id)->count();
        $this->assertGreaterThan(0, $matchedBefore);

        $this->edit($inv, ['total' => '56000.00'])->assertSessionHasNoErrors();

        $this->assertSame(
            $matchedBefore,
            InventoryTransaction::where('ap_invoice_id', $inv->id)->count()
        );
    }

    public function test_a_matched_invoice_edit_in_a_closed_year_changes_nothing(): void
    {
        $inv = $this->matchedCattleInvoice('55050.00');
        $this->s->fiscalYear->update(['status' => 'closed']);

        $this->edit($inv, ['total' => '56000.00'])->assertSessionHasErrors();

        $this->assertSame('55050.00', number_format((float) $inv->fresh()->total, 2, '.', ''));
        $this->assertSame('-55050.00', $this->s->net('2210'));
    }
}
