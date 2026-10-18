<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Health Records Table
//  Treatment and vet visit records per animal.
//  Costs are automatically posted to COGS Vet & Medicine account.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_records', function (Blueprint $table) {
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

            // ── Record Type ───────────────────────────────────────
            $table->enum('type', [
                'treatment',
                'vet_visit',
                'surgery',
                'other',
            ])->default('treatment');

            // ── Details ───────────────────────────────────────────
            $table->date('date');
            $table->string('condition')->nullable()
                ->comment('Diagnosis or condition treated');
            $table->string('medicine_name')->nullable();
            $table->decimal('dose', 8, 2)->nullable();
            $table->string('dose_unit')->nullable()
                ->comment('ml, mg, tablet, etc.');
            $table->string('route')->nullable()
                ->comment('Administration route');
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('currency', 3)->default('EGP');

            // ── Vet ───────────────────────────────────────────────
            $table->foreignId('vet_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->string('vet_name')->nullable()
                ->comment('External vet name if not a system user');

            // ── Outcome ───────────────────────────────────────────
            $table->enum('outcome', [
                'recovered',
                'ongoing',
                'died',
                'slaughtered',
                'unknown',
            ])->nullable();

            $table->date('withdrawal_clear_date')->nullable()
                ->comment('Date animal is cleared for slaughter after treatment');

            // ── Quarantine ────────────────────────────────────────
            $table->boolean('is_quarantine')->default(false);

            // ── Accounting ────────────────────────────────────────
            $table->unsignedBigInteger('journal_entry_id')
                ->nullable()
                ->comment('Linked journal entry after auto-posting');

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
        Schema::dropIfExists('health_records');
    }
};