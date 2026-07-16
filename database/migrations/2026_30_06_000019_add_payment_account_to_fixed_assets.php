<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 069 — Add payment_account_id to fixed_assets
 *
 * Previously the purchase JE always credited Accounts Payable (2100).
 * This change lets the user select the actual credit account at asset
 * creation time: Cash (14xx), Bank (15xx), or a Payables account (21xx).
 *
 * The column is nullable so that existing assets (which were saved
 * before this column existed) are not broken. The controller's
 * postPurchaseJournalEntry() already guards against a null value and
 * surfaces a flash warning instead of crashing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            // Placed after dep_expense_account_id for logical grouping
            $table->foreignId('payment_account_id')
                  ->nullable()
                  ->after('dep_expense_account_id')
                  ->constrained('accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropForeign(['payment_account_id']);
            $table->dropColumn('payment_account_id');
        });
    }
};
