<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
//
// Creates the pivot table linking feed_consumptions to the specific
// animals that were selected for each feeding session.
//
// This replaces the user-entered head_count with an explicit animal list.
// head_count on feed_consumptions is now derived server-side as COUNT(animal_ids).
// Old records with no pivot rows retain their stored head_count for legacy COGS.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_consumption_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_consumption_id')
                  ->constrained('feed_consumptions')
                  ->onDelete('cascade');
            $table->foreignId('animal_id')
                  ->constrained('animals')
                  ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['feed_consumption_id', 'animal_id']);
            $table->index('animal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_consumption_animals');
    }
};