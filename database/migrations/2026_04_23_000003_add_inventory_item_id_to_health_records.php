<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Health Records: Add inventory_item_id
//
//  Adds a nullable FK to inventory_items so that medicine used in
//  a health record is linked to stock. medicine_name becomes a
//  denormalized copy populated server-side — never from user input.
//
//  📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            // FK to inventory_items (medicine category) — nullable so
            // existing records without inventory link are not broken
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('lot_id')
                ->constrained('inventory_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn('inventory_item_id');
        });
    }
};
