<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Calving Records Table
//  Records birth events linking dam to newborn calf.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calving_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('dam_id')
                ->constrained('animals')
                ->onDelete('cascade');

            $table->foreignId('calf_id')
                ->nullable()
                ->constrained('animals')
                ->onDelete('set null')
                ->comment('Null if stillborn');

            $table->foreignId('breeding_record_id')
                ->nullable()
                ->constrained('breeding_records')
                ->onDelete('set null');

            $table->date('calving_date');
            $table->time('calving_time')->nullable();
            $table->decimal('birth_weight_kg', 6, 2)->nullable();

            $table->enum('condition', [
                'healthy',
                'weak',
                'stillborn',
            ])->default('healthy');

            $table->unsignedTinyInteger('difficulty_score')
                ->default(1)
                ->comment('1=easy, 2=minor assist, 3=major assist, 4=caesarean');

            $table->boolean('colostrum_fed')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['dam_id', 'calving_date']);
            $table->index(['farm_id', 'calving_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calving_records');
    }
};