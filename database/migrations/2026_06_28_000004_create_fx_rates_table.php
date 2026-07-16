<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 052 — Create fx_rates table
 *
 * Purpose:
 *   Central daily FX rate store per company.
 *   Rates here are a CONVENIENCE pre-fill — they do NOT override
 *   the exchange_rate stored on individual transactions (journal lines,
 *   ap_invoices, ar_invoices, payments). Each transaction is always
 *   the authoritative record of the rate actually used.
 *
 * Supported currencies (hard-coded in FxRateController):
 *   USD · EUR · GBP · SAR · AED · QAR
 *
 * Rate meaning: 1 foreign unit = rate EGP
 *   e.g. currency='USD', rate=31.25 means 1 USD = 31.25 EGP
 *
 * Unique constraint: one rate per (company, currency, rate_date).
 * Upsert pattern used in controller — no duplicates possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);                        // ISO 4217 code
            $table->date('rate_date');                          // Effective date
            $table->decimal('rate', 15, 6);                     // EGP per 1 unit
            $table->string('notes', 255)->nullable();           // Optional memo
            $table->foreignId('updated_by')->nullable()
                  ->constrained('users')->nullOnDelete();       // Who set it
            $table->timestamps();

            // One rate per currency per day per company
            $table->unique(['company_id', 'currency', 'rate_date'], 'fx_rates_unique');

            // Fast lookup: "latest rate for currency X in company Y"
            $table->index(['company_id', 'currency', 'rate_date'], 'fx_rates_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
