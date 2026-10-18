<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration — Feed Mixing (WIP)
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//
//  1. feed_mixing_batches  — one row per mixing batch
//  2. feed_mixing_lines    — ingredient lines of a batch
//  3. inventory_transactions.type  += 'mix_out', 'mix_in'
//  4. journal_entries.entry_type   += 'feed_mixing'
//
//  Delete-rule strategy (same as migration 068):
//    company_id → CASCADE (the one master delete path)
//    every other FK on the batch → RESTRICT (plain)
//    lines.batch_id → CASCADE (child of its own batch)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Batches ────────────────────────────────────────────
        Schema::create('feed_mixing_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('farm_id')->constrained('farms');
            $table->foreignId('location_id')->constrained('inventory_locations');
            $table->foreignId('output_item_id')->constrained('inventory_items')
                ->comment('MASTER inventory item the finished mix is stored as');

            $table->string('reference', 60)->nullable();
            $table->enum('status', ['draft', 'in_progress', 'completed', 'cancelled'])
                ->default('draft');

            $table->date('start_date');
            $table->date('finish_date')->nullable();

            // Filled when the batch is started (bcmath-computed server-side)
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('total_ingredient_kg', 12, 3)->default(0);

            // Filled when the batch is finished
            $table->decimal('finished_qty_kg', 12, 3)->nullable();
            $table->decimal('cost_per_kg', 12, 4)->nullable();

            // Journal entries (no FK constraint — same convention as inventory_transactions)
            $table->unsignedBigInteger('start_journal_entry_id')->nullable();
            $table->unsignedBigInteger('finish_journal_entry_id')->nullable();
            $table->unsignedBigInteger('cancel_journal_entry_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['farm_id', 'start_date']);
        });

        // ── 2. Ingredient lines ───────────────────────────────────
        Schema::create('feed_mixing_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')->constrained('feed_mixing_batches')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('inventory_items')
                ->comment('MASTER inventory item of the ingredient');

            $table->unsignedBigInteger('stock_row_id')->nullable()
                ->comment('Location-stock row the ingredient was taken from (set at start)');

            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 4)->nullable()->comment('WAC at start — server-set');
            $table->decimal('total_cost', 14, 2)->nullable()->comment('qty × unit_cost — server-set');
            $table->string('account_code', 10)->nullable()->comment('1320/1330/1340/1350 credited at start');

            $table->timestamps();
        });

        // ── 3. inventory_transactions.type — add mix_out / mix_in ──
        DB::statement("
            ALTER TABLE inventory_transactions
            MODIFY COLUMN type ENUM(
                'receipt',
                'consumption',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'livestock_receipt',
                'mix_out',
                'mix_in'
            ) NOT NULL
        ");

        // ── 4. journal_entries.entry_type — add feed_mixing ────────
        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type ENUM(
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal',
                'procurement',
                'sale',
                'feed_consumption',
                'health',
                'depreciation',
                'payment',
                'labor_overhead_accrual',
                'labor_overhead_settlement',
                'feed_mixing'
            ) NOT NULL DEFAULT 'auto'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_mixing_lines');
        Schema::dropIfExists('feed_mixing_batches');

        // Rows using the new values must be gone before the enum shrinks
        DB::table('inventory_transactions')->whereIn('type', ['mix_out', 'mix_in'])->delete();
        DB::table('journal_entries')->where('entry_type', 'feed_mixing')->update(['entry_type' => 'auto']);

        DB::statement("
            ALTER TABLE inventory_transactions
            MODIFY COLUMN type ENUM(
                'receipt','consumption','adjustment','transfer_in','transfer_out','livestock_receipt'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type ENUM(
                'auto','manual','opening_balance','closing','reversal','procurement','sale',
                'feed_consumption','health','depreciation','payment',
                'labor_overhead_accrual','labor_overhead_settlement'
            ) NOT NULL DEFAULT 'auto'
        ");
    }
};
