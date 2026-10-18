<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Feed Consumptions Table
//  Daily feed consumption log per lot.
//  Each record is one feed item consumed by a lot on one day.
//  Costs are automatically posted to COGS Feed Costs account.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_consumptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('lot_id')
                ->constrained('lots')
                ->onDelete('cascade');

            $table->foreignId('feed_item_id')
                ->constrained('feed_items')
                ->onDelete('cascade');

            $table->foreignId('ration_id')
                ->nullable()
                ->constrained('rations')
                ->onDelete('set null')
                ->comment('Ration this consumption was based on');

            $table->date('date');
            $table->unsignedSmallInteger('head_count')
                ->comment('Number of animals fed on this date');
            $table->decimal('qty_kg', 10, 3)
                ->comment('Total quantity consumed in kg');
            $table->decimal('unit_cost', 12, 4)
                ->comment('Unit cost at time of consumption');
            $table->decimal('total_cost', 12, 2)
                ->comment('qty_kg × unit_cost');

            // ── Variance ──────────────────────────────────────────
            $table->decimal('planned_qty_kg', 10, 3)->nullable()
                ->comment('Planned qty from ration × head count');
            $table->decimal('variance_kg', 10, 3)->nullable()
                ->comment('actual - planned');

            // ── Accounting ────────────────────────────────────────
            $table->unsignedBigInteger('journal_entry_id')
                ->nullable()
                ->comment('Linked journal entry after auto-posting');

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['lot_id', 'date']);
            $table->index(['farm_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_consumptions');
    }
};