<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Lots Table
//  A lot is a group of animals managed together for feeding,
//  health, and cost tracking purposes. All operational costs
//  are tracked at the lot level enabling per-lot profitability.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            // ── Identity ──────────────────────────────────────────
            $table->string('name');
            $table->string('lot_number')->nullable()
                ->comment('Internal lot reference number');

            // ── Dates ─────────────────────────────────────────────
            $table->date('start_date');
            $table->date('target_slaughter_date')->nullable();
            $table->date('closed_date')->nullable();

            // ── Location ──────────────────────────────────────────
            $table->string('pen')->nullable()
                ->comment('Pen or paddock identifier within the farm');

            // ── Status ────────────────────────────────────────────
            $table->enum('status', ['active', 'completed', 'archived'])
                ->default('active');

            // ── Notes ─────────────────────────────────────────────
            $table->text('notes_en')->nullable();
            $table->text('notes_ar')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'status']);
            $table->index(['farm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};