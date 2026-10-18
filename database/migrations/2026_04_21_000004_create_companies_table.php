<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Companies Table
//  Represents the legal entity / business owner.
//  One company can own many farms (physical locations).
//  All accounting, users, and settings belong to a company.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // ── Identity ─────────────────────────────────────────
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('logo')->nullable();

            // ── Contact ──────────────────────────────────────────
            $table->string('address_en')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('country')->default('EG');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // ── Legal & Financial ─────────────────────────────────
            $table->string('tax_number')->nullable();
            $table->string('commercial_register')->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1)
                ->comment('1 = January, 7 = July, etc.');

            // ── Accounting Mode ───────────────────────────────────
            $table->enum('accounting_mode', ['standalone', 'odoo'])
                ->default('standalone');

            // ── Status ────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};