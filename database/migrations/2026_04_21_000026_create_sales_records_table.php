<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Sales Records Table
//  Header record for each sale transaction.
//  Automatically posts revenue entry, COGS recognition, and AR.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->onDelete('cascade');

            $table->date('date');
            $table->string('invoice_ref')->nullable();

            // ── Financials ────────────────────────────────────────
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');

            // ── Payment Terms ─────────────────────────────────────
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
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
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('ar_invoice_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'date']);
            $table->index(['farm_id', 'date']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_records');
    }
};