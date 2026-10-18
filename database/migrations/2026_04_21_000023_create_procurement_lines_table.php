<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Procurement Lines Table
//  One line per animal purchased in a procurement record.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('procurement_id')
                ->constrained('procurement_records')
                ->onDelete('cascade');

            $table->foreignId('animal_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null');

            $table->decimal('weight_kg', 8, 2)->nullable()
                ->comment('Weight at purchase');
            $table->decimal('price_per_head', 12, 2)->nullable();
            $table->decimal('price_per_kg', 10, 4)->nullable();
            $table->decimal('freight_allocated', 12, 2)->default(0)
                ->comment('Freight cost allocated to this animal');
            $table->decimal('total_cost', 12, 2)->default(0)
                ->comment('Opening COGS for this animal');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('procurement_id');
            $table->index('animal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_lines');
    }
};