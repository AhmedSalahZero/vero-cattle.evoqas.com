<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Sales Lines Table
//  One line per animal sold in a sales record.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales_records')
                ->onDelete('cascade');

            $table->foreignId('animal_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null');

            $table->foreignId('slaughter_id')
                ->nullable()
                ->constrained('slaughter_records')
                ->onDelete('set null')
                ->comment('Linked slaughter record if sold as carcass');

            // ── Pricing ───────────────────────────────────────────
            $table->decimal('price_per_kg', 10, 4)->nullable();
            $table->decimal('price_per_head', 12, 2)->nullable();
            $table->decimal('qty_kg', 8, 2)->nullable()
                ->comment('Deadweight kg if sold by weight');
            $table->decimal('total', 12, 2)->default(0);

            // ── COGS at time of sale ──────────────────────────────
            $table->decimal('cogs_per_head', 12, 2)->nullable()
                ->comment('Total accumulated COGS for this animal');
            $table->decimal('gross_margin', 12, 2)->nullable()
                ->comment('total - cogs_per_head');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('sale_id');
            $table->index('animal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lines');
    }
};