<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 063 — AP Invoice Type & Expense Account
//
//  Adds two columns to ap_invoices:
//
//  invoice_type:
//    cattle    — invoice covers animal procurement (requires unmatched receipt)
//    inventory — invoice covers inventory item procurement (requires unmatched receipt)
//    other     — direct expense/asset invoice (no PO required; JE posted on save)
//
//  expense_account_id:
//    Nullable FK to accounts.id
//    Only populated when invoice_type = 'other'
//    Points to the Dr account for the direct journal entry
//    (Dr expense/asset account / Cr AP Payable 2210)
//
//  All existing rows default to 'cattle' — no existing data is affected.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            // Invoice purpose — determines flow and journal entry behaviour
            $table->enum('invoice_type', ['cattle', 'inventory', 'other'])
                  ->default('cattle')
                  ->after('notes');

            // Expense or asset account for direct (other) invoices
            // NULL for cattle and inventory type invoices
            $table->unsignedBigInteger('expense_account_id')
                  ->nullable()
                  ->after('invoice_type');

            $table->foreign('expense_account_id')
                  ->references('id')
                  ->on('accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            $table->dropForeign(['expense_account_id']);
            $table->dropColumn(['invoice_type', 'expense_account_id']);
        });
    }
};