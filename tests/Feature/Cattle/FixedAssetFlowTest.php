<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetFlowTest
//
//  Buying, depreciating and selling a fixed asset, through the real
//  routes:
//    purchase     : Dr asset account            / Cr cash-bank-payable
//    depreciation : Dr depreciation expense     / Cr accumulated depreciation
//    disposal     : Dr cash + accumulated dep.  / Cr asset  ± gain-loss (6800)
//
//  Also guards: land never depreciates, accounts must belong to the
//  company, a disposed asset is final.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Account;
use App\Models\AssetDepreciationLine;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class FixedAssetFlowTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;
    private Account $assetAcct;
    private Account $accDepAcct;
    private Account $depExpAcct;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s = CattleScenario::make();

        $this->assetAcct  = Account::factory()->create(['company_id' => $this->s->company->id, 'code' => '1141', 'type' => 'asset']);
        $this->accDepAcct = Account::factory()->create(['company_id' => $this->s->company->id, 'code' => '1149', 'type' => 'asset']);
        $this->depExpAcct = Account::factory()->create(['company_id' => $this->s->company->id, 'code' => '6101', 'type' => 'expense']);
        $this->cash       = $this->s->cashAccount();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'farm_id'                => $this->s->farm->id,
            'asset_code'             => 'FA-' . uniqid(),
            'name_en'                => 'Feed mixer',
            'category'               => 'equipment',
            'purchase_date'          => '2026-02-01',
            'purchase_cost'          => 120000,
            'salvage_value'          => 0,
            'depreciation_method'    => 'straight_line',
            'useful_life_months'     => 60,
            'asset_account_id'       => $this->assetAcct->id,
            'acc_dep_account_id'     => $this->accDepAcct->id,
            'dep_expense_account_id' => $this->depExpAcct->id,
            'payment_account_id'     => $this->cash->id,
        ], $over);
    }

    private function buy(array $over = [])
    {
        return $this->actingAs($this->s->admin())->post(route('fixed-assets.store'), $this->payload($over));
    }

    private function boughtAsset(array $over = []): FixedAsset
    {
        $this->buy($over)->assertSessionHasNoErrors();
        return FixedAsset::latest('id')->firstOrFail();
    }

    // ── purchase ──────────────────────────────────────────────────

    public function test_buying_an_asset_posts_asset_up_and_cash_down(): void
    {
        $asset = $this->boughtAsset();

        $this->assertSame('120000.00', $this->s->net($this->assetAcct));
        $this->assertSame('-120000.00', $this->s->net($this->cash));

        $je = JournalEntry::findOrFail($asset->journal_entry_id);
        $this->assertSame('posted', $je->status);
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2));
    }

    public function test_the_purchase_entry_is_dated_on_the_purchase_date(): void
    {
        $asset = $this->boughtAsset(['purchase_date' => '2026-03-15']);

        $this->assertSame('2026-03-15', JournalEntry::findOrFail($asset->journal_entry_id)->date->toDateString());
    }

    public function test_land_never_depreciates(): void
    {
        $asset = $this->boughtAsset([
            'category' => 'land', 'depreciation_method' => 'straight_line',
            'useful_life_months' => 60, 'salvage_value' => 5000,
        ]);

        $this->assertSame('none', $asset->depreciation_method);
        $this->assertNull($asset->useful_life_months);
        $this->assertSame('0.00', number_format((float) $asset->salvage_value, 2, '.', ''));
    }

    public function test_the_asset_code_must_be_unique(): void
    {
        $this->buy(['asset_code' => 'DUP-1'])->assertSessionHasNoErrors();
        $this->buy(['asset_code' => 'DUP-1'])->assertSessionHasErrors('asset_code');

        $this->assertSame(1, FixedAsset::count());
    }

    public function test_a_name_in_at_least_one_language_is_required(): void
    {
        $this->buy(['name_en' => null, 'name_ar' => null])->assertSessionHasErrors('name_en');
        $this->assertSame(0, FixedAsset::count());

        $this->buy(['name_en' => null, 'name_ar' => 'خلاط أعلاف'])->assertSessionHasNoErrors();
        $this->assertSame(1, FixedAsset::count());
    }

    public function test_accounts_of_another_company_are_refused(): void
    {
        $other = CattleScenario::make();
        $theirs = Account::factory()->create(['company_id' => $other->company->id, 'code' => '1141', 'type' => 'asset']);

        $this->buy(['asset_account_id' => $theirs->id])->assertSessionHasErrors('asset_account_id');
        $this->buy(['payment_account_id' => $other->cashAccount()->id])->assertSessionHasErrors('payment_account_id');

        $this->assertSame(0, FixedAsset::count());
    }

    // ── depreciation ──────────────────────────────────────────────

    public function test_a_depreciation_period_posts_expense_and_accumulated_depreciation(): void
    {
        $asset = $this->boughtAsset();
        $admin = $this->s->admin();

        $this->actingAs($admin)->post(route('fixed-assets.schedule', $asset->id), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertSessionHasNoErrors();

        $line = AssetDepreciationLine::where('asset_id', $asset->id)->orderBy('period_month')->firstOrFail();
        $this->assertGreaterThan(0, (float) $line->amount);

        $this->actingAs($admin)->post(route('fixed-assets.post-period', $asset->id), [
            'fiscal_year_id' => $line->fiscal_year_id,
            'period_month'   => $line->period_month,
        ])->assertSessionHasNoErrors();

        $amount = number_format((float) $line->amount, 2, '.', '');
        $this->assertSame($amount, $this->s->net($this->depExpAcct));
        $this->assertSame('-' . $amount, $this->s->net($this->accDepAcct));
        $this->assertSame($amount, number_format((float) $asset->fresh()->accumulated_depreciation, 2, '.', ''));
        $this->assertTrue((bool) $line->fresh()->is_posted);
    }

    public function test_the_same_period_cannot_be_posted_twice(): void
    {
        $asset = $this->boughtAsset();
        $admin = $this->s->admin();

        $this->actingAs($admin)->post(route('fixed-assets.schedule', $asset->id), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
        ]);
        $line = AssetDepreciationLine::where('asset_id', $asset->id)->orderBy('period_month')->firstOrFail();
        $data = ['fiscal_year_id' => $line->fiscal_year_id, 'period_month' => $line->period_month];

        $this->actingAs($admin)->post(route('fixed-assets.post-period', $asset->id), $data);
        $this->actingAs($admin)->post(route('fixed-assets.post-period', $asset->id), $data)->assertNotFound();

        $this->assertSame(1, JournalEntry::where('source_id', $asset->id)->where('reference', 'like', 'DEP-%')->count());
    }

    public function test_land_cannot_get_a_depreciation_schedule(): void
    {
        $asset = $this->boughtAsset(['category' => 'land']);

        $this->actingAs($this->s->admin())->post(route('fixed-assets.schedule', $asset->id), [
            'fiscal_year_id' => $this->s->fiscalYear->id,
        ])->assertStatus(422);
    }

    public function test_a_schedule_cannot_use_another_companys_fiscal_year(): void
    {
        $asset = $this->boughtAsset();
        $other = CattleScenario::make();

        $this->actingAs($this->s->admin())->post(route('fixed-assets.schedule', $asset->id), [
            'fiscal_year_id' => $other->fiscalYear->id,
        ])->assertStatus(422);

        $this->assertSame(0, AssetDepreciationLine::where('asset_id', $asset->id)->count());
    }

    // ── disposal ──────────────────────────────────────────────────

    private function dispose(FixedAsset $asset, string $proceeds, string $date = '2026-08-01')
    {
        return $this->actingAs($this->s->admin())->post(route('fixed-assets.dispose', $asset->id), [
            'disposal_date' => $date, 'disposal_proceeds' => $proceeds,
        ]);
    }

    public function test_selling_above_book_value_books_a_gain(): void
    {
        $asset = $this->boughtAsset();

        $this->dispose($asset, '130000')->assertSessionHasNoErrors();

        $this->assertSame('disposed', $asset->fresh()->status);
        $this->assertSame('0.00', $this->s->net($this->assetAcct));            // asset removed
        $this->assertSame('-10000.00', $this->s->net('6800'));                 // gain = credit
        $this->assertSame('10000.00', $this->s->net($this->cash));             // -120000 bought, +130000 sold

        $je = JournalEntry::findOrFail($asset->fresh()->disposal_journal_entry_id);
        $this->assertSame(0, bccomp((string) $je->lines()->sum('debit'), (string) $je->lines()->sum('credit'), 2));
    }

    public function test_selling_below_book_value_books_a_loss(): void
    {
        $asset = $this->boughtAsset();

        $this->dispose($asset, '100000')->assertSessionHasNoErrors();

        $this->assertSame('20000.00', $this->s->net('6800'));                  // loss = debit
        $this->assertSame('-20000.00', $this->s->net($this->cash));
    }

    public function test_an_asset_that_was_depreciated_is_sold_against_its_book_value(): void
    {
        $asset = $this->boughtAsset();
        $admin = $this->s->admin();
        $this->actingAs($admin)->post(route('fixed-assets.schedule', $asset->id), ['fiscal_year_id' => $this->s->fiscalYear->id]);
        $line = AssetDepreciationLine::where('asset_id', $asset->id)->orderBy('period_month')->firstOrFail();
        $this->actingAs($admin)->post(route('fixed-assets.post-period', $asset->id), [
            'fiscal_year_id' => $line->fiscal_year_id, 'period_month' => $line->period_month,
        ]);

        $nbv = bcsub('120000.00', number_format((float) $line->amount, 2, '.', ''), 2);
        $this->dispose($asset->fresh(), $nbv)->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->s->net($this->assetAcct));
        $this->assertSame('0.00', $this->s->net($this->accDepAcct));
        $this->assertSame('0.00', $this->s->net('6800'));    // sold exactly at book value → no gain or loss
    }

    public function test_a_disposed_asset_cannot_be_disposed_again(): void
    {
        $asset = $this->boughtAsset();
        $this->dispose($asset, '120000');

        $this->dispose($asset->fresh(), '120000')->assertStatus(422);
        $this->assertSame(1, JournalEntry::where('reference', 'DISP-' . $asset->asset_code)->count());
    }

    public function test_disposal_needs_an_open_fiscal_year(): void
    {
        $asset = $this->boughtAsset();

        $this->dispose($asset, '120000', '2031-01-01')->assertStatus(422);
        $this->assertSame('active', $asset->fresh()->status);
    }

    // ── security ──────────────────────────────────────────────────

    public function test_a_view_only_user_cannot_buy_or_dispose(): void
    {
        $asset  = $this->boughtAsset();
        $viewer = $this->s->user(['view fixed_assets']);

        $this->actingAs($viewer)->post(route('fixed-assets.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->post(route('fixed-assets.dispose', $asset->id), [
            'disposal_date' => '2026-08-01', 'disposal_proceeds' => 1,
        ])->assertForbidden();
    }

    public function test_another_company_cannot_touch_this_asset(): void
    {
        $asset = $this->boughtAsset();
        $other = CattleScenario::make();
        $admin = $other->admin();

        $this->actingAs($admin)->post(route('fixed-assets.dispose', $asset->id), [
            'disposal_date' => '2026-08-01', 'disposal_proceeds' => 1,
        ])->assertForbidden();
        $this->actingAs($admin)->delete(route('fixed-assets.destroy', $asset->id))->assertForbidden();
        $this->actingAs($admin)->post(route('fixed-assets.schedule', $asset->id), [
            'fiscal_year_id' => $other->fiscalYear->id,
        ])->assertForbidden();

        $this->assertSame('active', $asset->fresh()->status);
    }
}
