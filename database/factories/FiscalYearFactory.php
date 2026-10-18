<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            // `year` is NOT NULL (and unique per company) — derive it from start_date
            // so a test that only overrides the dates still gets a valid row.
            'year'       => fn (array $attrs) => Carbon::parse($attrs['start_date'])->year,
            'name'       => fn (array $attrs) => 'FY ' . Carbon::parse($attrs['start_date'])->year,
            'status'     => 'open',
        ];
    }
}
