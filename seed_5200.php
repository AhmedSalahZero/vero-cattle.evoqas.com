<?php
use App\Models\Account;
use App\Models\Company;

foreach (Company::all() as $c) {
    if (Account::where('company_id', $c->id)->where('code', '5200')->exists()) {
        echo 'Skip: ' . $c->id . ' - already has 5200' . PHP_EOL;
        continue;
    }
    $parentId = Account::where('company_id', $c->id)->where('code', '5000')->value('id');
    Account::create([
        'company_id'           => $c->id,
        'parent_id'            => $parentId,
        'code'                 => '5200',
        'name_en'              => 'Purchase Price Variance',
        'name_ar'              => 'فروق أسعار الشراء',
        'type'                 => 'cogs',
        'normal_balance'       => 'debit',
        'is_system'            => true,
        'allow_direct_posting' => true,
        'is_active'            => true,
        'notes'                => 'Difference between accrued receipt value and actual supplier invoice. See Methodology #29.',
    ]);
    echo 'Added 5200: ' . $c->id . ' - ' . $c->name_en . PHP_EOL;
}
echo 'Done.' . PHP_EOL;