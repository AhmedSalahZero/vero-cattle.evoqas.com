<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Breeding Records Table
//  Tracks breeding events between dam and sire.
//  Expected calving date auto-calculated: +283 days from breeding.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breeding_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('dam_id')
                ->constrained('animals')
                ->onDelete('cascade')
                ->comment('Mother');

            $table->foreignId('sire_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null')
                ->comment('Father — nullable for AI');

            $table->enum('method', ['natural', 'ai'])
                ->default('natural')
                ->comment('Natural service or Artificial Insemination');

            $table->date('breeding_date');
            $table->date('expected_calving_date')->nullable()
                ->comment('Auto-calculated: breeding_date + 283 days');

            $table->enum('pregnancy_status', [
                'open',
                'confirmed',
                'lost',
                'calved',
            ])->default('open');

            $table->date('confirmation_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['dam_id', 'breeding_date']);
            $table->index(['farm_id', 'pregnancy_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breeding_records');
    }
};