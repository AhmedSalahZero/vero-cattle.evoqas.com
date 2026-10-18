<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name_en'    => 'Test Supplier ' . fake()->unique()->numerify('####'),
            'name_ar'    => 'مورد تجريبي ' . fake()->unique()->numerify('####'),
            'type'       => 'animal',
            'is_active'  => true,
        ];
    }
}
