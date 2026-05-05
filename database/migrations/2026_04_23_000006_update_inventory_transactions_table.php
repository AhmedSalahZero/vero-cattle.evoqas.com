<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Update inventory_transactions
//
//  Adds location awareness to transactions:
//
//  location_id              — which location this line is on
//  counterpart_location_id  — for transfers: the other side's location
//  transfer_batch_ref       — groups the two sides of a transfer
//
//  farm_id: relaxed to nullable — derived from location for reporting.
//
//  Transfer pair:
//    Row 1: type=transfer_out, location_id=source, counterpart=dest
//    Row 2: type=transfer_in,  location_id=dest,   counterpart=source
//    Both share the same transfer_batch_ref for easy lookup.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {

            // ── Relax farm_id to nullable ─────────────────────────
            $table->foreignId('farm_id')->nullable()->change();

            // ── Add location_id ───────────────────────────────────
            $table->foreignId('location_id')
                ->nullable()
                ->after('farm_id')
                ->constrained('inventory_locations')
                ->nullOnDelete();

            // ── Add counterpart_location_id for transfers ─────────
            $table->foreignId('counterpart_location_id')
                ->nullable()
                ->after('location_id')
                ->constrained('inventory_locations')
                ->nullOnDelete();

            // ── Transfer batch reference ──────────────────────────
            // Groups the two rows of a single transfer operation.
            $table->string('transfer_batch_ref')->nullable()->after('ref_id');

            // ── Indexes ───────────────────────────────────────────
            $table->index(['location_id', 'date']);
            $table->index('transfer_batch_ref');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['counterpart_location_id']);
            $table->dropColumn(['location_id', 'counterpart_location_id', 'transfer_batch_ref']);
            $table->foreignId('farm_id')->nullable(false)->change();
        });
    }
};
