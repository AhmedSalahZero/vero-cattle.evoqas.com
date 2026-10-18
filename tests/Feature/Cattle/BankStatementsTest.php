<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  BankStatementsTest
//
//  Bank statement lines and reconciliation, through the real routes:
//    • lines can only be recorded against cash/bank accounts of the
//      user's own company
//    • a line is matched to ONE posted entry that really moved that
//      bank account; the same entry cannot serve two lines
//    • only a matched line can be reconciled; a reconciled line is
//      frozen (no edit, unmatch, or delete)
//    • who may do what, and nobody touches another company's lines
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\BankStatement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class BankStatementsTest extends TestCase
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

    private function staff()
    {
        return $this->s->user(['view bank_statements', 'create bank_statements', 'edit bank_statements', 'delete bank_statements']);
    }

    private function line(array $over = []): BankStatement
    {
        return BankStatement::create(array_merge([
            'company_id'    => $this->s->company->id,
            'account_id'    => $this->s->bankAccount()->id,
            'date'          => '2026-03-01',
            'description'   => 'Deposit',
            'amount'        => 500,
            'is_matched'    => false,
            'is_reconciled' => false,
        ], $over));
    }

    /** A posted entry that moves the bank account. */
    private function bankEntry(string $status = 'posted', ?\App\Models\Account $account = null): JournalEntry
    {
        $account ??= $this->s->bankAccount();
        $equity   = $this->s->account('3100');

        $je = JournalEntry::create([
            'company_id'     => $this->s->company->id,
            'fiscal_year_id' => $this->s->fiscalYear->id,
            'date'           => '2026-03-01',
            'period'         => 3,
            'reference'      => 'BK/' . uniqid(),
            'description'    => 'Bank movement',
            'status'         => $status,
            'entry_type'     => 'manual',
            'total_debit'    => 500,
            'total_credit'   => 500,
        ]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $account->id, 'debit' => 500, 'credit' => 0]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $equity->id, 'debit' => 0, 'credit' => 500]);
        return $je;
    }

    // ── Creating lines ────────────────────────────────────────────

    public function test_single_line_is_recorded_for_a_bank_account(): void
    {
        $this->actingAs($this->staff())->post(route('bank-statements.store'), [
            'account_id'  => $this->s->bankAccount()->id,
            'date'        => '2026-03-02',
            'description' => 'Salary paid',
            'amount'      => -1200.50,
        ])->assertSessionHasNoErrors();

        $line = BankStatement::where('company_id', $this->s->company->id)->firstOrFail();
        $this->assertFalse($line->is_matched);
        $this->assertFalse($line->is_reconciled);
        $this->assertSame('-1200.50', (string) $line->amount);
    }

    public function test_bulk_import_creates_every_line(): void
    {
        $acc = $this->s->bankAccount()->id;
        $this->actingAs($this->staff())->post(route('bank-statements.store'), ['lines' => [
            ['account_id' => $acc, 'date' => '2026-03-02', 'description' => 'A', 'amount' => 10],
            ['account_id' => $acc, 'date' => '2026-03-03', 'description' => 'B', 'amount' => -4],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(2, BankStatement::where('company_id', $this->s->company->id)->count());
    }

    public function test_a_non_bank_account_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->staff())->post(route('bank-statements.store'), [
            'account_id'  => $this->s->account('6910')->id,
            'date'        => '2026-03-02',
            'description' => 'x',
            'amount'      => 1,
        ])->assertStatus(404);

        $this->assertSame(0, BankStatement::count());
    }

    public function test_other_companys_bank_account_is_refused(): void
    {
        $other = CattleScenario::make();

        $this->actingAs($this->staff())->post(route('bank-statements.store'), [
            'account_id'  => $other->bankAccount()->id,
            'date'        => '2026-03-02',
            'description' => 'x',
            'amount'      => 1,
        ])->assertStatus(404);

        $this->assertSame(0, BankStatement::count());
    }

    public function test_bulk_import_is_all_or_nothing(): void
    {
        $this->actingAs($this->staff())->post(route('bank-statements.store'), ['lines' => [
            ['account_id' => $this->s->bankAccount()->id, 'date' => '2026-03-02', 'description' => 'ok', 'amount' => 10],
            ['account_id' => $this->s->account('6910')->id, 'date' => '2026-03-03', 'description' => 'bad', 'amount' => 5],
        ]])->assertStatus(404);

        $this->assertSame(0, BankStatement::count());
    }

    // ── Matching ──────────────────────────────────────────────────

    public function test_line_matches_a_posted_entry_on_the_same_account(): void
    {
        $line = $this->line();
        $je   = $this->bankEntry();

        $this->actingAs($this->staff())->patch(route('bank-statements.match', $line), ['journal_entry_id' => $je->id])
            ->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertTrue($line->is_matched);
        $this->assertSame($je->id, (int) $line->matched_entry_id);
    }

    public function test_draft_entry_cannot_be_matched(): void
    {
        $line = $this->line();
        $je   = $this->bankEntry('draft');

        $this->actingAs($this->staff())->patch(route('bank-statements.match', $line), ['journal_entry_id' => $je->id])
            ->assertSessionHasErrors('journal_entry_id');

        $this->assertFalse($line->fresh()->is_matched);
    }

    public function test_entry_that_never_touched_the_bank_account_cannot_be_matched(): void
    {
        $line  = $this->line();
        $other = $this->s->cashAccount('1402');
        $je    = $this->bankEntry('posted', $other);

        $this->actingAs($this->staff())->patch(route('bank-statements.match', $line), ['journal_entry_id' => $je->id])
            ->assertSessionHasErrors('journal_entry_id');

        $this->assertFalse($line->fresh()->is_matched);
    }

    public function test_one_entry_cannot_serve_two_lines(): void
    {
        $je    = $this->bankEntry();
        $first = $this->line();
        $second = $this->line(['description' => 'Second']);

        $this->actingAs($this->staff())->patch(route('bank-statements.match', $first), ['journal_entry_id' => $je->id]);
        $this->actingAs($this->staff())->patch(route('bank-statements.match', $second), ['journal_entry_id' => $je->id])
            ->assertSessionHasErrors('journal_entry_id');

        $this->assertFalse($second->fresh()->is_matched);
    }

    public function test_other_companys_entry_cannot_be_matched(): void
    {
        $line  = $this->line();
        $other = CattleScenario::make();
        $je = JournalEntry::create([
            'company_id' => $other->company->id, 'fiscal_year_id' => $other->fiscalYear->id,
            'date' => '2026-03-01', 'period' => 3, 'reference' => 'X/' . uniqid(), 'description' => 'x',
            'status' => 'posted', 'entry_type' => 'manual', 'total_debit' => 1, 'total_credit' => 1,
        ]);

        $this->actingAs($this->staff())->patch(route('bank-statements.match', $line), ['journal_entry_id' => $je->id])
            ->assertStatus(403);
    }

    public function test_unmatch_clears_the_link(): void
    {
        $je   = $this->bankEntry();
        $line = $this->line(['matched_entry_id' => $je->id, 'is_matched' => true]);

        $this->actingAs($this->staff())->patch(route('bank-statements.unmatch', $line))->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertFalse($line->is_matched);
        $this->assertNull($line->matched_entry_id);
    }

    // ── Reconciling ───────────────────────────────────────────────

    public function test_unmatched_line_cannot_be_reconciled(): void
    {
        $line = $this->line();

        $this->actingAs($this->staff())->patch(route('bank-statements.reconcile', $line))->assertStatus(422);
        $this->assertFalse($line->fresh()->is_reconciled);
    }

    public function test_reconciled_line_is_frozen(): void
    {
        $je   = $this->bankEntry();
        $line = $this->line(['matched_entry_id' => $je->id, 'is_matched' => true]);
        $u    = $this->staff();

        $this->actingAs($u)->patch(route('bank-statements.reconcile', $line))->assertSessionHasNoErrors();
        $this->assertTrue($line->fresh()->is_reconciled);

        $this->actingAs($u)->put(route('bank-statements.update', $line), [
            'date' => '2026-03-05', 'description' => 'Changed', 'amount' => 9,
        ])->assertStatus(422);
        $this->actingAs($u)->patch(route('bank-statements.unmatch', $line))->assertStatus(422);
        $this->actingAs($u)->patch(route('bank-statements.match', $line), ['journal_entry_id' => $je->id])->assertStatus(422);
        $this->actingAs($u)->delete(route('bank-statements.destroy', $line))->assertStatus(422);

        $line->refresh();
        $this->assertSame('Deposit', $line->description);
        $this->assertTrue($line->is_matched);
    }

    // ── Edit / delete ─────────────────────────────────────────────

    public function test_open_line_can_be_edited_and_deleted(): void
    {
        $line = $this->line();
        $u    = $this->staff();

        $this->actingAs($u)->put(route('bank-statements.update', $line), [
            'date' => '2026-03-09', 'description' => 'Corrected', 'amount' => 750,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Corrected', $line->fresh()->description);

        $this->actingAs($u)->delete(route('bank-statements.destroy', $line))->assertSessionHasNoErrors();
        $this->assertNull(BankStatement::find($line->id));
    }

    // ── Permissions & company boundary ────────────────────────────

    public function test_view_only_user_cannot_change_anything(): void
    {
        $viewer = $this->s->user(['view bank_statements']);
        $line   = $this->line();

        $this->actingAs($viewer)->post(route('bank-statements.store'), [
            'account_id' => $this->s->bankAccount()->id, 'date' => '2026-03-02', 'description' => 'x', 'amount' => 1,
        ])->assertStatus(403);
        $this->actingAs($viewer)->put(route('bank-statements.update', $line), ['date' => '2026-03-02', 'description' => 'x', 'amount' => 1])->assertStatus(403);
        $this->actingAs($viewer)->patch(route('bank-statements.reconcile', $line))->assertStatus(403);
        $this->actingAs($viewer)->delete(route('bank-statements.destroy', $line))->assertStatus(403);

        $this->assertSame(1, BankStatement::count());
    }

    public function test_other_company_user_cannot_touch_the_line(): void
    {
        $line  = $this->line();
        $other = CattleScenario::make()->user(['view bank_statements', 'create bank_statements', 'edit bank_statements', 'delete bank_statements']);

        $this->actingAs($other)->put(route('bank-statements.update', $line), ['date' => '2026-03-02', 'description' => 'x', 'amount' => 1])->assertStatus(403);
        $this->actingAs($other)->patch(route('bank-statements.unmatch', $line))->assertStatus(403);
        $this->actingAs($other)->patch(route('bank-statements.reconcile', $line))->assertStatus(403);
        $this->actingAs($other)->delete(route('bank-statements.destroy', $line))->assertStatus(403);

        $this->assertNotNull(BankStatement::find($line->id));
    }
}
