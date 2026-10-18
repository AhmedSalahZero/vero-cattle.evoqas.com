<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 📌 METHODOLOGY REFERENCE: InvoiceForeignCurrencyBalance
// exchange_rate stored at invoice creation time.
// total / paid / balance always stored in the invoice's native currency (foreign).
// EGP equivalent = amount × exchange_rate — computed at display time, never stored.
// rate = 1.000000 for EGP invoices (locked, never user-editable when currency = EGP).

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            // Rate at time of invoice creation: foreign → EGP
            // NULL allowed only briefly during migration; application always sets it.
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
        });

        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });

        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
