<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Vaccines Table
//  Vaccine catalog used across the health module.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('disease_target')->nullable();
            $table->decimal('dose_ml', 6, 2)->nullable();
            $table->string('route')->nullable()
                ->comment('e.g. subcutaneous, intramuscular');
            $table->unsignedSmallInteger('withdrawal_days')
                ->default(0);
            $table->string('storage_conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccines');
    }
};