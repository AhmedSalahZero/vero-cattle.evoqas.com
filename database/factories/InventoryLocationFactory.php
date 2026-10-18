<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLocation>
 */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'farm_id'    => null,
            'name_en'    => 'Test Location ' . fake()->unique()->numerify('####'),
            'name_ar'    => 'موقع تجريبي ' . fake()->unique()->numerify('####'),
            'type'       => 'warehouse',
            'is_active'  => true,
        ];
    }
}
