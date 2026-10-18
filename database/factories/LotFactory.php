<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Farm;
use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // When a test passes only company_id, the farm is created under that company.
            'farm_id'    => fn (array $attrs) => Farm::factory()->create([
                'company_id' => $attrs['company_id'],
            ])->id,
            // NOTE: the lots table has a single `name` column (not name_en / name_ar).
            'name'       => 'Lot ' . fake()->unique()->numerify('####'),
            'start_date' => '2025-01-01',
            'status'     => 'active',
        ];
    }
}
