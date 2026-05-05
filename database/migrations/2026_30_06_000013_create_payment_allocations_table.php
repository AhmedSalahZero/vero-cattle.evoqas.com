<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation (#46)
//
// Replaces single-FK pattern (payments.ar_invoice_id / payments.ap_invoice_id)
// with a pivot table that supports one payment settling multiple invoices.
//
// The old columns on `payments` are kept as nullable for backward compatibility
// with existing records — they are deprecated and must not be written to for
// any new payment created after this migration runs.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                  ->constrained('payments')
                  ->cascadeOnDelete();

            // One of these two will be set — never both, never neither
            $table->foreignId('ar_invoice_id')
                  ->nullable()
                  ->constrained('ar_invoices')
                  ->nullOnDelete();

            $table->foreignId('ap_invoice_id')
                  ->nullable()
                  ->constrained('ap_invoices')
                  ->nullOnDelete();

            // The portion of the payment allocated to this invoice
            // Stored in the invoice's native currency (same as invoice.currency)
            $table->decimal('allocated_amount', 15, 4)->default(0);

            $table->timestamps();

            // One payment cannot allocate to the same invoice twice
            $table->unique(['payment_id', 'ar_invoice_id']);
            $table->unique(['payment_id', 'ap_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
