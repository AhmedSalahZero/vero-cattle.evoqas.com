<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Animals Table
//  The core record of the entire application. Every other
//  operational module references an animal record.
//  Each animal belongs to a farm and optionally to a lot.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animals', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('lots')
                ->onDelete('set null');

            // ── Identity ──────────────────────────────────────────
            $table->string('tag')->comment('Ear tag number — unique per farm');
            $table->string('rfid')->nullable()->comment('RFID tag for scanner integration');
            $table->string('qr_code')->nullable()->comment('Auto-generated QR code reference');

            // ── Physical Attributes ───────────────────────────────
            $table->string('breed')->nullable();
            $table->date('dob')->nullable()->comment('Date of birth or estimated');
            $table->boolean('dob_estimated')->default(false);
            $table->enum('sex', ['male', 'female', 'castrated']);
            $table->string('color')->nullable();
            $table->string('markings')->nullable();

            // ── Source ────────────────────────────────────────────
            $table->enum('source', ['purchased', 'born_on_farm'])->default('purchased');

            // ── Lineage ───────────────────────────────────────────
            $table->foreignId('dam_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null')
                ->comment('Mother reference');

            $table->foreignId('sire_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null')
                ->comment('Father reference');

            // ── Purchase Information ──────────────────────────────
            $table->date('purchase_date')->nullable();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->onDelete('set null');
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('purchase_weight_kg', 8, 2)->nullable();
            $table->decimal('freight_cost', 12, 2)->nullable()
                ->comment('Allocated freight cost for this animal');

            // ── Media ─────────────────────────────────────────────
            $table->string('photo')->nullable();

            // ── Status ────────────────────────────────────────────
            $table->enum('status', [
                'active',
                'sold',
                'slaughtered',
                'dead',
                'quarantine',
                'transferred',
            ])->default('active');

            $table->date('status_date')->nullable()
                ->comment('Date the status last changed');

            // ── Notes ─────────────────────────────────────────────
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['farm_id', 'status']);
            $table->index(['lot_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->unique(['farm_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animals');
    }
};