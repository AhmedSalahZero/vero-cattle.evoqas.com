<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Items Unique Constraints
//
//  Adds partial unique indexes on master inventory items to prevent
//  duplicate names and SKU codes within the same company.
//
//  Rules:
//    name_en  — unique per (company_id, category) among master rows
//               where name_en IS NOT NULL
//    name_ar  — unique per (company_id, category) among master rows
//               where name_ar IS NOT NULL
//    sku      — unique per company_id among master rows
//               where sku IS NOT NULL
//
//  NOTE: MySQL does not support partial indexes natively.
//  We use full composite indexes instead. NULL values in MySQL
//  unique indexes are treated as distinct — so two rows with
//  sku = NULL do not violate the unique constraint.
//  This matches our requirement: SKU is optional, but when
//  provided it must be unique within the company.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {

            // Unique: name_en per company per category (master rows only)
            // is_master is included so location-stock rows (is_master=0)
            // are also covered — but since they have name_en = NULL they
            // never trigger the constraint in practice.
            $table->unique(
                ['company_id', 'category', 'name_en', 'is_master'],
                'inv_items_name_en_company_category_unique'
            );

            // Unique: name_ar per company per category
            $table->unique(
                ['company_id', 'category', 'name_ar', 'is_master'],
                'inv_items_name_ar_company_category_unique'
            );

            // Unique: SKU per company (NULL SKUs are always allowed — MySQL treats NULLs as distinct)
            $table->unique(
                ['company_id', 'sku', 'is_master'],
                'inv_items_sku_company_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inv_items_name_en_company_category_unique');
            $table->dropUnique('inv_items_name_ar_company_category_unique');
            $table->dropUnique('inv_items_sku_company_unique');
        });
    }
};
