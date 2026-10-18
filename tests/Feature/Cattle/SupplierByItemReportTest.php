<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  SupplierByItemReportTest
//  Analytics → "Suppliers by Item" must find receipts that were booked
//  against a location-stock row and group them under the MASTER item.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\InventoryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class SupplierByItemReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_on_stock_rows_are_reported_under_the_master_item(): void
    {
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $s        = CattleScenario::make();
        $supplier = $s->supplier();
        $item     = $s->stockedItem('feed', '0', '0');

        InventoryTransaction::create([
            'company_id' => $s->company->id, 'farm_id' => $s->farm->id,
            'location_id' => $item['location']->id, 'item_id' => $item['stock']->id,
            'type' => 'receipt', 'date' => '2026-03-10', 'qty' => 100, 'unit_cost' => 10, 'total' => 1000,
            'qty_after' => 100, 'wac_after' => 10, 'supplier_id' => $supplier->id,
        ]);

        $this->actingAs($s->admin())
            ->get(route('analytics.top-suppliers-item', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
            ->assertInertia(fn ($page) => $page
                ->where('report.by_item.0.item_id', $item['master']->id)
                ->where('report.by_item.0.suppliers.0.total_value', 1000)
                ->where('report.by_item.0.suppliers.0.supplier_id', $supplier->id));
    }
}
