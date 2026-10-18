<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  php artisan demo:seed [--fresh]
//
//  Builds a complete demo company with a full month-by-month history
//  from 1 Jan 2025 to 30 Sep 2026.
//
//  Every transaction goes in through the app's own routes (see
//  App\Support\Demo\DemoHttp), so journal entries, stock, invoices
//  and costs are produced by the real code — not hand-made rows.
//  The "current date" is moved day by day, so every record carries
//  its true historical date.
//
//  Story of the business: a cattle fattening farm.
//    • shareholders put in capital (topped up automatically when cash runs short)
//    • sheds, tractor, mixer wagon … bought and depreciated monthly
//    • every 2 months a cohort of ~25 young cattle is bought from a trader
//    • feed, hay, medicine and vaccines are bought monthly / quarterly
//    • supplier invoices are matched to the goods received, paid 30 days later
//    • feed, treatments, vaccinations and weighings are recorded for every lot
//    • labour and farm overheads are accrued every month-end and settled next month
//    • each cohort is sold in two tranches (≈7 and ≈8 months after purchase)
//    • customers pay 4–5 weeks later (the newest sales are still unpaid at the end)
//
//  📌 METHODOLOGY REFERENCE: DemoCompanyHistory
//  Formulas : weight(d) = w0 + ADG × days ; feed kg = head × days × (2% of average weight) ;
//             sale amount = weight × price per kg
//  Edge cases: any step the app refuses is reported and skipped, never hidden.
//
//  @layer Console (demo only)
// ══════════════════════════════════════════════════════════════════

use App\Models\Account;
use App\Models\Animal;
use App\Models\AnimalBreed;
use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\AssetDepreciationLine;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\FarmOverheadType;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\InventoryItemTemplate;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\JournalLine;
use App\Models\Lot;
use App\Models\ProcurementItemLine;
use App\Models\ProcurementRecord;
use App\Models\SalesRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo\DemoHttp;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SeedDemoCompany extends Command
{
    protected $signature = 'demo:seed {--fresh : Delete the existing demo company first and build it again}';

    protected $description = 'Build a demo company with a full month-by-month history (Jan 2025 – Sep 2026)';

    private const COMPANY_EN = 'Delta Premium Cattle (DEMO)';
    private const COMPANY_AR = 'دلتا للماشية الممتازة (تجريبي)';
    private const EMAIL      = 'demo@vero-demo.test';
    private const PASSWORD   = 'Demo@1234';

    private const START = '2025-01-01';
    private const END   = '2026-09-30';

    private DemoHttp $http;
    private Company $company;
    private Farm $farm;
    private User $admin;
    private Account $bank;
    private Account $equity;

    /** @var array<string, Account> */
    private array $acct = [];
    /** @var array<string, Supplier> */
    private array $sup = [];
    /** @var array<int, Customer> */
    private array $customers = [];
    /** @var array<string, FiscalYear> */
    private array $fy = [];
    /** @var array<string, InventoryItem> */
    private array $item = [];
    /** @var array<string, InventoryLocation> */
    private array $loc = [];
    /** @var array<int, FarmOverheadType> */
    private array $overheadTypes = [];
    /** @var array<int, array> */
    private array $cohorts = [];
    /** @var array<int, FixedAsset> */
    private array $assets = [];
    /** @var array<string, array<int, callable>> */
    private array $scheduled = [];
    /** @var array<int, array> */
    private array $failures = [];
    private int $calls = 0;
    private int $topUps = 0;
    private int $idx = 0;   // months since Jan 2025

    // ═════════════════════════════════════════════════════════════
    public function handle(): int
    {
        mt_srand(20250101);
        config(['session.driver' => 'array', 'app.debug' => true]);
        ini_set('memory_limit', '1G');
        set_time_limit(0);

        if (! $this->prepareCompany()) {
            return self::FAILURE;
        }

        $this->http = new DemoHttp($this->laravel);
        $this->http->actingAs($this->admin);

        $this->buildPlan();

        $this->info('Building history… (this takes a few minutes)');
        $day = Carbon::parse(self::START);
        $end = Carbon::parse(self::END);
        $lastPrinted = '';

        while ($day->lte($end)) {
            Carbon::setTestNow($day->copy()->setTime(10, 0));
            $this->idx = ($day->year - 2025) * 12 + $day->month - 1;

            foreach ($this->scheduled[$day->toDateString()] ?? [] as $job) {
                $job();
            }
            $this->runDay($day->copy());

            if ($day->format('Y-m') !== $lastPrinted) {
                $lastPrinted = $day->format('Y-m');
                $this->line('  ' . $lastPrinted . '  done   (' . count($this->failures) . ' problems so far)');
            }
            if (count($this->failures) > 120) {
                $this->error('Too many problems — stopping so they can be fixed first.');
                break;
            }
            $day->addDay();
        }

        Carbon::setTestNow();
        $this->report();

        return count($this->failures) === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ═════════════════════════════════════════════════════════════
    //  1. THE COMPANY AND ITS SET-UP DATA
    // ═════════════════════════════════════════════════════════════

    private function prepareCompany(): bool
    {
        if (Permission::count() === 0) {
            $this->callSilent('db:seed', ['--class' => \Database\Seeders\RolesAndPermissionsSeeder::class, '--force' => true]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $existing = Company::where('name_en', self::COMPANY_EN)->get();
        if ($existing->isNotEmpty()) {
            if (! $this->option('fresh')) {
                $this->error('The demo company already exists. Run again with --fresh to delete it and rebuild:');
                $this->line('    php artisan demo:seed --fresh');
                return false;
            }
            $this->warn('Deleting the old demo company…');
            foreach ($existing as $c) {
                $this->purgeCompany((int) $c->id);
            }
        }
        DB::table('users')->where('email', self::EMAIL)->delete();

        Carbon::setTestNow(Carbon::parse(self::START)->setTime(9, 0));

        $this->company = Company::create([
            'name_en'                => self::COMPANY_EN,
            'name_ar'                => self::COMPANY_AR,
            'country'                => 'EG',
            'currency'               => 'EGP',
            'accounting_mode'        => 'standalone',
            'is_active'              => true,
            'subscription_starts_at' => '2025-01-01',
            'subscription_ends_at'   => '2030-12-31',
        ]);
        (new \Database\Seeders\ChartOfAccountsSeeder())->seedForCompany($this->company->id);

        $this->admin = User::unguarded(fn () => User::create([
            'name'              => 'Demo Admin',
            'email'             => self::EMAIL,
            'password'          => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'company_id'        => $this->company->id,
            'is_active'         => true,
            'is_super_admin'    => false,
        ]));
        $this->admin->givePermissionTo(Permission::pluck('name')->all());
        try {
            $this->admin->assignRole('Company Admin');
        } catch (\Throwable $e) {
            // role names are only display labels — not needed for access
        }

        $this->farm = Farm::create([
            'company_id' => $this->company->id,
            'name_en'    => 'Delta Fattening Farm',
            'name_ar'    => 'مزرعة دلتا للتسمين',
            'country'    => 'EG',
            'is_active'  => true,
        ]);

        foreach ([2025, 2026] as $y) {
            $this->fy[(string) $y] = FiscalYear::create([
                'company_id' => $this->company->id,
                'year'       => $y,
                'name'       => 'FY ' . $y,
                'start_date' => "$y-01-01",
                'end_date'   => "$y-12-31",
                'status'     => 'open',
            ]);
        }

        // Cash safe, bank account and the equity account used for capital
        $cashGroup = $this->account('1400');
        $bankGroup = $this->account('1500');
        $this->bank = $this->makeAccount('1501', 'CIB Current Account (EGP)', 'حساب جاري - البنك التجاري الدولي', 'asset', $bankGroup->id);
        $this->makeAccount('1401', 'Farm Cash Safe', 'خزينة المزرعة', 'asset', $cashGroup->id);
        $this->equity = $this->account('3100');

        // Settings lists (breeds + item templates)
        foreach ([['Holstein', 'هولشتاين'], ['Baladi', 'بلدي'], ['Brown Swiss', 'سويسري بني'], ['Simmental', 'سيمنتال']] as [$en, $ar]) {
            AnimalBreed::create(['company_id' => $this->company->id, 'name_en' => $en, 'name_ar' => $ar, 'is_active' => true]);
        }

        // Suppliers
        $this->sup['cattle1'] = $this->makeSupplier('Delta Cattle Traders', 'تجار دلتا للماشية', 'animal');
        $this->sup['cattle2'] = $this->makeSupplier('Nile Livestock Market Co.', 'شركة سوق النيل للماشية', 'animal');
        $this->sup['feed']    = $this->makeSupplier('Al-Wadi Feed Mill', 'مطحنة الوادي للأعلاف', 'feed');
        $this->sup['pharma']  = $this->makeSupplier('VetCare Pharma', 'فيت كير للأدوية البيطرية', 'medicine');
        $this->sup['vet']     = $this->makeSupplier('Dr. Hassan Veterinary Clinic', 'عيادة د. حسن البيطرية', 'other');
        $this->sup['move']    = $this->makeSupplier('Fast Move Livestock Transport', 'النقل السريع للماشية', 'other');
        $this->sup['media']   = $this->makeSupplier('AgriMedia Marketing', 'أجري ميديا للتسويق', 'other');

        // Customers
        foreach ([
            ['Cairo Prime Meat Co.', 'شركة القاهرة للحوم الممتازة'],
            ['Alexandria Slaughterhouse', 'مجزر الإسكندرية'],
            ['Giza Butchers Union', 'اتحاد جزاري الجيزة'],
            ['Delta Hypermarkets Group', 'مجموعة دلتا هايبر ماركت'],
        ] as [$en, $ar]) {
            $this->customers[] = Customer::create([
                'company_id'         => $this->company->id,
                'name_en'            => $en,
                'name_ar'            => $ar,
                'payment_terms_days' => 30,
                'is_active'          => true,
            ]);
        }

        // Stores and stock items
        $this->loc['feed']   = $this->makeLocation('Feed Store', 'مخزن الأعلاف');
        $this->loc['pharma'] = $this->makeLocation('Farm Pharmacy', 'صيدلية المزرعة');

        $this->item['conc'] = $this->makeItem('Concentrate Feed Mix', 'علف مركز', 'feed', 'kg', 'FEED-001', 'feed');
        $this->item['hay']  = $this->makeItem('Alfalfa Hay', 'دريس برسيم حجازي', 'feed', 'kg', 'FEED-002', 'feed');
        // Link each feed to the feed catalogue so dry matter (and so FCR) can be worked out
        foreach ([['conc', 'concentrate', 90], ['hay', 'roughage', 88]] as [$k, $type, $dm]) {
            $fi = \App\Models\FeedItem::create([
                'company_id' => $this->company->id,
                'name_en'    => $this->item[$k]->name_en,
                'name_ar'    => $this->item[$k]->name_ar,
                'type'       => $type,
                'unit'       => 'kg',
                'dm_pct'     => $dm,
                'is_active'  => true,
            ]);
            $this->item[$k]->update(['feed_item_id' => $fi->id]);
        }

        $this->item['oxy']  = $this->makeItem('Oxytetracycline 20% Injection', 'أوكسي تتراسيكلين 20% حقن', 'medicine', 'ml', 'MED-001', 'pharma');
        $this->item['iver'] = $this->makeItem('Ivermectin 1% Injection', 'ايفرمكتين 1% حقن', 'medicine', 'ml', 'MED-002', 'pharma');
        $this->item['fmd']  = $this->makeItem('FMD Vaccine', 'مصل الحمى القلاعية', 'vaccine', 'ml', 'VAC-001', 'pharma');
        $this->item['lsd']  = $this->makeItem('Lumpy Skin Disease Vaccine', 'مصل الجلد العقدي', 'vaccine', 'ml', 'VAC-002', 'pharma');

        // Farm labour / overhead types for the monthly cost allocation
        foreach ([['Electricity', 'كهرباء', '2271', '6321'], ['Water', 'مياه', '2272', '6322'], ['Fuel', 'وقود', '2273', '6323']] as [$en, $ar, $acc, $var]) {
            $this->overheadTypes[] = FarmOverheadType::create([
                'company_id'          => $this->company->id,
                'name_en'             => $en,
                'name_ar'             => $ar,
                'accrued_account_id'  => $this->account($acc)->id,
                'variance_account_id' => $this->account($var)->id,
                'is_active'           => true,
            ]);
        }

        return true;
    }

    /**
     * Remove one company and everything that belongs to it.
     * Pauses the database's foreign-key checks (so users and the records that
     * point to them can go in any order), deletes every row carrying this
     * company_id, then sweeps away child rows left without a parent.
     */
    private function purgeCompany(int $companyId): void
    {
        $db = DB::getDatabaseName();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $tables = DB::select(
                "SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'company_id'",
                [$db]
            );
            foreach ($tables as $row) {
                DB::table($row->t)->where('company_id', $companyId)->delete();
            }
            DB::table('companies')->where('id', $companyId)->delete();

            $fks = DB::select(
                "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
                [$db]
            );
            for ($pass = 0; $pass < 12; $pass++) {
                $removed = 0;
                foreach ($fks as $fk) {
                    if ($fk->t === $fk->rt) {
                        continue;   // a table pointing at itself (e.g. account parents)
                    }
                    try {
                        $removed += DB::table($fk->t)
                            ->whereNotNull($fk->c)
                            ->whereNotIn($fk->c, function ($q) use ($fk) {
                                $q->select($fk->rc)->from($fk->rt);
                            })
                            ->delete();
                    } catch (\Throwable $e) {
                        // a table that cannot be swept this way is simply skipped
                    }
                }
                if ($removed === 0) {
                    break;
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function account(string $code): Account
    {
        return $this->acct[$code] ??= Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function makeAccount(string $code, string $en, string $ar, string $type, int $parentId): Account
    {
        return $this->acct[$code] = Account::create([
            'company_id'           => $this->company->id,
            'parent_id'            => $parentId,
            'code'                 => $code,
            'name_en'              => $en,
            'name_ar'              => $ar,
            'type'                 => $type,
            'normal_balance'       => in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit',
            'is_system'            => false,
            'allow_direct_posting' => true,
            'is_active'            => true,
            'currency'             => 'EGP',
        ]);
    }

    private function makeSupplier(string $en, string $ar, string $type): Supplier
    {
        return Supplier::create([
            'company_id' => $this->company->id,
            'name_en'    => $en,
            'name_ar'    => $ar,
            'type'       => $type,
            'is_active'  => true,
        ]);
    }

    private function makeLocation(string $en, string $ar): InventoryLocation
    {
        return InventoryLocation::create([
            'company_id' => $this->company->id,
            'farm_id'    => $this->farm->id,
            'name_en'    => $en,
            'name_ar'    => $ar,
            'type'       => 'warehouse',
            'is_active'  => true,
        ]);
    }

    /** A master item plus its (empty) stock row at the chosen store. */
    private function makeItem(string $en, string $ar, string $category, string $unit, string $sku, string $locKey): InventoryItem
    {
        InventoryItemTemplate::create([
            'company_id' => $this->company->id,
            'name_en'    => $en,
            'name_ar'    => $ar,
            'category'   => $category,
            'is_active'  => true,
        ]);

        $master = InventoryItem::create([
            'company_id'  => $this->company->id,
            'is_master'   => true,
            'name_en'     => $en,
            'name_ar'     => $ar,
            'sku'         => $sku,
            'category'    => $category,
            'unit'        => $unit,
            'unit_cost'   => 0,
            'qty_on_hand' => 0,
            'is_active'   => true,
        ]);

        InventoryItem::create([
            'company_id'     => $this->company->id,
            'farm_id'        => $this->farm->id,
            'location_id'    => $this->loc[$locKey]->id,
            'master_item_id' => $master->id,
            'is_master'      => false,
            'category'       => $category,
            'unit'           => $unit,
            'unit_cost'      => 0,
            'qty_on_hand'    => 0,
            'is_active'      => true,
        ]);

        return $master;
    }

    // ═════════════════════════════════════════════════════════════
    //  2. THE PLAN (which cohorts, when bought, when sold)
    // ═════════════════════════════════════════════════════════════

    private function buildPlan(): void
    {
        $breeds = ['Holstein', 'Baladi', 'Brown Swiss', 'Simmental'];
        $months = ['2025-01', '2025-03', '2025-05', '2025-07', '2025-09', '2025-11',
                   '2026-01', '2026-03', '2026-05', '2026-07', '2026-09'];

        foreach ($months as $i => $ym) {
            $buy   = Carbon::parse($ym . '-08');
            $head  = [25, 24, 26, 25, 23, 27][$i % 6];
            $n1    = (int) round($head * 0.6);

            $this->cohorts[$i] = [
                'no'       => $i + 1,
                'ym'       => $ym,
                'head'     => $head,
                'buy'      => $buy,
                'breed'    => $breeds[$i % 4],
                'supplier' => $i % 2 === 0 ? 'cattle1' : 'cattle2',
                'adg'      => 0.98 + (mt_rand(0, 14) / 100),         // kg per day for the lot
                't1'       => $buy->copy()->addMonthsNoOverflow(7)->day(24),
                't2'       => $buy->copy()->addMonthsNoOverflow(8)->day(24),
                'n1'       => $n1,
                'lot'      => null,
                'animals'  => [],      // animal_id => [w0, adg]
                'sold1'    => false,
                'sold2'    => false,
            ];
        }
    }

    /** Planned number of head in a cohort on a given day. */
    private function headAt(array $c, Carbon $d): int
    {
        if ($d->lt($c['buy'])) {
            return 0;
        }
        if ($d->lt($c['t1'])) {
            return $c['head'];
        }
        if ($d->lt($c['t2'])) {
            return $c['head'] - $c['n1'];
        }
        return 0;
    }

    private function weightOf(array $a, array $c, Carbon $d): float
    {
        $days = max(0, $c['buy']->diffInDays($d));
        return round($a['w0'] + $a['adg'] * $days, 1);
    }

    // ═════════════════════════════════════════════════════════════
    //  3. WHAT HAPPENS ON EACH DAY
    // ═════════════════════════════════════════════════════════════

    private function runDay(Carbon $d): void
    {
        $dom = $d->day;
        $ymd = $d->toDateString();

        // Opening capital and the new year's depreciation schedules
        if ($ymd === '2025-01-02') {
            $this->injectCapital(12000000, 'Opening capital from shareholders');
        }
        if ($dom === 2 && $d->month === 1) {
            $this->scheduleDepreciation($d->year);
        }

        // Fixed assets
        if ($dom === 6) {
            foreach ($this->assetPlan() as $p) {
                if ($p['date'] === $ymd) {
                    $this->buyAsset($p);
                }
            }
        }

        if ($dom === 3) {
            $this->monthlyPurchases($d);
        }
        if ($dom === 5) {
            $this->invoiceInventory($d);
            $this->settleLastMonthAllocation($d);
        }
        if ($dom === 8) {
            $this->buyCohort($d);
        }
        if ($dom === 12) {
            $this->vaccinate($d);
        }
        if ($dom === 15) {
            $this->otherExpenses($d);
        }
        if ($dom === 16) {
            $this->treat($d);
        }
        if ($dom === 22) {
            $this->feedAll($d);
        }
        if ($dom === 23) {
            $this->weighAll($d);
        }
        if ($dom === 24) {
            $this->sellDue($d);
        }
        if ($d->isSameDay($d->copy()->endOfMonth())) {
            $this->monthEnd($d);
        }
    }

    // ── money helpers ─────────────────────────────────────────────

    private function m($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function schedule(Carbon $date, callable $job): void
    {
        $this->scheduled[$date->toDateString()][] = $job;
    }

    /** Send a request; record a problem if the app refuses it. */
    private function api(string $label, string $method, string $route, array $params, array $data = []): array
    {
        $this->calls++;
        $res = $this->http->send($method, route($route, $params, false), $data);

        if (! $res['ok']) {
            $this->problem($label, $res['error']);
        } elseif (! empty($res['warning'])) {
            $this->problem($label, 'accounting warning: ' . $res['warning']);
            $res['ok'] = false;
        }

        return $res;
    }

    private function problem(string $label, ?string $why): void
    {
        $this->failures[] = ['date' => Carbon::now()->toDateString(), 'label' => $label, 'why' => (string) $why];
        $this->line('    <fg=red>✗</> [' . Carbon::now()->toDateString() . '] ' . $label . ' — ' . mb_substr((string) $why, 0, 160));
    }

    private function bankBalance(): string
    {
        $row = JournalLine::where('account_id', $this->bank->id)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
        return bcsub((string) $row->d, (string) $row->c, 2);
    }

    /** Make sure the bank can cover a payment; if not, shareholders top up. */
    private function ensureFunds(string $amount): void
    {
        $balance = $this->bankBalance();
        if (bccomp($balance, bcadd($amount, '5000', 2), 2) >= 0) {
            return;
        }
        $short = (float) bcsub(bcadd($amount, '5000', 2), $balance, 2);
        $this->injectCapital((int) (ceil(($short + 250000) / 250000) * 250000), 'Capital top-up');
        $this->topUps++;
    }

    private function injectCapital(int $amount, string $note): void
    {
        $this->api('Capital injection', 'POST', 'payments.store', [], [
            'payment_category'   => 'shareholder_collection',
            'sub_type'           => 'capital_injection',
            'date'               => Carbon::now()->toDateString(),
            'method'             => 'bank_transfer',
            'bank_name'          => 'CIB',
            'amount'             => $amount,
            'bank_account_id'    => $this->bank->id,
            'expense_account_id' => $this->equity->id,
            'reference'          => 'CAP-' . Carbon::now()->format('Ymd'),
            'notes'              => $note,
        ]);
    }

    private function paySupplier(int $invoiceId, string $note): void
    {
        $inv = ApInvoice::find($invoiceId);
        if (! $inv || bccomp((string) $inv->balance, '0', 2) <= 0 || $inv->status === 'cancelled') {
            return;
        }
        $amount = $this->m($inv->balance);
        $this->ensureFunds($amount);

        $this->api('Pay supplier invoice #' . $invoiceId, 'POST', 'payments.store', [], [
            'payment_category' => 'supplier_payment',
            'date'             => Carbon::now()->toDateString(),
            'method'           => 'bank_transfer',
            'bank_name'        => 'CIB',
            'amount'           => $amount,
            'bank_account_id'  => $this->bank->id,
            'allocations'      => [['invoice_id' => $invoiceId, 'allocated_amount' => $amount]],
            'reference'        => 'TRF-' . Carbon::now()->format('ymd') . '-' . $invoiceId,
            'notes'            => $note,
        ]);
    }

    private function collectFromCustomer(int $invoiceId, ?string $only = null): void
    {
        $inv = ArInvoice::find($invoiceId);
        if (! $inv || bccomp((string) $inv->balance, '0', 2) <= 0 || $inv->status === 'cancelled') {
            return;
        }
        $amount = $only ?? $this->m($inv->balance);

        $this->api('Collect from customer, invoice #' . $invoiceId, 'POST', 'payments.store', [], [
            'payment_category' => 'customer_collection',
            'date'             => Carbon::now()->toDateString(),
            'method'           => 'bank_transfer',
            'bank_name'        => 'CIB',
            'amount'           => $amount,
            'bank_account_id'  => $this->bank->id,
            'allocations'      => [['invoice_id' => $invoiceId, 'allocated_amount' => $amount]],
            'reference'        => 'RCV-' . Carbon::now()->format('ymd') . '-' . $invoiceId,
        ]);
    }

    // ═════════════════════════════════════════════════════════════
    //  4. FIXED ASSETS
    // ═════════════════════════════════════════════════════════════

    private function assetPlan(): array
    {
        return [
            ['date' => '2025-01-06', 'code' => 'FA-001', 'en' => 'Livestock Sheds (Pens)', 'ar' => 'عنابر المواشي', 'cat' => 'building', 'cost' => 3600000, 'life' => 240, 'asset' => '1120'],
            ['date' => '2025-01-06', 'code' => 'FA-002', 'en' => 'Tractor with Loader', 'ar' => 'جرار مع لودر', 'cat' => 'vehicle', 'cost' => 1350000, 'life' => 96, 'asset' => '1130'],
            ['date' => '2025-01-06', 'code' => 'FA-003', 'en' => 'Feed Mixer Wagon', 'ar' => 'عربة خلط الأعلاف', 'cat' => 'equipment', 'cost' => 520000, 'life' => 60, 'asset' => '1130'],
            ['date' => '2025-06-06', 'code' => 'FA-004', 'en' => 'Electronic Cattle Scale', 'ar' => 'ميزان إلكتروني للماشية', 'cat' => 'equipment', 'cost' => 95000, 'life' => 60, 'asset' => '1130'],
            ['date' => '2026-03-06', 'code' => 'FA-005', 'en' => 'Water Tank & Pumping Station', 'ar' => 'خزان مياه ومحطة ضخ', 'cat' => 'equipment', 'cost' => 380000, 'life' => 72, 'asset' => '1130'],
        ];
    }

    private function buyAsset(array $p): void
    {
        $this->ensureFunds((string) $p['cost']);
        $res = $this->api('Buy asset ' . $p['en'], 'POST', 'fixed-assets.store', [], [
            'farm_id'                => $this->farm->id,
            'asset_code'             => $p['code'],
            'name_en'                => $p['en'],
            'name_ar'                => $p['ar'],
            'category'               => $p['cat'],
            'purchase_date'          => $p['date'],
            'purchase_cost'          => $p['cost'],
            'salvage_value'          => 0,
            'depreciation_method'    => 'straight_line',
            'useful_life_months'     => $p['life'],
            'asset_account_id'       => $this->account($p['asset'])->id,
            'acc_dep_account_id'     => $this->account('1140')->id,
            'dep_expense_account_id' => $this->account('6400')->id,
            'payment_account_id'     => $this->bank->id,
        ]);
        if (! $res['ok']) {
            return;
        }

        $asset = FixedAsset::where('company_id', $this->company->id)->where('asset_code', $p['code'])->first();
        if ($asset) {
            $this->assets[] = $asset;
            $this->api('Depreciation schedule ' . $p['code'], 'POST', 'fixed-assets.schedule', [$asset->id], [
                'fiscal_year_id' => $this->fy[(string) Carbon::now()->year]->id,
            ]);
        }
    }

    /** At the start of a new year, schedules for assets already owned. */
    private function scheduleDepreciation(int $year): void
    {
        if ($year === 2025) {
            return;   // assets are scheduled when bought
        }
        foreach ($this->assets as $asset) {
            $this->api('Depreciation schedule ' . $asset->asset_code . ' ' . $year, 'POST', 'fixed-assets.schedule', [$asset->id], [
                'fiscal_year_id' => $this->fy[(string) $year]->id,
            ]);
        }
    }

    private function postDepreciation(Carbon $d): void
    {
        $fy = $this->fy[(string) $d->year];
        foreach ($this->assets as $asset) {
            $due = AssetDepreciationLine::where('asset_id', $asset->id)
                ->where('fiscal_year_id', $fy->id)
                ->where('period_month', $d->month)
                ->where('is_posted', false)
                ->exists();
            if ($due) {
                $this->api('Post depreciation ' . $asset->asset_code . ' ' . $d->format('Y-m'), 'POST', 'fixed-assets.post-period', [$asset->id], [
                    'fiscal_year_id' => $fy->id,
                    'period_month'   => $d->month,
                ]);
            }
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  5. BUYING: feed, medicine, vaccines, cattle
    // ═════════════════════════════════════════════════════════════

    private function feedPrice(string $key): float
    {
        return $key === 'conc'
            ? round(11.0 * pow(1.008, $this->idx), 2)
            : round(4.2 * pow(1.006, $this->idx), 2);
    }

    /** kg of concentrate per head per day for a given average body weight. */
    private function concRate(float $avgWeight): float
    {
        return 0.020 * $avgWeight;   // 2% of body weight
    }

    private const HAY_PER_HEAD_DAY = 3.2;

    /** Planned head-days and the feed they need in a calendar month. */
    private function monthNeed(Carbon $monthStart): array
    {
        $conc = 0.0;
        $hay  = 0.0;
        $days = $monthStart->daysInMonth;
        foreach ($this->cohorts as $c) {
            for ($i = 0; $i < $days; $i++) {
                $day  = $monthStart->copy()->addDays($i);
                $head = $this->headAt($c, $day);
                if ($head > 0) {
                    $avgW  = $c['w0avg'] ?? 300;
                    $avgW += $c['adg'] * max(0, $c['buy']->diffInDays($day));
                    $conc += $head * $this->concRate($avgW);
                    $hay  += $head * self::HAY_PER_HEAD_DAY;
                }
            }
        }
        return ['conc' => $conc, 'hay' => $hay];
    }

    private array $pendingInvoices = [];

    private function monthlyPurchases(Carbon $d): void
    {
        $need = $this->monthNeed($d->copy()->startOfMonth());

        $lines = [];
        $concQty = ceil($need['conc'] * 1.05 / 100) * 100;
        $hayQty  = ceil($need['hay'] * 1.05 / 100) * 100;
        if ($concQty > 0) {
            $lines[] = ['item' => $this->item['conc'], 'loc' => $this->loc['feed'], 'qty' => $concQty, 'price' => $this->feedPrice('conc')];
        }
        if ($hayQty > 0) {
            $lines[] = ['item' => $this->item['hay'], 'loc' => $this->loc['feed'], 'qty' => $hayQty, 'price' => $this->feedPrice('hay')];
        }
        if ($lines) {
            $this->buyInventory('feed', $this->sup['feed'], $lines, $d);
        }

        // Medicines every quarter
        if (in_array($d->month, [1, 4, 7, 10], true)) {
            $this->buyInventory('medicine', $this->sup['pharma'], [
                ['item' => $this->item['oxy'], 'loc' => $this->loc['pharma'], 'qty' => 800, 'price' => round(1.8 * pow(1.005, $this->idx), 2)],
                ['item' => $this->item['iver'], 'loc' => $this->loc['pharma'], 'qty' => 400, 'price' => round(2.6 * pow(1.005, $this->idx), 2)],
            ], $d);
        }

        // Vaccines for any cohort bought this month (initial shots + the FMD booster)
        foreach ($this->cohorts as $c) {
            if ($c['buy']->format('Y-m') === $d->format('Y-m')) {
                $this->buyInventory('vaccine', $this->sup['pharma'], [
                    ['item' => $this->item['fmd'], 'loc' => $this->loc['pharma'], 'qty' => ceil($c['head'] * 2 * 2 * 1.1), 'price' => round(14.0 * pow(1.005, $this->idx), 2)],
                    ['item' => $this->item['lsd'], 'loc' => $this->loc['pharma'], 'qty' => ceil($c['head'] * 1 * 1.1), 'price' => round(6.0 * pow(1.005, $this->idx), 2)],
                ], $d);
            }
        }
    }

    /**
     * Inventory purchase order → confirm → receive. The supplier's invoice is
     * entered two days later (see invoiceInventory()).
     */
    private function buyInventory(string $kind, Supplier $supplier, array $lines, Carbon $date): void
    {
        $label = ucfirst($kind) . ' purchase';

        $res = $this->api($label . ' order', 'POST', 'procurement.store-inventory-po', [], [
            'farm_id'            => $this->farm->id,
            'supplier_id'        => $supplier->id,
            'date'               => $date->toDateString(),
            'payment_terms_days' => 30,
            'item_lines'         => array_map(fn ($l) => [
                'inventory_item_id'   => $l['item']->id,
                'location_id'         => $l['loc']->id,
                'qty_ordered'         => $l['qty'],
                'unit_cost_estimated' => $l['price'],
            ], $lines),
        ]);
        if (! $res['ok']) {
            return;
        }

        $po = ProcurementRecord::where('company_id', $this->company->id)->where('po_type', 'inventory')->latest('id')->first();
        if (! $po) {
            $this->problem($label, 'order not found after saving');
            return;
        }
        if (! $this->api($label . ' confirm', 'PATCH', 'procurement.confirm-inventory-po', [$po->id])['ok']) {
            return;
        }

        $poLines   = ProcurementItemLine::where('procurement_id', $po->id)->orderBy('id')->get();
        $receipt   = [];
        foreach ($poLines as $i => $pl) {
            $receipt[] = [
                'procurement_item_line_id' => $pl->id,
                'qty_received'             => $lines[$i]['qty'],
                'unit_cost_actual'         => $lines[$i]['price'],
                'location_id'              => $lines[$i]['loc']->id,
            ];
        }

        $before = (int) InventoryTransaction::max('id');
        $res = $this->api($label . ' receipt', 'POST', 'procurement.confirm-inventory-receipt', [$po->id], [
            'batch_date' => $date->toDateString(),
            'lines'      => $receipt,
        ]);
        if (! $res['ok']) {
            return;
        }

        $ids = InventoryTransaction::where('company_id', $this->company->id)
            ->where('type', 'receipt')->where('supplier_id', $supplier->id)
            ->whereNull('ap_invoice_id')->where('id', '>', $before)->pluck('id')->all();

        $this->pendingInvoices[] = ['supplier' => $supplier, 'ids' => $ids, 'kind' => $kind];
    }

    /** Supplier invoices for the goods received on the 3rd: entered and matched on the 5th. */
    private function invoiceInventory(Carbon $d): void
    {
        foreach ($this->pendingInvoices as $p) {
            if (! $p['ids']) {
                continue;
            }
            $total = $this->m(InventoryTransaction::whereIn('id', $p['ids'])->sum('total'));
            $this->enterAndMatchInvoice('inventory', $p['supplier'], $p['ids'], $total, $d, 'INV-' . strtoupper(substr($p['kind'], 0, 3)) . '-' . $d->format('ym'));
        }
        $this->pendingInvoices = [];
    }

    private function enterAndMatchInvoice(string $type, Supplier $supplier, array $txIds, string $total, Carbon $d, string $ref): void
    {
        $res = $this->api('Supplier invoice ' . $ref, 'POST', 'ap-invoices.store', [], [
            'supplier_id'  => $supplier->id,
            'invoice_type' => $type,
            'date'         => $d->toDateString(),
            'due_date'     => $d->copy()->addDays(30)->toDateString(),
            'total'        => $total,
            'reference'    => $ref,
        ]);
        if (! $res['ok']) {
            return;
        }
        $inv = ApInvoice::where('company_id', $this->company->id)->latest('id')->first();

        if (! $this->api('Match receipts to ' . $ref, 'PATCH', 'ap-invoices.match-accruals', [$inv->id], ['transaction_ids' => $txIds])['ok']) {
            return;
        }

        $invId = $inv->id;
        $this->schedule($d->copy()->addDays(28), fn () => $this->paySupplier($invId, 'Settlement of ' . $ref));
    }

    // ── cattle ────────────────────────────────────────────────────

    private function buyCohort(Carbon $d): void
    {
        foreach ($this->cohorts as $i => &$c) {
            if ($c['buy']->toDateString() !== $d->toDateString()) {
                continue;
            }
            $this->purchaseCohort($c, $d);
        }
        unset($c);
    }

    private function purchaseCohort(array &$c, Carbon $d): void
    {
        $supplier = $this->sup[$c['supplier']];
        $price    = round(98.0 * pow(1.008, $this->idx), 2);
        $est      = 305;

        $c['lot'] = Lot::create([
            'company_id' => $this->company->id,
            'farm_id'    => $this->farm->id,
            'name'       => sprintf('Lot %02d – %s cohort', $c['no'], $d->format('M Y')),
            'start_date' => $d->toDateString(),
            'status'     => 'active',
        ]);

        $res = $this->api('Cattle order ' . $c['ym'], 'POST', 'procurement.store', [], [
            'farm_id'                 => $this->farm->id,
            'supplier_id'             => $supplier->id,
            'lot_id'                  => $c['lot']->id,
            'date'                    => $d->toDateString(),
            'min_head'                => $c['head'],
            'max_head'                => $c['head'] + 2,
            'estimated_price_per_kg'  => $price,
            'estimated_avg_weight_kg' => $est,
            'payment_terms_days'      => 30,
            'currency'                => 'EGP',
            'notes'                   => 'Demo cohort ' . $c['no'],
        ]);
        if (! $res['ok']) {
            return;
        }
        $po = ProcurementRecord::where('company_id', $this->company->id)->where('po_type', 'cattle')->latest('id')->first();
        if (! $this->api('Cattle order confirm ' . $c['ym'], 'PATCH', 'procurement.confirm-po', [$po->id])['ok']) {
            return;
        }

        $animals = [];
        $sum     = 0;
        for ($k = 1; $k <= $c['head']; $k++) {
            $w0 = mt_rand(2850, 3350) / 10;
            $sum += $w0;
            $animals[] = [
                'tag'       => sprintf('A%s-%03d', $d->format('ym'), $k),
                'sex'       => 'male',
                'weight_kg' => $w0,
                'breed'     => $c['breed'],
            ];
        }
        $c['w0avg'] = $sum / $c['head'];

        $before = (int) InventoryTransaction::max('id');
        $res = $this->api('Receive cattle ' . $c['ym'], 'POST', 'procurement.confirm-receipt', [$po->id], [
            'animals'      => $animals,
            'batch_date'   => $d->toDateString(),
            'receipt_mode' => 'individual',
        ]);
        if (! $res['ok']) {
            return;
        }

        foreach (Animal::where('lot_id', $c['lot']->id)->get() as $a) {
            $w0 = collect($animals)->firstWhere('tag', $a->tag)['weight_kg'] ?? 300;
            $c['animals'][$a->id] = ['w0' => $w0, 'adg' => max(0.7, $c['adg'] + (mt_rand(-12, 12) / 100))];
        }

        $ids = InventoryTransaction::where('company_id', $this->company->id)
            ->where('type', 'livestock_receipt')->whereNull('ap_invoice_id')->where('id', '>', $before)->pluck('id')->all();
        $total = $this->m(InventoryTransaction::whereIn('id', $ids)->sum('total'));
        // Traders sometimes invoice a touch differently from the receipt — shows the price-variance entry
        if ($c['no'] % 3 === 0) {
            $total = $this->m($total * 1.004);
        }
        $this->schedule($d->copy()->addDays(2), fn () => $this->enterAndMatchInvoice('cattle', $supplier, $ids, $total, Carbon::now(), 'TRD-' . $c['ym']));
    }

    // ═════════════════════════════════════════════════════════════
    //  6. DAILY FARM WORK: vaccines, treatments, feed, weights
    // ═════════════════════════════════════════════════════════════

    private function activeAnimalIds(array $c): array
    {
        if (! $c['lot']) {
            return [];
        }
        return Animal::where('lot_id', $c['lot']->id)->whereIn('status', ['active', 'quarantine'])->pluck('id')->all();
    }

    private function vaccinate(Carbon $d): void
    {
        foreach ($this->cohorts as $c) {
            if (! $c['lot']) {
                continue;
            }
            $ids = $this->activeAnimalIds($c);
            if (! $ids) {
                continue;
            }
            $arrivalMonth = $c['buy']->format('Y-m') === $d->format('Y-m');
            $boosterMonth = $c['buy']->copy()->addMonthsNoOverflow(3)->format('Y-m') === $d->format('Y-m');

            $shots = [];
            if ($arrivalMonth) {
                $shots[] = ['fmd', 2, 'Initial FMD'];
                $shots[] = ['lsd', 1, 'Initial LSD'];
            }
            if ($boosterMonth) {
                $shots[] = ['fmd', 2, 'FMD booster'];
            }
            foreach ($shots as [$key, $dose, $note]) {
                $this->api('Vaccinate lot ' . $c['no'] . ' (' . $note . ')', 'POST', 'vaccinations.batch', [], [
                    'farm_id'           => $this->farm->id,
                    'lot_id'            => $c['lot']->id,
                    'inventory_item_id' => $this->item[$key]->id,
                    'location_id'       => $this->loc['pharma']->id,
                    'date'              => $d->toDateString(),
                    'animal_ids'        => $ids,
                    'dose_given_ml'     => $dose,
                    'batch_number'      => 'B' . $d->format('ym') . strtoupper($key),
                    'next_due_date'     => $d->copy()->addDays(90)->toDateString(),
                    'notes'             => $note,
                ]);
            }
        }
    }

    private function treat(Carbon $d): void
    {
        foreach ($this->cohorts as $c) {
            $ids = $this->activeAnimalIds($c);
            if (count($ids) < 3) {
                continue;
            }
            // two or three animals per lot need a treatment each month
            $count = 2 + (($c['no'] + $d->month) % 2);
            for ($k = 0; $k < $count; $k++) {
                $animalId = $ids[($k * 7 + $d->month) % count($ids)];
                $useOxy   = ($k + $d->month) % 2 === 0;
                $this->api('Treat animal in lot ' . $c['no'], 'POST', 'health.store', [], [
                    'animal_id'         => $animalId,
                    'farm_id'           => $this->farm->id,
                    'lot_id'            => $c['lot']->id,
                    'inventory_item_id' => $this->item[$useOxy ? 'oxy' : 'iver']->id,
                    'location_id'       => $this->loc['pharma']->id,
                    'type'              => 'treatment',
                    'date'              => $d->toDateString(),
                    'dose'              => $useOxy ? 20 : 10,
                    'notes'             => $useOxy ? 'Respiratory infection – antibiotic course' : 'Internal/external parasites',
                ]);
            }
        }
    }

    private function feedAll(Carbon $d): void
    {
        $monthStart = $d->copy()->startOfMonth();
        $days       = $monthStart->daysInMonth;

        foreach ($this->cohorts as $c) {
            if (! $c['lot'] || $c['buy']->gt($d)) {
                continue;
            }
            $ids = $this->activeAnimalIds($c);
            if (! $ids) {
                continue;
            }

            $headDays = 0;
            for ($i = 0; $i < $days; $i++) {
                $headDays += $this->headAt($c, $monthStart->copy()->addDays($i));
            }
            if ($headDays <= 0) {
                continue;
            }

            $mid  = $monthStart->copy()->addDays(14);
            $avgW = ($c['w0avg'] ?? 300) + $c['adg'] * max(0, $c['buy']->diffInDays($mid));
            $conc = round($headDays * $this->concRate($avgW), 1);
            $hay  = round($headDays * self::HAY_PER_HEAD_DAY, 1);

            $this->api('Feed lot ' . $c['no'] . ' ' . $d->format('Y-m'), 'POST', 'feed.consumption.batch', [], [
                'farm_id'    => $this->farm->id,
                'lot_id'     => $c['lot']->id,
                'date'       => $d->toDateString(),
                'animal_ids' => $ids,
                'rows'       => [
                    ['inventory_item_id' => $this->item['conc']->id, 'location_id' => $this->loc['feed']->id, 'qty_kg' => $conc],
                    ['inventory_item_id' => $this->item['hay']->id, 'location_id' => $this->loc['feed']->id, 'qty_kg' => $hay],
                ],
                'notes' => 'Monthly feed record',
            ]);
        }
    }

    private function weighAll(Carbon $d): void
    {
        foreach ($this->cohorts as $c) {
            if (! $c['lot'] || $c['buy']->gte($d)) {
                continue;
            }
            $readings = [];
            foreach ($this->activeAnimalIds($c) as $id) {
                if (isset($c['animals'][$id])) {
                    $readings[] = ['animal_id' => $id, 'weight_kg' => $this->weightOf($c['animals'][$id], $c, $d)];
                }
            }
            if ($readings) {
                $this->api('Weigh lot ' . $c['no'] . ' ' . $d->format('Y-m'), 'POST', 'weights.batch', [], [
                    'lot_id'      => $c['lot']->id,
                    'date'        => $d->toDateString(),
                    'session_ref' => 'WT-' . $d->format('Ym') . '-L' . $c['no'],
                    'readings'    => $readings,
                ]);
            }
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  7. OTHER EXPENSES (invoices without a purchase order)
    // ═════════════════════════════════════════════════════════════

    private function otherExpenses(Carbon $d): void
    {
        $g = pow(1.006, $this->idx);
        $items = [
            ['vet',   '6200', 'Veterinary consultancy', round(9000 * $g, 0), 'VET'],
            ['move',  '6910', 'Livestock transport & handling', round((6500 + 1500 * ($this->idx % 3)) * $g, 0), 'TRN'],
        ];
        if (in_array($d->month, [3, 6, 9, 12], true)) {
            $items[] = ['media', '6930', 'Marketing & promotion', round(7500 * $g, 0), 'MKT'];
        }

        foreach ($items as [$sup, $acc, $desc, $amount, $pre]) {
            $ref = $pre . '-' . $d->format('ym');
            $res = $this->api('Expense invoice ' . $ref, 'POST', 'ap-invoices.store', [], [
                'supplier_id'        => $this->sup[$sup]->id,
                'invoice_type'       => 'other',
                'expense_account_id' => $this->account($acc)->id,
                'date'               => $d->toDateString(),
                'due_date'           => $d->copy()->addDays(21)->toDateString(),
                'total'              => $amount,
                'reference'          => $ref,
                'description'        => $desc,
            ]);
            if ($res['ok']) {
                $invId = ApInvoice::where('company_id', $this->company->id)->latest('id')->value('id');
                $this->schedule($d->copy()->addDays(21), fn () => $this->paySupplier($invId, $desc));
            }
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  8. SALES AND COLLECTIONS
    // ═════════════════════════════════════════════════════════════

    private function sellDue(Carbon $d): void
    {
        foreach ($this->cohorts as $i => &$c) {
            if (! $c['lot']) {
                continue;
            }
            foreach ([['t1', 'sold1', 1], ['t2', 'sold2', 2]] as [$tKey, $flag, $tranche]) {
                if ($c[$tKey]->toDateString() === $d->toDateString() && ! $c[$flag]) {
                    $c[$flag] = true;
                    $this->sellTranche($c, $tranche, $d);
                }
            }
        }
        unset($c);
    }

    private function sellTranche(array $c, int $tranche, Carbon $d): void
    {
        $ids = $this->activeAnimalIds($c);
        if (! $ids) {
            return;
        }
        sort($ids);
        $take = $tranche === 1 ? array_slice($ids, 0, $c['n1']) : $ids;
        $pricePerKg = round(116.0 * pow(1.008, $this->idx), 2);
        $weighDay   = $d->copy()->subDay();

        $lines = [];
        $total = 0.0;
        foreach ($take as $id) {
            if (! isset($c['animals'][$id])) {
                continue;
            }
            $kg = $this->weightOf($c['animals'][$id], $c, $weighDay);
            $lines[] = ['animal_id' => $id, 'pricing_mode' => 'per_kg', 'price_per_kg' => $pricePerKg, 'qty_kg' => $kg];
            $total += round($kg * $pricePerKg, 2);
        }
        if (! $lines) {
            return;
        }

        $customer = $this->customers[($c['no'] + $tranche) % count($this->customers)];
        $label    = 'Sale lot ' . $c['no'] . ' tranche ' . $tranche;

        $res = $this->api($label, 'POST', 'sales.store', [], [
            'farm_id'            => $this->farm->id,
            'customer_id'        => $customer->id,
            'date'               => $d->toDateString(),
            'payment_terms_days' => 30,
            'currency'           => 'EGP',
            'total_amount'       => round($total, 2),
            'lines'              => $lines,
            'notes'              => 'Live sale – ' . count($lines) . ' head',
        ]);
        if (! $res['ok']) {
            return;
        }

        $sale = SalesRecord::where('company_id', $this->company->id)->latest('id')->first();
        $res  = $this->api($label . ' confirm', 'PATCH', 'sales.confirm', [$sale->id], [
            'revenue_account_id' => $this->account('4100')->id,
        ]);
        if (! $res['ok']) {
            return;
        }

        $arId = SalesRecord::find($sale->id)->ar_invoice_id;
        if ($arId) {
            // customers pay 4–5 weeks after the sale; the newest sales stay unpaid
            $this->schedule($d->copy()->addDays(26 + (($c['no'] * 3 + $tranche) % 9)), fn () => $this->collectFromCustomer($arId));
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  9. MONTH-END: depreciation, labour & overhead accrual
    // ═════════════════════════════════════════════════════════════

    private function monthEnd(Carbon $d): void
    {
        $this->postDepreciation($d);

        $g = pow(1.007, $this->idx);
        $labour = round(88000 * $g, 0);
        $over = [
            ['Electricity', round((17000 + 450 * $this->headNow($d)) * $g, 0)],
            ['Water',       round((5500 + 120 * $this->headNow($d)) * $g, 0)],
            ['Fuel',        round((12000 + 90 * $this->headNow($d)) * $g, 0)],
        ];

        $lines = [];
        foreach ($this->overheadTypes as $t) {
            foreach ($over as [$name, $amt]) {
                if ($t->name_en === $name) {
                    $lines[] = ['overhead_type_id' => $t->id, 'estimated_amount' => $amt];
                }
            }
        }

        $res = $this->api('Cost allocation ' . $d->format('Y-m'), 'POST', 'farm-cost-allocations.store', [], [
            'farm_id'         => $this->farm->id,
            'fiscal_year_id'  => $this->fy[(string) $d->year]->id,
            'month'           => $d->month,
            'year'            => $d->year,
            'labor_estimated' => $labour,
            'overhead_lines'  => $lines,
            'notes'           => 'Estimated labour and overheads',
        ]);
        if (! $res['ok']) {
            return;
        }
        $alloc = \App\Models\FarmCostAllocation::where('company_id', $this->company->id)
            ->where('year', $d->year)->where('month', $d->month)->first();
        if ($alloc) {
            $this->api('Post cost accrual ' . $d->format('Y-m'), 'POST', 'farm-cost-allocations.post', [$alloc->id]);
        }
    }

    private function headNow(Carbon $d): int
    {
        $n = 0;
        foreach ($this->cohorts as $c) {
            $n += $this->headAt($c, $d);
        }
        return $n;
    }

    /** On the 5th, the previous month's estimates are replaced by what was really paid. */
    private function settleLastMonthAllocation(Carbon $d): void
    {
        $prev  = $d->copy()->subMonthNoOverflow();
        $alloc = \App\Models\FarmCostAllocation::where('company_id', $this->company->id)
            ->where('year', $prev->year)->where('month', $prev->month)->where('status', 'posted')->first();
        if (! $alloc) {
            return;
        }

        $f      = 1 + ((mt_rand(-40, 60)) / 1000);   // actuals land within about −4% … +6% of estimate
        $labour = $this->m($alloc->labor_estimated * $f);
        $actuals = [];
        $sum = (float) $labour;
        foreach ($alloc->overheadLines as $line) {
            $a = $this->m($line->estimated_amount * (1 + (mt_rand(-50, 70) / 1000)));
            $actuals[$line->overhead_type_id] = $a;
            $sum += (float) $a;
        }
        $this->ensureFunds($this->m($sum));

        $this->api('Settle costs ' . $prev->format('Y-m'), 'POST', 'farm-cost-allocations.settle', [$alloc->id], [
            'labor_actual'       => $labour,
            'payment_account_id' => $this->bank->id,
            'overhead_actuals'   => $actuals,
        ]);
    }

    // ═════════════════════════════════════════════════════════════
    //  10. FINAL REPORT
    // ═════════════════════════════════════════════════════════════

    private function balance(string $code): string
    {
        $acc = Account::where('company_id', $this->company->id)->where('code', $code)->first();
        if (! $acc) {
            return '0.00';
        }
        $row = JournalLine::where('account_id', $acc->id)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
        return bcsub((string) $row->d, (string) $row->c, 2);
    }

    private function report(): void
    {
        $this->newLine();
        $this->info('══════════════ DEMO COMPANY READY ══════════════');
        $this->line('Company : ' . self::COMPANY_EN);
        $this->line('Login   : ' . self::EMAIL . '   /   ' . self::PASSWORD);
        $this->newLine();

        $cid = $this->company->id;
        $rows = [
            ['Animals bought', Animal::where('company_id', $cid)->count()],
            ['Animals sold', Animal::where('company_id', $cid)->where('status', 'sold')->count()],
            ['Animals still on the farm', Animal::where('company_id', $cid)->whereIn('status', ['active', 'quarantine'])->count()],
            ['Sales confirmed', SalesRecord::where('company_id', $cid)->where('status', 'confirmed')->count()],
            ['Supplier invoices', ApInvoice::where('company_id', $cid)->count()],
            ['Customer invoices', ArInvoice::where('company_id', $cid)->count()],
            ['Journal entries', \App\Models\JournalEntry::where('company_id', $cid)->count()],
            ['Capital top-ups needed', $this->topUps],
            ['Requests sent to the app', $this->calls],
        ];
        $this->table(['Item', 'Count'], $rows);

        $this->table(['Account', 'Balance (EGP)'], [
            ['Bank (1501)', number_format((float) $this->balance('1501'), 2)],
            ['Receivables (1600)', number_format((float) $this->balance('1600'), 2)],
            ['Livestock in progress (1310)', number_format((float) $this->balance('1310'), 2)],
            ['Feed inventory (1320)', number_format((float) $this->balance('1320'), 2)],
            ['Payables (2210) – credit', number_format((float) $this->balance('2210'), 2)],
            ['Sales revenue (4100) – credit', number_format((float) $this->balance('4100'), 2)],
            ['Cost of sales (5100)', number_format((float) $this->balance('5100'), 2)],
        ]);

        if ($this->failures) {
            $this->newLine();
            $this->warn(count($this->failures) . ' step(s) were refused by the app. Please send this list so they can be fixed:');
            foreach (array_slice($this->failures, 0, 40) as $f) {
                $this->line('  [' . $f['date'] . '] ' . $f['label'] . ' — ' . mb_substr($f['why'], 0, 200));
            }
        } else {
            $this->info('No problems — every step was accepted by the app.');
        }
    }
}
