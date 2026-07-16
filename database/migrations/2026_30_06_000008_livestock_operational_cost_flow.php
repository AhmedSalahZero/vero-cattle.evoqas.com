<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration 059
//
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43)
//
//  This migration implements the agreed architectural changes for
//  the livestock operational cost flow workflow. It covers:
//
//  1. Create animal_lot_history table
//     — tracks every lot an animal has ever been in with entry
//       and exit dates. Required for date-bounded feed cost
//       allocation at COGS calculation time.
//
//  2. feed_consumptions table changes
//     — add inventory_item_id (direct binding to inventory, replaces feed_item_id)
//     — add location_id (explicit warehouse selection, replaces blind lookup)
//     — make feed_item_id nullable (was NOT NULL — kept for history, retired from workflow)
//     — ration_id already nullable — no change needed
//     — journal_entry_id already exists — no change needed
//
//  3. health_records table changes
//     — add location_id (explicit warehouse for medicine decrement)
//     — journal_entry_id already exists — no change needed
//     — inventory_item_id already exists — no change needed
//
//  4. vaccination_records table changes
//     — add inventory_item_id (direct binding to vaccine inventory item)
//     — add location_id (explicit warehouse selection)
//     — add journal_entry_id (for T4 journal entry write-back)
//     — make vaccine_id nullable (was NOT NULL — kept for history, retired from workflow)
//
//  5. Backfill animal_lot_history
//     — Option A agreed: only active animals with a lot_id get a row
//     — Sold / slaughtered / dead animals are skipped (legacy COGS applies)
//
//  DOWN: fully reversible. Drops the new table and new columns.
//  Does NOT restore feed_item_id or vaccine_id to NOT NULL —
//  restoring NOT NULL on a column that may now have NULL values
//  from new records would break down(). The nullable state is
//  permanent once up() has run.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Create animal_lot_history ──────────────────────────
        //
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
        //
        // Invariants enforced at application layer (not DB):
        //   - Only one open row per animal (exited_at IS NULL)
        //   - No gaps between periods
        //   - No overlapping periods for the same animal
        //
        // Two indexes for the two main query patterns:
        //   idx_animal_open : fast current-lot lookup (animal_id + exited_at)
        //   idx_lot_period  : fast feed cost allocation query
        //                     (lot_id + entered_at + exited_at)
        Schema::create('animal_lot_history', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('animal_id');
            $table->foreign('animal_id')
                  ->references('id')
                  ->on('animals')
                  ->onDelete('cascade');   // if animal is hard-deleted, history goes too

            $table->unsignedBigInteger('lot_id');
            $table->foreign('lot_id')
                  ->references('id')
                  ->on('lots')
                  ->onDelete('restrict');  // cannot delete a lot that has animal history

            $table->unsignedBigInteger('farm_id');
            $table->foreign('farm_id')
                  ->references('id')
                  ->on('farms')
                  ->onDelete('restrict');

            $table->unsignedBigInteger('company_id');

            // entered_at: date animal joined this lot
            // For purchased animals: = purchase_date
            // For born-on-farm animals: = dob
            $table->date('entered_at');

            // exited_at: NULL = animal is currently in this lot
            // Set when animal is transferred, sold, slaughtered, or dies
            $table->date('exited_at')->nullable();

            $table->enum('exit_reason', [
                'transferred',
                'sold',
                'slaughtered',
                'dead',
            ])->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            // Pattern 1: "what lot is animal X currently in?"
            // Used by AnimalController::transferLot() and display
            $table->index(['animal_id', 'exited_at'], 'idx_alh_animal_open');

            // Pattern 2: feed cost allocation join
            // "all membership periods for animals in lot Y between dates D1 and D2"
            // Used by LivestockIntelligenceService::costingForAnimal()
            $table->index(['lot_id', 'entered_at', 'exited_at'], 'idx_alh_lot_period');

            // Pattern 3: full history for one animal (show timeline)
            $table->index(['animal_id', 'entered_at'], 'idx_alh_animal_timeline');
        });

        // ── 2. feed_consumptions changes ──────────────────────────
        //
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
        Schema::table('feed_consumptions', function (Blueprint $table) {

            // NEW: direct binding to inventory item (category=feed)
            // NULL allowed for legacy rows that still reference feed_item_id
            // Required for all new records — enforced at application layer
            $table->unsignedBigInteger('inventory_item_id')
                  ->nullable()
                  ->after('ration_id');
            $table->foreign('inventory_item_id')
                  ->references('id')
                  ->on('inventory_items')
                  ->onDelete('restrict');

            // NEW: explicit warehouse location the feed was consumed from
            // NULL allowed for legacy rows
            // Required for all new records — enforced at application layer
            $table->unsignedBigInteger('location_id')
                  ->nullable()
                  ->after('inventory_item_id');
            $table->foreign('location_id')
                  ->references('id')
                  ->on('inventory_locations')
                  ->onDelete('restrict');

            // CHANGE: feed_item_id was NOT NULL — make nullable
            // Old workflow required it; new workflow uses inventory_item_id instead
            // Existing rows retain their feed_item_id values
            // New rows will have feed_item_id = NULL
            $table->unsignedBigInteger('feed_item_id')
                  ->nullable()
                  ->change();
        });

        // ── 3. health_records changes ─────────────────────────────
        //
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
        //
        // inventory_item_id already exists on health_records ✅
        // journal_entry_id  already exists on health_records ✅
        // Only location_id is new
        Schema::table('health_records', function (Blueprint $table) {

            // NEW: which warehouse location the medicine was taken from
            // NULL allowed for legacy records and vet_visit / surgery types
            // that do not consume inventory
            $table->unsignedBigInteger('location_id')
                  ->nullable()
                  ->after('inventory_item_id');
            $table->foreign('location_id')
                  ->references('id')
                  ->on('inventory_locations')
                  ->onDelete('restrict');
        });

        // ── 4. vaccination_records changes ────────────────────────
        //
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
        Schema::table('vaccination_records', function (Blueprint $table) {

            // NEW: direct binding to inventory item (category=vaccine)
            // NULL allowed for legacy rows that still reference vaccine_id
            // Required for all new records — enforced at application layer
            $table->unsignedBigInteger('inventory_item_id')
                  ->nullable()
                  ->after('vaccine_id');
            $table->foreign('inventory_item_id')
                  ->references('id')
                  ->on('inventory_items')
                  ->onDelete('restrict');

            // NEW: explicit warehouse location the vaccine was taken from
            $table->unsignedBigInteger('location_id')
                  ->nullable()
                  ->after('inventory_item_id');
            $table->foreign('location_id')
                  ->references('id')
                  ->on('inventory_locations')
                  ->onDelete('restrict');

            // NEW: journal entry write-back for T4 auto-posting
            $table->unsignedBigInteger('journal_entry_id')
                  ->nullable()
                  ->after('location_id');
            $table->foreign('journal_entry_id')
                  ->references('id')
                  ->on('journal_entries')
                  ->onDelete('set null');

            // CHANGE: vaccine_id was NOT NULL — make nullable
            // Old workflow required it; new workflow uses inventory_item_id
            // Existing rows retain their vaccine_id values for history
            $table->unsignedBigInteger('vaccine_id')
                  ->nullable()
                  ->change();
        });

        // ── 5. Backfill animal_lot_history ────────────────────────
        //
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
        //
        // Option A (agreed): only active animals with a lot_id get a row.
        // Sold / slaughtered / dead animals are skipped entirely.
        // Their COGS will continue to use the legacy calculation path
        // in LivestockIntelligenceService which queries operational tables
        // directly without lot history.
        //
        // entered_at = COALESCE(purchase_date, DATE(created_at))
        //   purchase_date is set for purchased animals
        //   born-on-farm animals may have dob but purchase_date is NULL
        //   created_at is the last fallback
        DB::statement("
            INSERT INTO animal_lot_history
                (animal_id, lot_id, farm_id, company_id, entered_at, exited_at, exit_reason, notes, created_at, updated_at)
            SELECT
                a.id                                        AS animal_id,
                a.lot_id                                    AS lot_id,
                a.farm_id                                   AS farm_id,
                a.company_id                                AS company_id,
                COALESCE(a.purchase_date, DATE(a.created_at)) AS entered_at,
                NULL                                        AS exited_at,
                NULL                                        AS exit_reason,
                'Backfilled by Migration 059'               AS notes,
                NOW()                                       AS created_at,
                NOW()                                       AS updated_at
            FROM animals a
            WHERE a.lot_id IS NOT NULL
              AND a.status NOT IN ('sold', 'slaughtered', 'dead')
              AND a.deleted_at IS NULL
        ");
    }

    // ── DOWN — fully reversible ───────────────────────────────────
    //
    // Note: feed_item_id and vaccine_id are NOT restored to NOT NULL.
    // Restoring a NOT NULL constraint on a column that may now have
    // NULL values from new records would fail. The nullable state is
    // permanent once this migration has run and new records exist.
    public function down(): void
    {
        // Remove foreign keys and columns from vaccination_records
        Schema::table('vaccination_records', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn(['journal_entry_id', 'location_id', 'inventory_item_id']);
        });

        // Remove foreign key and column from health_records
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        // Remove foreign keys and columns from feed_consumptions
        Schema::table('feed_consumptions', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn(['location_id', 'inventory_item_id']);
        });

        // Drop the new table — cascade handles child rows
        Schema::dropIfExists('animal_lot_history');
    }
};
