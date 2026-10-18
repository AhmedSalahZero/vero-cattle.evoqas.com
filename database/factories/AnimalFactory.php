<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Company;
use App\Models\Farm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Animal>
 */
class AnimalFactory extends Factory
{
    protected $model = Animal::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'farm_id'    => fn (array $attrs) => Farm::factory()->create([
                'company_id' => $attrs['company_id'],
            ])->id,
            'lot_id'     => null,
            'tag'        => 'TAG-' . fake()->unique()->numerify('######'),
            'sex'        => 'male',
            'source'     => 'purchased',
            'status'     => 'active',
        ];
    }
}
