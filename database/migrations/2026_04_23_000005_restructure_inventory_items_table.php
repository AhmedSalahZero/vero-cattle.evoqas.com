<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Restructure inventory_items
//
//  Introduces two row types on the same table:
//
//  MASTER ROW  (master_item_id = NULL, location_id = NULL)
//    → Defines the item: name, SKU, category, unit, feed_item_id
//    → One per company per item
//    → Created by the user via the form
//
//  LOCATION-STOCK ROW  (master_item_id = {master.id}, location_id set)
//    → Tracks qty and cost at one physical location
//    → Auto-created with qty=0 for every active location
//      when the master row is created (via InventoryLocationService)
//    → name/sku/category/unit are NULL — read from master
//
//  Changes:
//    • farm_id: NOT NULL → nullable (location owns the farm relationship)
//    • ADD location_id  FK → inventory_locations (nullable on master rows)
//    • ADD master_item_id FK → inventory_items self-ref (nullable on master)
//    • ADD is_master boolean flag for quick filtering
//    • name_en: NOT NULL → nullable (only master rows carry name)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {

            // ── Step 1: relax farm_id to nullable ─────────────────
            // Master rows have no farm (they are company-level).
            // Location-stock rows derive farm from their location.
            $table->foreignId('farm_id')->nullable()->change();

            // ── Step 2: relax name_en to nullable ─────────────────
            // Only master rows have a name. Location-stock rows
            // inherit name from the master via relationship.
            $table->string('name_en')->nullable()->change();

            // ── Step 3: add location_id ───────────────────────────
            // NULL on master rows. Set on location-stock rows.
            $table->foreignId('location_id')
                ->nullable()
                ->after('farm_id')
                ->constrained('inventory_locations')
                ->nullOnDelete();

            // ── Step 4: add master_item_id (self-reference) ───────
            // NULL on master rows. Points to master on location rows.
            $table->foreignId('master_item_id')
                ->nullable()
                ->after('location_id')
                ->constrained('inventory_items')
                ->cascadeOnDelete();

            // ── Step 5: add is_master flag ────────────────────────
            $table->boolean('is_master')
                ->default(false)
                ->after('master_item_id');

            // ── Step 6: index for fast location lookups ───────────
            $table->index(['master_item_id', 'location_id']);
            $table->index(['company_id', 'is_master', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['master_item_id']);
            $table->dropColumn(['location_id', 'master_item_id', 'is_master']);
            $table->string('name_en')->nullable(false)->change();
            $table->foreignId('farm_id')->nullable(false)->change();
        });
    }
};
