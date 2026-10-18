<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 073 — inventory_item_templates
//  Lookup table for inventory item name + category presets used in
//  Inventory/CreateEdit.vue. When user picks a template, name_en,
//  name_ar, and category auto-fill on the form.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->enum('category', [
                'medicine',
                'vaccine',
                'feed',
                'equipment',
                'supply',
                'other',
            ])->default('other');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name_en']);
            $table->unique(['company_id', 'name_ar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_templates');
    }
};
