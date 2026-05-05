<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//
// This migration implements the schema changes defined in §4 of Methodology #45.
//
// Changes:
//   1. ALTER procurement_records  — add po_type column; backfill existing rows to 'cattle'
//   2. CREATE procurement_item_lines   — ordered items for inventory POs
//   3. CREATE inventory_receipt_batches — one row per receipt event
//   4. CREATE inventory_receipt_lines  — one row per item per receipt batch
//
// No existing columns are dropped or altered beyond adding po_type.
// All cattle PO logic is completely unaffected.

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // 1. ALTER procurement_records — add po_type
        // ══════════════════════════════════════════════════════════════
        Schema::table('procurement_records', function (Blueprint $table) {
            $table->enum('po_type', ['cattle', 'inventory'])
                  ->default('cattle')
                  ->after('company_id')
                  ->comment('cattle = livestock PO (existing flow); inventory = inventory items PO (new flow)');
        });

        // Backfill: all existing rows are cattle POs
        DB::table('procurement_records')->update(['po_type' => 'cattle']);

        // ══════════════════════════════════════════════════════════════
        // 2. CREATE procurement_item_lines
        //    Ordered items for inventory POs.
        //    One row per unique (procurement_id, inventory_item_id, location_id).
        // ══════════════════════════════════════════════════════════════
        Schema::create('procurement_item_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('procurement_id')
                  ->constrained('procurement_records')
                  ->cascadeOnDelete()
                  ->comment('Parent inventory PO');

            // inventory_item_id must reference a MASTER row (is_master = true).
            // This is enforced at the application layer, not the DB layer,
            // because MySQL cannot reference a partial index condition in a FK.
            $table->foreignId('inventory_item_id')
                  ->constrained('inventory_items')
                  ->comment('Master inventory item being ordered (is_master = true)');

            $table->foreignId('location_id')
                  ->constrained('inventory_locations')
                  ->comment('Destination location for this item on receipt');

            // Quantities
            $table->decimal('qty_ordered', 12, 3)
                  ->comment('Quantity ordered on the PO');

            $table->decimal('qty_received', 12, 3)
                  ->default(0.000)
                  ->comment('Running total of qty received across all batches');

            // Pricing
            $table->decimal('unit_cost_estimated', 12, 4)
                  ->comment('Estimated unit cost at PO creation time');

            $table->decimal('unit_cost_actual', 12, 4)
                  ->nullable()
                  ->comment('Actual unit cost from the most recent receipt batch');

            // Totals (server-computed)
            $table->decimal('total_estimated', 12, 2)
                  ->comment('qty_ordered × unit_cost_estimated — server-computed on create/update');

            $table->decimal('total_actual', 12, 2)
                  ->default(0.00)
                  ->comment('Running sum of all receipt line totals for this item line');

            // Status
            $table->enum('line_status', ['open', 'fully_received', 'over_received', 'force_closed'])
                  ->default('open')
                  ->comment('Auto-computed after each receipt batch');

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ──────────────────────────────────────────────
            $table->index('procurement_id');
            $table->index('inventory_item_id');
            $table->index(['procurement_id', 'line_status']);

            // One line per item+location combination per PO
            $table->unique(
                ['procurement_id', 'inventory_item_id', 'location_id'],
                'uq_proc_item_location'
            );
        });

        // ══════════════════════════════════════════════════════════════
        // 3. CREATE inventory_receipt_batches
        //    One row per receipt event (a PO may be received in
        //    multiple partial batches).
        //    Mirrors animal_receipt_batches in structure.
        // ══════════════════════════════════════════════════════════════
        Schema::create('inventory_receipt_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('procurement_id')
                  ->constrained('procurement_records')
                  ->comment('Parent inventory PO');

            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies');

            $table->unsignedBigInteger('farm_id');
            $table->foreign('farm_id')->references('id')->on('farms');

            $table->date('date')
                  ->comment('Physical receipt date');

            $table->enum('status', ['confirmed'])
                  ->default('confirmed')
                  ->comment('Receipt batches are always confirmed on creation — no draft state');

            $table->decimal('subtotal', 12, 2)
                  ->default(0.00)
                  ->comment('Sum of all receipt line totals in this batch — bcmath computed');

            // Journal entry write-back
            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->comment('T1 journal entry posted on receipt — Dr Inventory / Cr AP Accruals');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->comment('User who confirmed this receipt batch');

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ──────────────────────────────────────────────
            $table->index('procurement_id');
            $table->index('company_id');
            $table->index('journal_entry_id');
            $table->index('date');
        });

        // ══════════════════════════════════════════════════════════════
        // 4. CREATE inventory_receipt_lines
        //    One row per item per receipt batch.
        //    Carries WAC audit trail (wac_before / wac_after).
        // ══════════════════════════════════════════════════════════════
        Schema::create('inventory_receipt_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('receipt_batch_id')
                  ->constrained('inventory_receipt_batches')
                  ->cascadeOnDelete()
                  ->comment('Parent receipt batch');

            $table->foreignId('procurement_item_line_id')
                  ->constrained('procurement_item_lines')
                  ->comment('The PO line this receipt satisfies (partially or fully)');

            // Denormalised for query performance and audit integrity
            $table->foreignId('inventory_item_id')
                  ->constrained('inventory_items')
                  ->comment('Master inventory item — denormalised from PO line');

            $table->foreignId('location_id')
                  ->constrained('inventory_locations')
                  ->comment('Destination location — denormalised from PO line');

            // Receipt quantities and pricing
            $table->decimal('qty_received', 12, 3)
                  ->comment('Quantity physically received in this batch for this item');

            $table->decimal('unit_cost_actual', 12, 4)
                  ->comment('Actual unit cost agreed with supplier for this receipt');

            $table->decimal('total_actual', 12, 2)
                  ->comment('qty_received × unit_cost_actual — bcmath computed, scale=2');

            // Inventory transaction write-back
            $table->foreignId('inventory_transaction_id')
                  ->nullable()
                  ->constrained('inventory_transactions')
                  ->comment('inventory_transactions row written by InventoryReceiptService');

            // WAC audit trail
            $table->decimal('wac_before', 12, 4)
                  ->nullable()
                  ->comment('WAC on the location stock row BEFORE this receipt — captured pre-write');

            $table->decimal('wac_after', 12, 4)
                  ->nullable()
                  ->comment('WAC on the location stock row AFTER this receipt — bcmath computed');

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ──────────────────────────────────────────────
            $table->index('receipt_batch_id');
            $table->index('procurement_item_line_id');
            $table->index(['inventory_item_id', 'location_id']);
            $table->index('inventory_transaction_id');
        });
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        Schema::dropIfExists('inventory_receipt_lines');
        Schema::dropIfExists('inventory_receipt_batches');
        Schema::dropIfExists('procurement_item_lines');

        Schema::table('procurement_records', function (Blueprint $table) {
            $table->dropColumn('po_type');
        });
    }
};
