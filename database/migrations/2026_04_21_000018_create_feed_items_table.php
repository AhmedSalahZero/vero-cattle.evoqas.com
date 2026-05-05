<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Feed Items Table
//  Feed catalog with nutritional profiles and unit costs.
//  Used by rations, daily feed logs, and inventory.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->string('name_en');
            $table->string('name_ar')->nullable();

            $table->enum('type', [
                'roughage',
                'concentrate',
                'supplement',
                'mineral',
                'other',
            ])->default('other');

            $table->string('unit')->default('kg')
                ->comment('Unit of measure: kg, ton, bale, etc.');

            $table->decimal('unit_cost', 12, 2)->default(0)
                ->comment('Cost per unit');

            // ── Nutritional Profile ───────────────────────────────
            $table->decimal('cp_pct', 5, 2)->nullable()
                ->comment('Crude Protein %');
            $table->decimal('tdn_pct', 5, 2)->nullable()
                ->comment('Total Digestible Nutrients %');
            $table->decimal('dm_pct', 5, 2)->nullable()
                ->comment('Dry Matter %');
            $table->decimal('ndf_pct', 5, 2)->nullable()
                ->comment('Neutral Detergent Fiber %');
            $table->decimal('me_mj', 6, 2)->nullable()
                ->comment('Metabolizable Energy MJ/kg DM');

            // ── Supplier ──────────────────────────────────────────
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->onDelete('set null');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
    }
};