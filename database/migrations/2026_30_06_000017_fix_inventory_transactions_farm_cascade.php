<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration 068 — Fix double-cascade on inventory_transactions
 *
 * Problem:
 *   inventory_transactions has CASCADE on BOTH company_id and farm_id.
 *   When a company is deleted, MySQL tries to cascade-delete via two
 *   paths simultaneously (company → rows AND company → farms → rows)
 *   and throws a constraint violation error.
 *
 * Fix:
 *   Change farm_id FK from CASCADE → SET NULL.
 *   company_id CASCADE is enough to delete all rows for a company.
 *   farm_id just becomes null on those rows before they are deleted
 *   — which doesn't matter because company_id CASCADE removes them anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign('inventory_transactions_farm_id_foreign');
            $table->foreign('farm_id')
                  ->references('id')
                  ->on('farms')
                  ->onDelete('set null');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->foreign('farm_id')
                  ->references('id')
                  ->on('farms')
                  ->onDelete('cascade');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
