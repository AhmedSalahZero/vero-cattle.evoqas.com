<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Weight Records Table
//  Stores individual weight readings per animal over time.
//  Used to calculate ADG, FCR, total gain, and projected
//  slaughter dates. Supports both individual and batch weighing.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_records', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            // ── References ────────────────────────────────────────
            $table->foreignId('animal_id')
                ->constrained('animals')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('lots')
                ->onDelete('set null');

            // ── Weight Data ───────────────────────────────────────
            $table->date('date');
            $table->decimal('weight_kg', 8, 2);
            $table->string('scale_reference')->nullable()
                ->comment('Scale ID or location reference');

            // ── Session ───────────────────────────────────────────
            $table->string('session_ref')->nullable()
                ->comment('Batch weighing session reference — links animals weighed together');

            // ── Calculated Fields (stored for performance) ────────
            $table->decimal('adg_kg', 6, 3)->nullable()
                ->comment('Average Daily Gain since previous weight reading');
            $table->decimal('total_gain_kg', 8, 2)->nullable()
                ->comment('Total gain from entry weight to this reading');
            $table->unsignedSmallInteger('days_on_feed')->nullable()
                ->comment('Days since lot start date');

            // ── Recorded By ───────────────────────────────────────
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // ── Notes ─────────────────────────────────────────────
            $table->text('notes')->nullable();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['animal_id', 'date']);
            $table->index(['lot_id', 'date']);
            $table->index(['farm_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_records');
    }
};