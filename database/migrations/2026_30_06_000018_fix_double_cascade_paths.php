<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration 068 — Fix all double-cascade paths
 *
 * Constraint names verified directly from:
 *   SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE
 *   FROM information_schema.REFERENTIAL_CONSTRAINTS
 *   WHERE CONSTRAINT_SCHEMA = 'vero_cattle_mg'
 *   AND DELETE_RULE = 'CASCADE'
 *   AND CONSTRAINT_NAME NOT LIKE '%company_id%'
 *
 * Strategy:
 *   company_id CASCADE = the ONE master delete path (kept from migration 067)
 *   All other CASCADE FKs on the same tables → changed to plain RESTRICT
 *   RESTRICT = referential integrity only, no cascade delete
 *
 * Tables intentionally left alone (their cascades are correct child→parent):
 *   animal_lot_history_animal_id_foreign     — child of animals, not company
 *   farm_overhead_lines_farm_cost_allocation — child of farm_cost_allocations
 *   feed_consumption_animals_*               — pivot table, child of both
 *   inventory_receipt_lines_receipt_batch    — child of inventory_receipt_batches
 *   journal_lines_entry_id                   — child of journal_entries
 *   payment_allocations_payment_id           — child of payments
 *   procurement_item_lines_procurement_id    — child of procurement_records
 *   procurement_lines_procurement_id         — child of procurement_records
 *   ration_lines_ration_id                   — child of rations
 *   sales_lines_sale_id                      — child of sales_records
 *   model_has_permissions / model_has_roles / role_has_permissions — Spatie, leave alone
 *   user_farms_farm_id / user_farms_user_id  — pivot, leave alone
 *   user_tasks_user_id                       — user relation, leave alone
 *
 * Only fixing tables that have BOTH company_id CASCADE AND another CASCADE
 * on the same table — these are the ones causing the double-path conflict.
 */
return new class extends Migration
{
    // Verified exact constraint names from DB — column is NOT NULL so RESTRICT only
    // Format: [ table, exact_constraint_name, column, referenced_table ]
    private array $fixes = [
        // ap_invoices: has company_id CASCADE + supplier_id CASCADE
        ['ap_invoices',              'ap_invoices_supplier_id_foreign',                 'supplier_id',    'suppliers'],

        // ar_invoices: has company_id CASCADE + customer_id CASCADE
        ['ar_invoices',              'ar_invoices_customer_id_foreign',                 'customer_id',    'customers'],

        // asset_depreciation_lines: company_id + asset_id + fiscal_year_id CASCADE
        ['asset_depreciation_lines', 'asset_depreciation_lines_asset_id_foreign',       'asset_id',       'fixed_assets'],
        ['asset_depreciation_lines', 'asset_depreciation_lines_fiscal_year_id_foreign', 'fiscal_year_id', 'fiscal_years'],

        // bank_statements: company_id CASCADE + account_id CASCADE
        ['bank_statements',          'bank_statements_account_id_foreign',              'account_id',     'accounts'],

        // breeding_records: company_id + dam_id + farm_id CASCADE
        ['breeding_records',         'breeding_records_dam_id_foreign',                 'dam_id',         'animals'],
        ['breeding_records',         'breeding_records_farm_id_foreign',                'farm_id',        'farms'],

        // budgets: company_id + account_id + fiscal_year_id CASCADE
        ['budgets',                  'budgets_account_id_foreign',                      'account_id',     'accounts'],
        ['budgets',                  'budgets_fiscal_year_id_foreign',                  'fiscal_year_id', 'fiscal_years'],

        // calving_records: company_id + dam_id + farm_id CASCADE
        ['calving_records',          'calving_records_dam_id_foreign',                  'dam_id',         'animals'],
        ['calving_records',          'calving_records_farm_id_foreign',                 'farm_id',        'farms'],

        // feed_consumptions: company_id + farm_id + feed_item_id + lot_id CASCADE
        ['feed_consumptions',        'feed_consumptions_farm_id_foreign',               'farm_id',        'farms'],
        ['feed_consumptions',        'feed_consumptions_feed_item_id_foreign',          'feed_item_id',   'feed_items'],
        ['feed_consumptions',        'feed_consumptions_lot_id_foreign',                'lot_id',         'lots'],

        // financial_ratio_comments: company_id + fiscal_year_id CASCADE
        ['financial_ratio_comments', 'financial_ratio_comments_fiscal_year_id_foreign', 'fiscal_year_id', 'fiscal_years'],

        // health_records: company_id + animal_id + farm_id CASCADE
        ['health_records',           'health_records_animal_id_foreign',                'animal_id',      'animals'],
        ['health_records',           'health_records_farm_id_foreign',                  'farm_id',        'farms'],

        // inventory_items: company_id + farm_id + master_item_id CASCADE
        ['inventory_items',          'inventory_items_farm_id_foreign',                 'farm_id',        'farms'],
        ['inventory_items',          'inventory_items_master_item_id_foreign',          'master_item_id', 'inventory_items'],

        // journal_lines: entry_id CASCADE + account_id CASCADE
        // (no company_id on journal_lines — but account_id cascade conflicts with accounts cascade)
        ['journal_lines',            'journal_lines_account_id_foreign',                'account_id',     'accounts'],

        // lots: company_id + farm_id CASCADE
        ['lots',                     'lots_farm_id_foreign',                            'farm_id',        'farms'],

        // procurement_records: company_id + farm_id + supplier_id CASCADE
        ['procurement_records',      'procurement_records_farm_id_foreign',             'farm_id',        'farms'],
        ['procurement_records',      'procurement_records_supplier_id_foreign',         'supplier_id',    'suppliers'],

        // ration_lines: ration_id CASCADE + feed_item_id CASCADE
        ['ration_lines',             'ration_lines_feed_item_id_foreign',               'feed_item_id',   'feed_items'],

        // rations: company_id + farm_id + lot_id CASCADE
        ['rations',                  'rations_farm_id_foreign',                         'farm_id',        'farms'],
        ['rations',                  'rations_lot_id_foreign',                          'lot_id',         'lots'],

        // sales_records: company_id + customer_id + farm_id CASCADE
        ['sales_records',            'sales_records_customer_id_foreign',               'customer_id',    'customers'],
        ['sales_records',            'sales_records_farm_id_foreign',                   'farm_id',        'farms'],

        // slaughter_records: company_id + animal_id + farm_id CASCADE
        ['slaughter_records',        'slaughter_records_animal_id_foreign',             'animal_id',      'animals'],
        ['slaughter_records',        'slaughter_records_farm_id_foreign',               'farm_id',        'farms'],

        // vaccination_records: company_id + animal_id + farm_id + vaccine_id CASCADE
        ['vaccination_records',      'vaccination_records_animal_id_foreign',           'animal_id',      'animals'],
        ['vaccination_records',      'vaccination_records_farm_id_foreign',             'farm_id',        'farms'],
        ['vaccination_records',      'vaccination_records_vaccine_id_foreign',          'vaccine_id',     'vaccines'],

        // weight_records: company_id + animal_id + farm_id CASCADE
        ['weight_records',           'weight_records_animal_id_foreign',                'animal_id',      'animals'],
        ['weight_records',           'weight_records_farm_id_foreign',                  'farm_id',        'farms'],
    ];

    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->fixes as [$table, $constraintName, $column, $referencedTable]) {
            Schema::table($table, function (Blueprint $t) use ($constraintName, $column, $referencedTable) {
                $t->dropForeign($constraintName);
                // Plain FK — RESTRICT (default): enforces integrity, no cascade
                $t->foreign($column)
                  ->references('id')
                  ->on($referencedTable);
            });
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->fixes as [$table, $constraintName, $column, $referencedTable]) {
            Schema::table($table, function (Blueprint $t) use ($column, $referencedTable) {
                $t->dropForeign([$column]);
                $t->foreign($column)
                  ->references('id')
                  ->on($referencedTable)
                  ->onDelete('cascade');
            });
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
