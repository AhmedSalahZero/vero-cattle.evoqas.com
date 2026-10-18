<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Farm;
use App\Models\ProcurementRecord;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcurementRecord>
 */
class ProcurementRecordFactory extends Factory
{
    protected $model = ProcurementRecord::class;

    public function definition(): array
    {
        return [
            'company_id'  => Company::factory(),
            'farm_id'     => fn (array $attrs) => Farm::factory()->create(['company_id' => $attrs['company_id']])->id,
            'supplier_id' => fn (array $attrs) => Supplier::factory()->create(['company_id' => $attrs['company_id']])->id,
            'po_type'     => 'cattle',
            'date'        => '2024-03-01',
            'status'      => 'draft',
            'po_status'   => 'draft',
        ];
    }
}
