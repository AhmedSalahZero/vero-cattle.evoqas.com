<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  AccessControlTest
//
//  Who may do what, and — just as important — who may NOT.
//  Covers: guests, users without permission, read-only users, and
//  the Super Admin.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class AccessControlTest extends TestCase
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

    public function test_guests_are_sent_to_login_from_every_main_page(): void
    {
        foreach (['sales.index', 'animals.index', 'farms.index', 'lots.index', 'procurement.index'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $nobody = $this->s->user([]);

        $this->actingAs($nobody)->get(route('sales.index'))->assertForbidden();
    }

    public function test_view_only_user_cannot_confirm_cancel_or_delete_a_sale(): void
    {
        $sale   = $this->s->draftSale([['price' => '10000.00']]);
        $viewer = $this->s->user(['view sales']);

        $this->actingAs($viewer)->patch(route('sales.confirm', $sale->id), [
            'revenue_account_id' => $this->s->account('4100')->id,
        ])->assertForbidden();

        $this->actingAs($viewer)->post(route('sales.cancel', $sale->id))->assertForbidden();
        $this->actingAs($viewer)->delete(route('sales.destroy', $sale->id))->assertForbidden();

        $this->assertSame('draft', $sale->fresh()->status);
    }

    public function test_user_who_can_edit_but_not_delete_cannot_delete(): void
    {
        $sale   = $this->s->draftSale([['price' => '10000.00']]);
        $editor = $this->s->user(['view sales', 'edit sales']);

        $this->actingAs($editor)->delete(route('sales.destroy', $sale->id))->assertForbidden();

        $this->assertNotNull(\App\Models\SalesRecord::find($sale->id));
    }

    public function test_the_dashboard_is_open_to_any_signed_in_user(): void
    {
        $nobody = $this->s->user([]);

        $this->actingAs($nobody)->get(route('dashboard'))->assertOk();
    }

    public function test_super_admin_can_open_pages_without_individual_permissions(): void
    {
        $admin = $this->s->superAdmin();

        $this->actingAs($admin)
            ->withSession(['selected_company_id' => $this->s->company->id])
            ->get(route('sales.index'))
            ->assertOk();
    }
}
