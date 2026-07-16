<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Transactions Table
//  Every stock movement: purchase receipts, consumption,
//  adjustments, and transfers. WAC recalculated on each receipt.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('farm_id')
                ->constrained('farms')
                ->onDelete('cascade');

            $table->foreignId('item_id')
                ->constrained('inventory_items')
                ->onDelete('cascade');

            $table->enum('type', [
                'receipt',
                'consumption',
                'adjustment',
                'transfer_in',
                'transfer_out',
            ]);

            $table->date('date');
            $table->decimal('qty', 12, 3)
                ->comment('Positive = in, negative = out');
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // ── Running Balance (stored for performance) ──────────
            $table->decimal('qty_after', 12, 3)->nullable()
                ->comment('Stock on hand after this transaction');
            $table->decimal('wac_after', 12, 4)->nullable()
                ->comment('Weighted average cost after this transaction');

            // ── Source Reference ──────────────────────────────────
            $table->string('ref_type')->nullable()
                ->comment('Model name: procurement_records, feed_consumptions, etc.');
            $table->unsignedBigInteger('ref_id')->nullable()
                ->comment('ID of the source record');

            // ── Accounting ────────────────────────────────────────
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['item_id', 'date']);
            $table->index(['farm_id', 'date']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};