<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Vaccination Records Table
//  Tracks vaccinations administered per animal.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccination_records', function (Blueprint $table) {
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

            $table->foreignId('vaccine_id')
                ->constrained('vaccines')
                ->onDelete('cascade');

            $table->date('date');
            $table->string('batch_number')->nullable();
            $table->date('next_due_date')->nullable();
            $table->decimal('dose_given_ml', 6, 2)->nullable();
            $table->decimal('cost', 12, 2)->default(0);

            $table->foreignId('administered_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['animal_id', 'date']);
            $table->index(['farm_id', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccination_records');
    }
};