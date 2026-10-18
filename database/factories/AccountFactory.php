<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'company_id'           => Company::factory(),
            'code'                 => (string) fake()->unique()->numberBetween(7000, 9999),
            'name_en'              => 'Test Account ' . fake()->unique()->numerify('####'),
            'name_ar'              => 'حساب تجريبي',
            'type'                 => 'asset',
            // normal_balance is NOT NULL with no default — follow the account type.
            'normal_balance'       => fn (array $attrs) => in_array($attrs['type'], ['liability', 'equity', 'revenue'], true)
                ? 'credit'
                : 'debit',
            'is_system'            => false,
            'allow_direct_posting' => true,
            'is_active'            => true,
        ];
    }
}
