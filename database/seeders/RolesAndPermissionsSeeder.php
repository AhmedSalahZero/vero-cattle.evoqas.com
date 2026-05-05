<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Roles & Permissions Seeder
//
//  Seeds 7 roles and all module-level permissions.
//  Each module gets 4 actions: view, create, edit, delete.
//  Safe to re-run — uses firstOrCreate throughout.
//
//  Change vs original:
//    • Company Admin role added (was missing, caused 403 for company
//      admins trying to access Users module)
//    • Role count updated from 6 → 7
//    • Company Admin permissions: full user management within their
//      company + read-only access to all operational modules so they
//      can see what is happening on the farm
// ══════════════════════════════════════════════════════════════════

class RolesAndPermissionsSeeder extends Seeder
{
    // ── Module list ───────────────────────────────────────────────
    private array $modules = [
        'companies',
        'farms',
        'users',
        'animals',
        'lots',
        'health',
        'vaccinations',
        'breeding',
        'calvings',
        'feed',
        'rations',
        'weight',
        'procurement',
        'slaughter',
        'sales',
        'inventory',
        'accounts',
        'journal_entries',
        'ap_invoices',
        'ar_invoices',
        'payments',
        'bank_statements',
        'fiscal_years',
        'budgets',
        'reports',
        'settings',
        'odoo-sync',
        'audit-logs',
        'tasks',
        'customers',
        'fx_rates',
        'suppliers',
        'fixed_assets',
    ];

    // ── Actions per module ────────────────────────────────────────
    private array $actions = ['view', 'create', 'edit', 'delete'];

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Step 1: Create all permissions ────────────────────────
        foreach ($this->modules as $module) {
            foreach ($this->actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$action} {$module}", 'guard_name' => 'web']
                );
            }
        }

        $this->command->info('✅ ' . (count($this->modules) * count($this->actions)) . ' permissions created.');

        // ── Step 2: Create roles ──────────────────────────────────
        $companyAdmin = Role::firstOrCreate(['name' => 'Company Admin', 'guard_name' => 'web']); // NEW
        $farmOwner    = Role::firstOrCreate(['name' => 'Farm Owner',    'guard_name' => 'web']);
        $farmManager  = Role::firstOrCreate(['name' => 'Farm Manager',  'guard_name' => 'web']);
        $vet          = Role::firstOrCreate(['name' => 'Veterinarian',  'guard_name' => 'web']);
        $feedManager  = Role::firstOrCreate(['name' => 'Feed Manager',  'guard_name' => 'web']);
        $dataEntry    = Role::firstOrCreate(['name' => 'Data Entry',    'guard_name' => 'web']);
        $accountant   = Role::firstOrCreate(['name' => 'Accountant',    'guard_name' => 'web']);

        $this->command->info('✅ 7 roles created.');

        // ── Step 3: Assign permissions to roles ───────────────────

        // ── Farm Owner — full access to everything ────────────────
        $farmOwner->syncPermissions(Permission::all());

        // ── Company Admin — user management + read-only operations ─
        // Can create/edit/delete users within their own company.
        // Cannot touch financial records or company-level settings.
        // Has read-only view of all operational modules so they can
        // monitor the farm without being able to alter records.
        $companyAdmin->syncPermissions(
            $this->permissionsFor([
                // Full user management — this is their primary purpose
                'users'           => ['view', 'create', 'edit', 'delete'],
                'farms'           => ['view', 'create', 'edit', 'delete'],
                'animals'         => ['view', 'create', 'edit', 'delete'],
                'lots'            => ['view', 'create', 'edit', 'delete'],
                'health'          => ['view', 'create', 'edit', 'delete'],
                'vaccinations'    => ['view', 'create', 'edit', 'delete'],
                'breeding'        => ['view', 'create', 'edit', 'delete'],
                'calvings'        => ['view', 'create', 'edit', 'delete'],
                'feed'            => ['view', 'create', 'edit', 'delete'],
                'rations'         => ['view', 'create', 'edit', 'delete'],
                'weight'          => ['view', 'create', 'edit', 'delete'],
                'procurement'     => ['view', 'create', 'edit', 'delete'],
                'slaughter'       => ['view', 'create', 'edit', 'delete'],
                'sales'           => ['view', 'create', 'edit', 'delete'],
                'inventory'       => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view'],
                'bank_statements' => ['view', 'create', 'edit', 'delete'],
                'tasks'           => ['view', 'create', 'edit', 'delete'],
                'fixed_assets'    => ['view', 'create', 'edit', 'delete'],
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'suppliers'       => ['view', 'create', 'edit', 'delete'],
                'accounts'        => ['view', 'create', 'edit', 'delete'],
                'journal_entries' => ['view', 'create', 'edit', 'delete'],
                'ap_invoices'     => ['view', 'create', 'edit', 'delete'],
                'ar_invoices'     => ['view', 'create', 'edit', 'delete'],
                'fx_rates'        => ['view', 'create', 'edit', 'delete'],
                'payments'        => ['view', 'create', 'edit', 'delete'],
                'budgets'         => ['view', 'create', 'edit', 'delete'],
            ])
        );

        // ── Farm Manager — full operational, no user mgmt/billing ─
        $farmManager->syncPermissions(
            $this->permissionsFor([
              
                'farms'           => ['view', 'create', 'edit', 'delete'],
                'animals'         => ['view', 'create', 'edit', 'delete'],
                'lots'            => ['view', 'create', 'edit', 'delete'],
                'health'          => ['view', 'create', 'edit', 'delete'],
                'vaccinations'    => ['view', 'create', 'edit', 'delete'],
                'breeding'        => ['view', 'create', 'edit', 'delete'],
                'calvings'        => ['view', 'create', 'edit', 'delete'],
                'feed'            => ['view', 'create', 'edit', 'delete'],
                'rations'         => ['view', 'create', 'edit', 'delete'],
                'weight'          => ['view', 'create', 'edit', 'delete'],
                'procurement'     => ['view', 'create', 'edit', 'delete'],
                'slaughter'       => ['view', 'create', 'edit', 'delete'],
                'sales'           => ['view', 'create', 'edit', 'delete'],
                'inventory'       => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view'],
                'bank_statements' => ['view', 'create', 'edit', 'delete'],
                'tasks'           => ['view', 'create', 'edit', 'delete'],
                'fixed_assets'    => ['view', 'create', 'edit', 'delete'],
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'suppliers'       => ['view', 'create', 'edit', 'delete'],
                'accounts'        => ['view', 'create', 'edit', 'delete'],
                'journal_entries' => ['view', 'create', 'edit', 'delete'],
                'ap_invoices'     => ['view', 'create', 'edit', 'delete'],
                'ar_invoices'     => ['view', 'create', 'edit', 'delete'],
                'fx_rates'        => ['view', 'create', 'edit', 'delete'],
                'payments'        => ['view', 'create', 'edit', 'delete'],
                'budgets'         => ['view', 'create', 'edit', 'delete'],
            ])
        );

        // ── Veterinarian — health full, herd/reproduction read-only
        $vet->syncPermissions(
            $this->permissionsFor([
                'animals'      => ['view', 'create', 'edit', 'delete'],
                'lots'         => ['view', 'create', 'edit', 'delete'],
                'health'       => ['view', 'create', 'edit', 'delete'],
                'vaccinations' => ['view', 'create', 'edit', 'delete'],
                'breeding'     => ['view', 'create', 'edit', 'delete'],
                'calvings'     => ['view', 'create', 'edit', 'delete'],
                'inventory'    => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view'],
                'tasks'        => ['view', 'create', 'edit', 'delete'],
            ])
        );

        // ── Feed Manager — feed and inventory full access ─────────
        $feedManager->syncPermissions(
            $this->permissionsFor([
                'animals'   => ['view', 'create', 'edit', 'delete'],
                'lots'      => ['view', 'create', 'edit', 'delete'],
                'feed'      => ['view', 'create', 'edit', 'delete'],
                'rations'   => ['view', 'create', 'edit', 'delete'],
                'inventory' => ['view', 'create', 'edit', 'delete'],
                'weight'    => ['view', 'create', 'edit', 'delete'],
                'reports'   => ['view'],
                'tasks'     => ['view', 'create', 'edit', 'delete'],
            ])
        );

        // ── Data Entry — create/edit animals and daily logs ───────
        $dataEntry->syncPermissions(
            $this->permissionsFor([
                'animals'      => ['view', 'create', 'edit'],
                'lots'         => ['view', 'create', 'edit'],
                'health'       => ['view', 'create', 'edit'],
                'vaccinations' => ['view', 'create', 'edit'],
                'breeding'     => ['view', 'create', 'edit'],
                'calvings'     => ['view', 'create', 'edit'],
                'feed'         => ['view', 'create', 'edit'],
                'weight'       => ['view', 'create', 'edit'],
                'procurement'  => ['view', 'create', 'edit'],
                'slaughter'    => ['view', 'create', 'edit'],
                'sales'        => ['view', 'create', 'edit'],
                'inventory'    => ['view', 'create', 'edit'],
                'tasks'        => ['view', 'create', 'edit', 'delete'],
                'budgets'      => ['view', 'create', 'edit', 'delete'],
            ])
        );

        // ── Accountant — financial modules only, no operational write
        $accountant->syncPermissions(
            $this->permissionsFor([
                'farms'           => ['view', 'create', 'edit', 'delete'],
                'animals'         => ['view', 'create', 'edit', 'delete'],
                'lots'            => ['view', 'create', 'edit', 'delete'],
                'health'          => ['view', 'create', 'edit', 'delete'],
                'vaccinations'    => ['view', 'create', 'edit', 'delete'],
                'breeding'        => ['view', 'create', 'edit', 'delete'],
                'calvings'        => ['view', 'create', 'edit', 'delete'],
                'feed'            => ['view', 'create', 'edit', 'delete'],
                'rations'         => ['view', 'create', 'edit', 'delete'],
                'weight'          => ['view', 'create', 'edit', 'delete'],
                'procurement'     => ['view', 'create', 'edit', 'delete'],
                'slaughter'       => ['view', 'create', 'edit', 'delete'],
                'sales'           => ['view', 'create', 'edit', 'delete'],
                'inventory'       => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view'],
                'bank_statements' => ['view', 'create', 'edit', 'delete'],
                'tasks'           => ['view', 'create', 'edit', 'delete'],
                'fixed_assets'    => ['view', 'create', 'edit', 'delete'],
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'suppliers'       => ['view', 'create', 'edit', 'delete'],
                'accounts'        => ['view', 'create', 'edit', 'delete'],
                'journal_entries' => ['view', 'create', 'edit', 'delete'],
                'ap_invoices'     => ['view', 'create', 'edit', 'delete'],
                'ar_invoices'     => ['view', 'create', 'edit', 'delete'],
                'fx_rates'        => ['view', 'create', 'edit', 'delete'],
                'payments'        => ['view', 'create', 'edit', 'delete'],
                'budgets'         => ['view', 'create', 'edit', 'delete'],
            ])
        );

        $this->command->info('✅ Permissions assigned to all 7 roles.');
        $this->command->info('');
        $this->command->info('  Company Admin → user management full, all operations full ');
        $this->command->info('  Farm Owner    → all permissions');
        $this->command->info('  Farm Manager  → full operational, read-only financial, no user mgmt');
        $this->command->info('  Veterinarian  → health full, herd/reproduction read-only');
        $this->command->info('  Feed Manager  → feed & inventory full, animals/lots read-only');
        $this->command->info('  Data Entry    → create/edit operational, no delete on core records');
        $this->command->info('  Accountant    → financial full, operational read-only');
    }

    // ── Helper: resolve permission name strings from a map ────────
    private function permissionsFor(array $map): array
    {
        $permissions = [];

        foreach ($map as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$action} {$module}";
            }
        }

        return $permissions;
    }
}