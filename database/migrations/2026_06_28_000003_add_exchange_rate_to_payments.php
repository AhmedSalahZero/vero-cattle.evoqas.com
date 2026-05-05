<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 051 — Add exchange_rate to payments table
 *
 * The payments table already has a currency column (VARCHAR 3, DEFAULT 'EGP')
 * but was missing exchange_rate. This column was added to ap_invoices and
 * ar_invoices in Migration 048 — payments is being aligned to the same pattern.
 *
 * exchange_rate = EGP per 1 unit of the payment currency.
 * EGP payments always have exchange_rate = 1.000000 (enforced in controller).
 * Foreign currency payments store the rate at the time of the transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 15, 6)
                  ->default(1.000000)
                  ->after('currency')
                  ->comment('EGP per 1 unit of payment currency. Always 1.0 for EGP payments.');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
