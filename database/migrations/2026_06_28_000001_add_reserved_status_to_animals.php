<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Migration 049 — Add 'reserved' to animals.status enum
//
//  Fix 1: Animal soft-lock during draft sales.
//  When an animal is added to any sale (even draft/unsaved), its
//  status is set to 'reserved' so it cannot appear in any new sale
//  animal selectors until the draft is cancelled or deleted.
//
//  Reserved animals are released back to 'active' when:
//  - The draft sale is deleted
//  - The draft sale is cancelled
//  - The sale line is removed in an update (syncLines diff)
//
//  animals.status enum before: active, sold, slaughtered, dead, quarantine, transferred
//  animals.status enum after:  active, reserved, sold, slaughtered, dead, quarantine, transferred
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // MySQL ALTER TABLE to add 'reserved' to the enum
        DB::statement("
            ALTER TABLE animals
            MODIFY COLUMN status
            ENUM('active','reserved','sold','slaughtered','dead','quarantine','transferred')
            NOT NULL DEFAULT 'active'
        ");
    }

    public function down(): void
    {
        // First release any reserved animals back to active so rollback does not fail
        DB::statement("UPDATE animals SET status = 'active' WHERE status = 'reserved'");

        DB::statement("
            ALTER TABLE animals
            MODIFY COLUMN status
            ENUM('active','sold','slaughtered','dead','quarantine','transferred')
            NOT NULL DEFAULT 'active'
        ");
    }
};
