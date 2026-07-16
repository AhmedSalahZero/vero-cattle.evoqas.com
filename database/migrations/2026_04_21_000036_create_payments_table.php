<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Payments Table
//  Records payments against AP or AR invoices.
//  Each payment posts a journal entry: Dr AP/CR Cash or Dr Cash/Cr AR.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->enum('type', ['incoming', 'outgoing'])
                ->comment('incoming = customer payment, outgoing = supplier payment');

            // ── Invoice References ────────────────────────────────
            $table->foreignId('ap_invoice_id')
                ->nullable()
                ->constrained('ap_invoices')
                ->onDelete('set null');

            $table->foreignId('ar_invoice_id')
                ->nullable()
                ->constrained('ar_invoices')
                ->onDelete('set null');

            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->onDelete('set null');

            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EGP');

            $table->enum('method', [
                'cash',
                'bank_transfer',
                'cheque',
                'other',
            ])->default('bank_transfer');

            $table->string('reference')->nullable()
                ->comment('Cheque number, transfer ref, etc.');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};