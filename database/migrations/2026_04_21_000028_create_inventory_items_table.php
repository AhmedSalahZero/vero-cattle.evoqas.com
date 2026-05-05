<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Items Table
//  Catalog of all stocked items: feed, medicines, supplies.
//  Valued using weighted average cost (WAC) method.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('sku')->nullable()
                ->comment('Internal stock keeping unit code');

            $table->enum('category', [
                'feed',
                'medicine',
                'vaccine',
                'equipment',
                'supply',
                'other',
            ])->default('other');

            $table->string('unit')->default('kg');
            $table->decimal('unit_cost', 12, 4)->default(0)
                ->comment('Current weighted average cost per unit');
            $table->decimal('qty_on_hand', 12, 3)->default(0);
            $table->decimal('reorder_point', 12, 3)->nullable()
                ->comment('Alert threshold for low stock');

            // ── Feed item link ─────────────────────────────────────
            $table->foreignId('feed_item_id')
                ->nullable()
                ->constrained('feed_items')
                ->onDelete('set null')
                ->comment('Link to feed catalog if category = feed');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'category']);
            $table->index(['farm_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};