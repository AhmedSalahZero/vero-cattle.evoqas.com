<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 047 — Foreign currency columns on journal_lines
//
//  📌 METHODOLOGY REFERENCE: ForeignCurrencyJournalLine
//
//  Adds three nullable columns to journal_lines:
//    foreign_currency  — ISO 3-letter code (USD, EUR, …)
//    foreign_amount    — the amount in the foreign currency
//    exchange_rate     — rate at transaction date (1 USD = X EGP)
//
//  The existing debit/credit columns ALWAYS store EGP (functional
//  currency). These three columns are the audit trail for the
//  conversion. They are NULL for EGP lines.
//
//  Formula: debit or credit (EGP) = foreign_amount × exchange_rate
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            // ISO currency code — NULL means EGP (functional currency)
            $table->string('foreign_currency', 3)
                  ->nullable()
                  ->after('memo');

            // Amount in the foreign currency (what the user typed)
            $table->decimal('foreign_amount', 15, 4)
                  ->nullable()
                  ->after('foreign_currency');

            // Exchange rate at transaction date: 1 unit of foreign = X EGP
            // Default 1.000000 so EGP lines need no special handling
            $table->decimal('exchange_rate', 15, 6)
                  ->nullable()
                  ->default(1.000000)
                  ->after('foreign_amount');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn(['foreign_currency', 'foreign_amount', 'exchange_rate']);
        });
    }
};