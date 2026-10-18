<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Journal Lines Table
//  Individual debit/credit lines within a journal entry.
//  Each line references an account from the chart of accounts.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entry_id')
                ->constrained('journal_entries')
                ->onDelete('cascade');

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->onDelete('cascade');

            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);

            // ── Cost Centre References ─────────────────────────────
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('lots')
                ->onDelete('set null');

            $table->foreignId('animal_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null');

            $table->foreignId('farm_id')
                ->nullable()
                ->constrained('farms')
                ->onDelete('set null');

            $table->string('memo')->nullable();

            $table->timestamps();

            $table->index('entry_id');
            $table->index('account_id');
            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};