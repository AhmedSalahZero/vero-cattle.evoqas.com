<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration 058 — Expand journal_entries.entry_type enum
 *
 * PROBLEM:
 *   The current enum only has 5 values:
 *     'auto','manual','opening_balance','closing','reversal'
 *
 *   LivestockReceiptService::postReceiptJournalEntry() writes entry_type = 'procurement'
 *   which does NOT exist in the enum. MySQL throws a data truncation error and
 *   rolls back the entire animal receipt — animals, batch, inventory_transaction,
 *   and JE all fail together.
 *
 *   The VERO_Master_Methodology_Reference.md defines the correct full enum,
 *   including all Phase 5 auto-posting entry types.
 *
 * FIX:
 *   Expand the enum to include all current and Phase 5 entry types.
 *   Existing rows are unaffected — all current values remain valid.
 *
 * New enum values added:
 *   'procurement'      — T1: livestock receipt (Dr WIP / Cr AP Accruals)
 *   'sale'             — Phase 5: sale confirmation (Dr COGS / Cr Livestock WIP)
 *   'feed_consumption' — Phase 5: feed drawn from inventory
 *   'health'           — Phase 5: medicine/vet cost posted to WIP
 *   'depreciation'     — Phase 5: fixed asset depreciation
 *   'payment'          — Phase 5: supplier/customer payment
 *
 * Note: 'auto' is kept for backward compatibility with existing rows.
 * Note: 'closing' is kept for fiscal year closing entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type
            ENUM(
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal',
                'procurement',
                'sale',
                'feed_consumption',
                'health',
                'depreciation',
                'payment'
            ) NOT NULL DEFAULT 'auto'
        ");
    }

    public function down(): void
    {
        // Revert to original 5-value enum.
        // WARNING: Any rows with the new entry_type values will cause
        // this rollback to fail. Truncate or update those rows first.
        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type
            ENUM(
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal'
            ) NOT NULL DEFAULT 'auto'
        ");
    }
};
