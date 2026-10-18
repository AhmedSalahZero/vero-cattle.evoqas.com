<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Roles & Permissions Seeder
//  Bootstraps two things only:
//    1. Permission rows in the permissions table — required so that
//       UserController::syncPermissions() has records to assign.
//    2. Role name records — required for the Role Label dropdown
//       in the user create/edit form (display only).
//
//  Access control is no longer assigned via role bundles.
//  Every user gets permissions individually via UserController.
//  Safe to re-run — uses firstOrCreate throughout.
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
        'statements',
        'analytics',
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

        // ── Step 2: Create role name records ─────────────────────
        Role::firstOrCreate(['name' => 'Company Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Farm Owner',    'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Farm Manager',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Veterinarian',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Feed Manager',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Data Entry',    'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Accountant',    'guard_name' => 'web']);

        $this->command->info('✅ 7 roles created.');
    }
       
}