<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Journal Entries Table
//  Header record for every double-entry bookkeeping transaction.
//  Lines are in journal_lines table.
//  Both auto-posted (from operational events) and manual entries.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->constrained('fiscal_years')
                ->onDelete('set null');

            $table->date('date');
            $table->string('reference')->nullable()
                ->comment('Auto-generated or manual reference number');
            $table->string('description');

            $table->unsignedTinyInteger('period')
                ->comment('Month number 1-12');

            $table->enum('status', [
                'draft',
                'posted',
                'reversed',
            ])->default('draft');

            // ── Source ────────────────────────────────────────────
            $table->string('source_type')->nullable()
                ->comment('Model: procurement_records, feed_consumptions, etc.');
            $table->unsignedBigInteger('source_id')->nullable()
                ->comment('ID of the originating operational record');

            $table->enum('entry_type', [
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal',
            ])->default('auto');

            // ── Totals (stored for performance) ───────────────────
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index(['fiscal_year_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};