<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 072 — animal_breeds
//  Lookup table for breed names used in the Animal module.
//  Replaces the free-text breed field on Animals/CreateEdit.vue
//  with a selector. Stored value on animals.breed stays varchar —
//  no FK constraint — so existing data is unaffected.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animal_breeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One company cannot have duplicate breed names
            $table->unique(['company_id', 'name_en']);
            $table->unique(['company_id', 'name_ar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animal_breeds');
    }
};
