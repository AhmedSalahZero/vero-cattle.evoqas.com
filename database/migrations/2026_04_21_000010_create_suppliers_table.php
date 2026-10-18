<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Suppliers Table
//  Suppliers are vendors from whom animals, feed, medicines,
//  and other farm inputs are purchased.
//  Used by: procurement, inventory, and AP modules.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            // ── Identity ──────────────────────────────────────────
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('code')->nullable()
                ->comment('Internal supplier reference code');

            // ── Classification ────────────────────────────────────
            $table->enum('type', [
                'animal',
                'feed',
                'medicine',
                'equipment',
                'other',
            ])->default('other')
                ->comment('Primary supply category');

            // ── Contact ───────────────────────────────────────────
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // ── Address ───────────────────────────────────────────
            $table->string('address_en')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('EG');

            // ── Financial ─────────────────────────────────────────
            $table->string('tax_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')
                ->default(30)
                ->comment('Default payment terms in days');

            // ── Odoo Integration ──────────────────────────────────
            $table->unsignedBigInteger('odoo_partner_id')
                ->nullable()
                ->comment('Linked Odoo res.partner ID');

            // ── Status ────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Notes ─────────────────────────────────────────────
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};