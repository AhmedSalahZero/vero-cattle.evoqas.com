<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Farms Table
//  Represents a physical farm location owned by a company.
//  One company can have many farms.
//  Animals, lots, and all operational data belong to a farm.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();

            // ── Parent Company ────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            // ── Identity ──────────────────────────────────────────
            $table->string('name_en');
            $table->string('name_ar');

            // ── Location ──────────────────────────────────────────
            $table->string('address_en')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country')->default('EG');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // ── Contact ───────────────────────────────────────────
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // ── Farm Details ──────────────────────────────────────
            $table->decimal('area_acres', 10, 2)->nullable()
                ->comment('Total farm area in acres');
            $table->unsignedInteger('capacity_head')->nullable()
                ->comment('Maximum livestock capacity');
            $table->text('notes_en')->nullable();
            $table->text('notes_ar')->nullable();

            // ── Status ────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};