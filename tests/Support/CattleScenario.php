<?php

namespace Tests\Support;

// ══════════════════════════════════════════════════════════════════
//  CattleScenario
//
//  Builds a real, fully set-up company for a test to work on:
//  company, farm, lot, the standard Chart of Accounts (seeded by the
//  SAME seeder production uses), an open fiscal year, a customer,
//  and users with exactly the permissions a given role should have.
//
//  It only ARRANGES. It never asserts. The tests then act through the
//  real web routes (the same ones the buttons call), so what is
//  tested is what a user would actually experience.
//
//  @layer Test Support
// ══════════════════════════════════════════════════════════════════

use App\Models\Account;
use App\Models\Animal;
use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\FiscalYear;
use App\Models\Lot;
use App\Models\SalesLine;
use App\Models\ProcurementRecord;
use App\Models\SalesRecord;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

class CattleScenario
{
    public Company $company;
    public Farm $farm;
    public Lot $lot;
    public Customer $customer;
    public FiscalYear $fiscalYear;

    /** Run once per test (RefreshDatabase empties the permissions table). */
    public static function seedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function make(): self
    {
        $s = new self();

        $s->company = Company::factory()->create();
        (new ChartOfAccountsSeeder())->seedForCompany($s->company->id);

        $s->farm = Farm::factory()->create(['company_id' => $s->company->id]);
        $s->lot  = Lot::factory()->create([
            'company_id' => $s->company->id,
            'farm_id'    => $s->farm->id,
        ]);

        $s->fiscalYear = FiscalYear::factory()->create([
            'company_id' => $s->company->id,
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'status'     => 'open',
        ]);

        $s->customer = Customer::create([
            'company_id' => $s->company->id,
            'name_en'    => 'Test Customer ' . uniqid(),
            'name_ar'    => 'عميل ' . uniqid(),
            'is_active'  => true,
        ]);

        return $s;
    }

    // ── Users ─────────────────────────────────────────────────────

    /** A user of this company holding exactly the listed permissions. */
    public function user(array $permissions = []): User
    {
        $user = User::factory()->create([
            'company_id'     => $this->company->id,
            'is_active'      => true,
            'is_super_admin' => false,
        ]);
        $user->givePermissionTo($permissions);
        return $user;
    }

    public function superAdmin(): User
    {
        return User::factory()->create([
            'company_id'     => null,
            'is_active'      => true,
            'is_super_admin' => true,
        ]);
    }

    /** Every view/create/edit/delete permission — a "Company Admin". */
    public function admin(): User
    {
        return $this->user(\Spatie\Permission\Models\Permission::pluck('name')->all());
    }

    // ── Accounts ──────────────────────────────────────────────────

    public function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    // ── Animals / sales ───────────────────────────────────────────

    public function animal(array $overrides = []): Animal
    {
        return Animal::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'farm_id'    => $this->farm->id,
            'lot_id'     => $this->lot->id,
            'status'     => 'active',
        ], $overrides));
    }

    /**
     * A DRAFT sale of the given animals; each line has a price and a
     * cost. Animals are put in "reserved", as the Sales screen does.
     *
     * @param  array<int, array{animal?: Animal, price: string, cogs?: string}>  $lines
     */
    public function draftSale(array $lines, string $date = '2026-06-15'): SalesRecord
    {
        $total = '0';
        foreach ($lines as $l) {
            $total = bcadd($total, $l['price'], 2);
        }

        $sale = SalesRecord::create([
            'company_id'   => $this->company->id,
            'farm_id'      => $this->farm->id,
            'customer_id'  => $this->customer->id,
            'date'         => $date,
            'invoice_ref'  => 'INV-' . uniqid(),
            'subtotal'     => $total,
            'discount'     => 0,
            'total_amount' => $total,
            'currency'     => 'EGP',
            'status'       => 'draft',
        ]);

        foreach ($lines as $l) {
            $animal = $l['animal'] ?? $this->animal();
            $animal->update(['status' => 'reserved']);

            SalesLine::create([
                'sale_id'        => $sale->id,
                'animal_id'      => $animal->id,
                'price_per_kg'   => 0,
                'price_per_head' => $l['price'],
                'qty_kg'         => 0,
                'total'          => $l['price'],
                'cogs_per_head'  => $l['cogs'] ?? null,
            ]);
        }

        return $sale->fresh();
    }

    // ── Purchasing ────────────────────────────────────────────────

    public function supplier(): Supplier
    {
        return Supplier::factory()->create(['company_id' => $this->company->id]);
    }

    /** A DRAFT cattle purchase order, ready to be confirmed through the route. */
    public function draftCattlePo(Supplier $supplier, array $overrides = []): ProcurementRecord
    {
        return ProcurementRecord::factory()->create(array_merge([
            'company_id'             => $this->company->id,
            'farm_id'                => $this->farm->id,
            'supplier_id'            => $supplier->id,
            'lot_id'                 => $this->lot->id,
            'po_type'                => 'cattle',
            'date'                   => '2026-06-01',
            'min_head'               => 1,
            'max_head'               => 5,
            'head_count'             => 5,
            'received_head'          => 0,
            'estimated_price_per_kg' => 100,
            'status'                 => 'draft',
            'po_status'              => 'draft',
        ], $overrides));
    }

    // ── Cash, bank and open invoices ──────────────────────────────

    /** A cash safe (code 14xx) — cash payments must use one. */
    public function cashAccount(string $code = '1401'): Account
    {
        $existing = Account::where('company_id', $this->company->id)->where('code', $code)->first();
        if ($existing) return $existing;

        return Account::factory()->create([
            'company_id' => $this->company->id,
            'code'       => $code,
            'type'       => 'asset',
        ]);
    }

    /** A bank account (code 15xx) — transfers and cheques must use one. */
    public function bankAccount(string $code = '1501'): Account
    {
        $existing = Account::where('company_id', $this->company->id)->where('code', $code)->first();
        if ($existing) return $existing;

        return Account::factory()->create([
            'company_id' => $this->company->id,
            'code'       => $code,
            'type'       => 'asset',
        ]);
    }

    private int $equityCounter = 3500;

    private function nextEquityCode(): int
    {
        while (Account::where('company_id', $this->company->id)->where('code', (string) $this->equityCounter)->exists()) {
            $this->equityCounter++;
        }
        return $this->equityCounter++;
    }

    /** Put money into a cash/bank account with a balanced posted entry (Dr account / Cr equity). */
    public function fund(Account $account, string $amount, string $date = '2026-01-05'): void
    {
        $equity = Account::factory()->create([
            'company_id' => $this->company->id,
            'code'       => (string) $this->nextEquityCode(),
            'type'       => 'equity',
        ]);

        $je = JournalEntry::create([
            'company_id'     => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'date'           => $date,
            'period'         => (int) date('n', strtotime($date)),
            'reference'      => 'FUND/' . uniqid(),
            'description'    => 'Test funding',
            'status'         => 'posted',
            'entry_type'     => 'manual',
            'total_debit'    => $amount,
            'total_credit'   => $amount,
            'posted_at'      => now(),
        ]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $account->id, 'debit' => $amount, 'credit' => 0]);
        JournalLine::create(['entry_id' => $je->id, 'account_id' => $equity->id, 'debit' => 0, 'credit' => $amount]);
    }

    /** Net balance (debit − credit) of an account across all posted lines. */
    public function net(Account|string $account): string
    {
        $id = $account instanceof Account ? $account->id : $this->account($account)->id;
        $d = (string) JournalLine::where('account_id', $id)->sum('debit');
        $c = (string) JournalLine::where('account_id', $id)->sum('credit');
        return bcsub($d, $c, 2);
    }

    public function openArInvoice(string $total, ?Customer $customer = null): ArInvoice
    {
        return ArInvoice::create([
            'company_id'    => $this->company->id,
            'customer_id'   => ($customer ?? $this->customer)->id,
            'date'          => '2026-06-01',
            'due_date'      => null,
            'total'         => $total,
            'paid'          => 0,
            'balance'       => $total,
            'currency'      => 'EGP',
            'exchange_rate' => 1,
            'status'        => 'open',
        ]);
    }

    public function openApInvoice(string $total, ?Supplier $supplier = null): ApInvoice
    {
        return ApInvoice::create([
            'company_id'    => $this->company->id,
            'supplier_id'   => ($supplier ?? $this->supplier())->id,
            'date'          => '2026-06-01',
            'due_date'      => null,
            'total'         => $total,
            'paid'          => 0,
            'balance'       => $total,
            'currency'      => 'EGP',
            'exchange_rate' => 1,
            'status'        => 'open',
            'invoice_type'  => 'other',
        ]);
    }

    // ── Stock (feed / medicine / vaccine) ─────────────────────────

    /**
     * A master item of the given category plus a stock row at a new
     * farm-level location holding $qty at $unitCost.
     *
     * @return array{master: \App\Models\InventoryItem, stock: \App\Models\InventoryItem, location: \App\Models\InventoryLocation}
     */
    public function stockedItem(string $category = 'feed', string $qty = '1000', string $unitCost = '10', ?Farm $farm = null): array
    {
        $farm ??= $this->farm;

        $location = \App\Models\InventoryLocation::factory()->create([
            'company_id' => $this->company->id,
            'farm_id'    => $farm->id,
        ]);

        $master = \App\Models\InventoryItem::factory()->create([
            'company_id' => $this->company->id,
            'category'   => $category,
            'is_master'  => true,
        ]);

        $stock = \App\Models\InventoryItem::factory()->create([
            'company_id'     => $this->company->id,
            'farm_id'        => $farm->id,
            'location_id'    => $location->id,
            'master_item_id' => $master->id,
            'is_master'      => false,
            'name_en'        => null,
            'name_ar'        => null,
            'sku'            => null,
            'category'       => $category,
            'qty_on_hand'    => $qty,
            'unit_cost'      => $unitCost,
        ]);

        return compact('master', 'stock', 'location');
    }
}
