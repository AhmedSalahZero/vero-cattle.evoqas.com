<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Ration Lines Table
//  Each line defines one feed item and its quantity
//  within a ration. Multiple lines make up a complete ration.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ration_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ration_id')
                ->constrained('rations')
                ->onDelete('cascade');

            $table->foreignId('feed_item_id')
                ->constrained('feed_items')
                ->onDelete('cascade');

            $table->decimal('qty_per_head_kg', 8, 3)
                ->comment('Quantity per head per day in kg');

            // ── Calculated (stored for performance) ───────────────
            $table->decimal('cost_per_head', 10, 4)->nullable()
                ->comment('qty × unit_cost');
            $table->decimal('cp_contribution', 5, 2)->nullable()
                ->comment('CP% contribution to ration');
            $table->decimal('tdn_contribution', 5, 2)->nullable()
                ->comment('TDN% contribution to ration');

            $table->timestamps();

            $table->unique(['ration_id', 'feed_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ration_lines');
    }
};