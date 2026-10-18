<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        // The table has unique indexes on (company, category, name_en|name_ar, is_master)
        // and (company, sku, is_master), so every generated row gets unique names/SKU.
        // Location-stock rows (is_master = false) should pass name_en/name_ar = null
        // if a test wants to mimic production exactly; unique values are harmless.
        $n = fake()->unique()->numerify('######');

        return [
            'company_id'     => Company::factory(),
            'farm_id'        => null,
            'location_id'    => null,
            'master_item_id' => null,
            'is_master'      => true,
            'name_en'        => 'Test Item ' . $n,
            'name_ar'        => 'صنف تجريبي ' . $n,
            'sku'            => 'SKU-' . $n,
            'category'       => 'feed',
            'unit'           => 'kg',
            'unit_cost'      => 0,
            'qty_on_hand'    => 0,
            'is_active'      => true,
        ];
    }
}
