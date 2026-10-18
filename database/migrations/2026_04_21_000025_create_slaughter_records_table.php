<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Slaughter Records Table
//  Records slaughter events per animal.
//  Dressing percentage auto-calculated: deadweight/liveweight×100.
//  Slaughter cost posted to COGS Slaughter Costs account.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slaughter_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('animal_id')
                ->constrained('animals')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('lots')
                ->onDelete('set null');

            $table->date('date');
            $table->string('abattoir')->nullable()
                ->comment('Slaughterhouse name or reference');

            // ── Weights ───────────────────────────────────────────
            $table->decimal('liveweight_kg', 8, 2)
                ->comment('Weight at slaughter');
            $table->decimal('deadweight_kg', 8, 2)->nullable()
                ->comment('Carcass weight');
            $table->decimal('dressing_pct', 5, 2)->nullable()
                ->comment('Auto-calculated: deadweight/liveweight×100');

            // ── Carcass Details ───────────────────────────────────
            $table->string('grade')->nullable()
                ->comment('Carcass grade/classification');
            $table->string('brand_number')->nullable()
                ->comment('Official carcass brand number');

            // ── Cost ──────────────────────────────────────────────
            $table->decimal('slaughter_cost', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');

            // ── Accounting ────────────────────────────────────────
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['animal_id', 'date']);
            $table->index(['lot_id', 'date']);
            $table->index(['farm_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slaughter_records');
    }
};