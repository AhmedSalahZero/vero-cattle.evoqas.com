<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Rations Table
//  A ration defines the daily feed mix for a lot.
//  Multiple rations can exist per lot with different
//  effective dates to track ration changes over time.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->constrained('lots')
                ->onDelete('cascade');

            $table->string('name');
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);

            // ── Calculated Totals (stored for performance) ────────
            $table->decimal('cost_per_head_day', 10, 4)->nullable()
                ->comment('Auto-calculated total cost per head per day');
            $table->decimal('total_cp_pct', 5, 2)->nullable()
                ->comment('Weighted average CP%');
            $table->decimal('total_tdn_pct', 5, 2)->nullable()
                ->comment('Weighted average TDN%');
            $table->decimal('total_dm_kg', 8, 3)->nullable()
                ->comment('Total dry matter kg per head per day');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['lot_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rations');
    }
};