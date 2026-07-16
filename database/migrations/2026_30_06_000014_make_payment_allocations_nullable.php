<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('ar_invoice_id')->nullable()->change();
            $table->foreignId('ap_invoice_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('ar_invoice_id')->nullable(false)->change();
            $table->foreignId('ap_invoice_id')->nullable(false)->change();
        });
    }
};