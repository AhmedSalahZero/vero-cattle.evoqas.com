<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Procurement Records Table
//  Header record for each animal purchase transaction.
//  Lines are in procurement_lines (one per animal purchased).
//  Automatically creates AP entry and animal records.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('lots')
                ->onDelete('set null')
                ->comment('Lot animals are assigned to on purchase');

            $table->date('date');
            $table->string('invoice_ref')->nullable();
            $table->unsignedSmallInteger('head_count')
                ->default(0)
                ->comment('Auto-calculated from lines');

            // ── Financials ────────────────────────────────────────
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('freight_cost', 12, 2)->default(0);
            $table->decimal('other_costs', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');

            // ── Payment Terms ─────────────────────────────────────
            $table->unsignedSmallInteger('payment_terms_days')
                ->default(30);
            $table->date('due_date')->nullable();

            // ── Status ────────────────────────────────────────────
            $table->enum('status', [
                'draft',
                'confirmed',
                'partially_paid',
                'paid',
                'cancelled',
            ])->default('draft');

            // ── Accounting ────────────────────────────────────────
            $table->unsignedBigInteger('journal_entry_id')
                ->nullable();
            $table->unsignedBigInteger('ap_invoice_id')
                ->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'date']);
            $table->index(['farm_id', 'date']);
            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_records');
    }
};