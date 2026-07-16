<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Bank Statements Table
//  Imported bank statement lines for reconciliation.
//  Each line is matched against a journal entry.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->onDelete('cascade')
                ->comment('Bank account in chart of accounts');

            $table->foreignId('matched_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->onDelete('set null');

            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 14, 2)
                ->comment('Positive = credit, negative = debit');
            $table->string('reference')->nullable();

            $table->boolean('is_matched')->default(false);
            $table->boolean('is_reconciled')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'is_matched']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};