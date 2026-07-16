<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration 055
//
//  SEC-GAP-1 — No `SoftDeletes` on Core Financial Models
//
//  Problem:
//    None of the three core financial models use `SoftDeletes`.
// The application-layer guards (`destroy()` blocks on posted entries) are the only protection. 
//A future developer adding `forceDelete`, a misconfigured cascade, or a direct SQL `DELETE` 
//during migration permanently erases financial records with no recovery path.

//The Egyptian Tax Authority requires financial records to be retained for a minimum of 5 years. Hard-deleted records cannot satisfy an ETA audit request.
//
//  Fix:
//   Add soft delete

// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
   public function up(): void
{
    Schema::table('journal_entries', fn($t) => $t->softDeletes());
    Schema::table('journal_lines',   fn($t) => $t->softDeletes());
    Schema::table('payments',        fn($t) => $t->softDeletes());
}

public function down(): void
{
    Schema::table('journal_entries', fn($t) => $t->dropSoftDeletes());
    Schema::table('journal_lines',   fn($t) => $t->dropSoftDeletes());
    Schema::table('payments',        fn($t) => $t->dropSoftDeletes());
}
};
