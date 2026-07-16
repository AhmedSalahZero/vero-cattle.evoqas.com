<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration 067 — Add CASCADE DELETE on all company_id foreign keys
 *
 * Confirmed FK constraint names taken directly from:
 *   SELECT TABLE_NAME, CONSTRAINT_NAME
 *   FROM information_schema.KEY_COLUMN_USAGE
 *   WHERE REFERENCED_TABLE_NAME = 'companies'
 *   AND TABLE_SCHEMA = 'vero_cattle_mg'
 *
 * Notes:
 *   - animal_lot_history: no company_id FK exists in DB — skipped intentionally.
 *   - users: company_id is nullable (super admin = null). We change it to
 *     SET NULL so users are NOT deleted when a company is deleted —
 *     they become unassigned instead. The CompanyController::destroy()
 *     guard already blocks deletion if users still exist anyway.
 */
return new class extends Migration
{
    // All confirmed tables with CASCADE behaviour
    private array $cascadeTables = [
        'accounts',
        'animal_receipt_batches',
        'animals',
        'ap_invoices',
        'ar_invoices',
        'asset_depreciation_lines',
        'audit_logs',
        'bank_statements',
        'breeding_records',
        'budgets',
        'calving_records',
        'customers',
        'farm_cost_allocations',
        'farm_overhead_types',
        'farms',
        'feed_consumptions',
        'feed_items',
        'financial_ratio_comments',
        'fiscal_years',
        'fixed_assets',
        'fx_rates',
        'health_records',
        'inventory_items',
        'inventory_locations',
        'inventory_receipt_batches',
        'inventory_transactions',
        'journal_entries',
        'lots',
        'odoo_sync_log',
        'payments',
        'procurement_records',
        'rations',
        'sales_records',
        'slaughter_records',
        'suppliers',
        'user_tasks',
        'vaccination_records',
        'vaccines',
        'weight_records',
    ];

    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // ── 1. All operational + accounting tables → CASCADE ─────────
        foreach ($this->cascadeTables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_company_id_foreign");
                $t->foreign('company_id')
                  ->references('id')
                  ->on('companies')
                  ->onDelete('cascade');
            });
        }

        // ── 2. users → SET NULL (do not delete users, just unassign) ─
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign('users_company_id_foreign');
            $t->foreign('company_id')
              ->references('id')
              ->on('companies')
              ->onDelete('set null');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Restore CASCADE tables back to plain FK (no cascade)
        foreach ($this->cascadeTables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['company_id']);
                $t->foreign('company_id')
                  ->references('id')
                  ->on('companies');
            });
        }

        // Restore users back to plain FK
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['company_id']);
            $t->foreign('company_id')
              ->references('id')
              ->on('companies');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
