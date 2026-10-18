<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  TenantIsolationTest
//
//  Many companies share one database. A user of Company A must never
//  see, change, confirm or delete anything belonging to Company B —
//  even with full permissions and even by guessing record numbers.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\SalesRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $alpha;
    private CattleScenario $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->alpha = CattleScenario::make();
        $this->beta  = CattleScenario::make();
    }

    public function test_a_user_cannot_confirm_another_companys_sale(): void
    {
        $betaSale   = $this->beta->draftSale([['price' => '10000.00']]);
        $alphaAdmin = $this->alpha->admin();

        $this->actingAs($alphaAdmin)->patch(route('sales.confirm', $betaSale->id), [
            'revenue_account_id' => $this->alpha->account('4100')->id,
        ])->assertForbidden();

        $this->assertSame('draft', $betaSale->fresh()->status);
    }

    public function test_a_user_cannot_cancel_or_delete_another_companys_sale(): void
    {
        $betaSale   = $this->beta->draftSale([['price' => '10000.00']]);
        $alphaAdmin = $this->alpha->admin();

        $this->actingAs($alphaAdmin)->post(route('sales.cancel', $betaSale->id))->assertForbidden();
        $this->actingAs($alphaAdmin)->delete(route('sales.destroy', $betaSale->id))->assertForbidden();

        $this->assertNotNull(SalesRecord::find($betaSale->id));
        $this->assertSame('draft', $betaSale->fresh()->status);
    }

    public function test_the_sales_list_shows_only_own_company_records(): void
    {
        $this->alpha->draftSale([['price' => '10000.00']]);
        $this->beta->draftSale([['price' => '99999.00']]);

        $this->actingAs($this->alpha->admin())
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->where('records.total', 1)
            );
    }

    public function test_revenue_account_of_another_company_is_rejected(): void
    {
        $sale = $this->alpha->draftSale([['price' => '10000.00']]);

        $this->actingAs($this->alpha->admin())->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $this->beta->account('4100')->id,
        ])->assertSessionHasErrors('revenue_account_id');

        $this->assertSame('draft', $sale->fresh()->status);
    }
}
