<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — User Farms Pivot Table
//  Links users to the farms they have access to.
//  A Farm Owner sees all farms under their company.
//  A site-level user (manager, vet, feed manager) is scoped
//  to one or more specific farms via this table.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_farms', function (Blueprint $table) {
            $table->id();

            // ── Relationships ─────────────────────────────────────
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            // ── Access Control ────────────────────────────────────
            $table->boolean('is_primary')
                ->default(false)
                ->comment('Primary farm shown on user dashboard by default');

            // ── Timestamps ────────────────────────────────────────
            $table->timestamps();

            // ── Unique Constraint ─────────────────────────────────
            $table->unique(['user_id', 'farm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_farms');
    }
};