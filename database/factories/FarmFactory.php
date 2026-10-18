<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Farm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Farm>
 */
class FarmFactory extends Factory
{
    protected $model = Farm::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name_en'    => 'Test Farm ' . fake()->unique()->numerify('####'),
            'name_ar'    => 'مزرعة تجريبية ' . fake()->unique()->numerify('####'),
            'country'    => 'EG',
            'is_active'  => true,
        ];
    }
}
