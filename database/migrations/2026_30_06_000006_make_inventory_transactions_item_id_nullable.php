<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 057 — Make inventory_transactions.item_id nullable
//
//  📌 METHODOLOGY REFERENCE: LivestockReceiptItemIdNullable (#41)
//
//  REASON:
//  inventory_transactions.item_id was NOT NULL, which forced
//  LivestockReceiptService to create/find a sentinel inventory_item
//  ("_LIVESTOCK_SENTINEL_") as a fake FK satisfier for livestock_receipt
//  rows. Animals are tracked in the `animals` table — there is no real
//  inventory_item for a living animal.
//
//  FIX:
//  Make item_id nullable. Application-level guards in InventoryController
//  and InventoryLocationService already enforce non-null item_id for all
//  other transaction types (receipt, consumption, adjustment, transfer_in,
//  transfer_out). The only code path that writes item_id = NULL is
//  LivestockReceiptService::confirmReceipt(), which is the controlled
//  single entry point for livestock_receipt rows.
//
//  DOWN MIGRATION WARNING:
//  Restoring NOT NULL will fail if any livestock_receipt rows exist
//  (item_id = NULL). Safe to down() only on a fresh database or before
//  any animals have been received via Procurement.
//
//  SENTINEL CLEANUP:
//  After running this migration, any existing _LIVESTOCK_SENTINEL_ items
//  created by the old getLivestockSentinelItemId() method can be deleted:
//
//      DB::table('inventory_items')
//          ->where('sku', '_LIVESTOCK_SENTINEL_')
//          ->delete();
//
//  Run that in Tinker after deploying this migration. The sentinel rows
//  have qty_on_hand = 0 and is_active = false, so they carry no financial
//  weight — deletion is safe.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Drop the FK constraint first (MySQL requires this before column change)
            // The FK name follows Laravel's convention: table_column_foreign
            $table->dropForeign(['item_id']);

            // Make item_id nullable
            $table->unsignedBigInteger('item_id')->nullable()->change();

            // Re-add FK with nulls allowed (MySQL allows nullable FK columns)
            $table->foreign('item_id')
                  ->references('id')
                  ->on('inventory_items')
                  ->nullOnDelete();   // if the item is ever deleted, set to null rather than cascade
        });
    }

    public function down(): void
    {
        // WARNING: This will fail if any livestock_receipt rows exist with item_id = NULL.
        // Safe only on fresh databases or before any animals have been received via Procurement.
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['item_id']);

            $table->unsignedBigInteger('item_id')->nullable(false)->change();

            $table->foreign('item_id')
                  ->references('id')
                  ->on('inventory_items');
        });
    }
};
