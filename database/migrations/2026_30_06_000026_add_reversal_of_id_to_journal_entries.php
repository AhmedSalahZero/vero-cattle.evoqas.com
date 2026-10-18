<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration — journal_entries.reversal_of_id
//
//  The project rules (§8.2) say a reversal entry is "linked by
//  reversal_of_id". The code (health, sales, fixed assets) already writes
//  that field — but the column never existed, and it was missing from the
//  model's $fillable, so the link was silently thrown away and a reversal
//  could not be traced back to the entry it cancels.
//
//  This adds the column (nullable, self-referencing).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('journal_entries', 'reversal_of_id')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->after('notes')
                ->constrained('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('journal_entries', 'reversal_of_id')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_id');
        });
    }
};
