<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration 056
//  Livestock Purchase Order Lifecycle
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//
//  What this migration does (5 changes):
//
//  1. CREATE animal_receipt_batches
//     New table — one row per delivery batch against a PO.
//     Links PO → animals → inventory_transaction into one traceable chain.
//
//  2. ALTER procurement_records
//     Repurposed as a pure Purchase Order header.
//     Adds: min_head, max_head, received_head, estimated_price_per_kg,
//           estimated_avg_weight_kg, po_status, closure_reason,
//           closed_at, closed_by, po_reference (auto-reference).
//     Keeps: all existing columns — no data loss, no breaking changes.
//     The old status enum is kept for backward compatibility. po_status
//     is the new receipt-progress tracker.
//
//  3. ALTER procurement_lines
//     Lines become pure PO estimate rows (optional detail per breed/sex).
//     Adds: breed, sex, estimated_weight_kg.
//     Keeps: animal_id (kept as deprecated — was always NULL; dropped
//            in a future cleanup migration once confirmed safe).
//
//  4. ALTER animals
//     Adds: receipt_batch_id (FK → animal_receipt_batches)
//           po_price_per_kg  (PO price snapshot — immutable after receipt)
//
//  5. ALTER inventory_transactions
//     Expands type enum to include 'livestock_receipt'.
//     livestock_receipt rows are created atomically with the animal rows
//     and serve as the AP accrual anchor (ap_invoice_id = NULL until
//     accountant runs matchAccruals()).
//
//  SAFETY NOTES:
//  — All new columns are nullable or have defaults → no existing row breaks
//  — No columns are dropped in this migration
//  — The enum expansion is additive — existing rows unaffected
//  — All FKs use ON DELETE SET NULL to protect against cascade deletes
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. CREATE animal_receipt_batches ──────────────────────
        Schema::create('animal_receipt_batches', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('procurement_id')
                  ->constrained('procurement_records')
                  ->onDelete('restrict');    // Cannot delete PO that has receipts

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->onDelete('restrict');

            $table->foreignId('farm_id')
                  ->constrained('farms')
                  ->onDelete('restrict');

            $table->foreignId('lot_id')
                  ->nullable()
                  ->constrained('lots')
                  ->onDelete('set null');

            // ── Receipt details ───────────────────────────────────
            $table->date('date');                                   // actual arrival date
            $table->unsignedSmallInteger('received_head')->default(0);

            // PO price locked at the moment of this receipt
            // Used for all inventory valuation — never changes after confirm
            $table->decimal('po_price_per_kg', 10, 4);
            $table->decimal('po_price_per_head', 12, 2)->nullable();
            $table->decimal('freight_allocated', 12, 2)->default(0.00);

            // Server-computed: Σ (animal.weight × po_price_per_kg)
            $table->decimal('subtotal', 12, 2)->default(0.00);

            // ── Entry mode used for this batch ────────────────────
            // bulk_avg    = one average weight for all animals in batch
            // bulk_range  = tag range with individual weights entered
            // individual  = single-animal form, one animal
            $table->enum('receipt_mode', ['bulk_avg', 'bulk_range', 'individual'])
                  ->default('bulk_avg');

            // ── Status ────────────────────────────────────────────
            $table->enum('status', ['draft', 'confirmed'])->default('confirmed');
            // Note: batches are created as 'confirmed' in a single atomic step.
            // 'draft' is reserved for a future "save draft receipt" feature.

            // ── Accounting link ───────────────────────────────────
            // Set after the inventory_transaction row is created in the same txn.
            // ap_invoice_id lives on inventory_transactions — not here.
            $table->foreignId('inventory_transaction_id')
                  ->nullable()
                  ->constrained('inventory_transactions')
                  ->onDelete('set null');

            // ── Audit ─────────────────────────────────────────────
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->index('procurement_id');
            $table->index('company_id');
            $table->index('farm_id');
            $table->index('date');
            $table->index('status');
        });

        // ── 2. ALTER procurement_records ─────────────────────────
        Schema::table('procurement_records', function (Blueprint $table) {

            // ── Receipt progress tracking ─────────────────────────
            // min_head / max_head define the contracted range.
            // received_head is the running total from confirmed batches.
            $table->unsignedSmallInteger('min_head')->default(0)->after('head_count');
            $table->unsignedSmallInteger('max_head')->default(0)->after('min_head');
            $table->unsignedSmallInteger('received_head')->default(0)->after('max_head');

            // ── PO price estimates ────────────────────────────────
            // Locked into each receipt batch at confirmation time.
            // These are estimates on the PO — actuals may vary slightly.
            $table->decimal('estimated_price_per_kg', 10, 4)->nullable()->after('received_head');
            $table->decimal('estimated_avg_weight_kg', 8, 2)->nullable()->after('estimated_price_per_kg');

            // ── PO receipt-progress status ────────────────────────
            // Separate from the old payment-oriented `status` column.
            // po_status tracks physical delivery progress.
            // old `status` tracks payment progress (kept for backward compat).
            $table->enum('po_status', [
                'draft',                // PO created, not yet confirmed
                'open',                 // PO confirmed, no animals received yet
                'partially_received',   // Some animals received (< min_head)
                'on_track',             // Received between min_head and max_head
                'fully_received',       // received_head = max_head
                'over_received',        // received_head > max_head (warned)
                'force_closed',         // Closed short by user with reason
            ])->default('draft')->after('status');

            // ── Force-close audit ─────────────────────────────────
            $table->text('closure_reason')->nullable()->after('po_status');
            $table->timestamp('closed_at')->nullable()->after('closure_reason');
            $table->foreignId('closed_by')
                  ->nullable()
                  ->after('closed_at')
                  ->constrained('users')
                  ->onDelete('set null');

            // ── Auto-reference for PO number display ─────────────
            // Pattern: PO/{CO3}/{YYYY}/{MM}/{NNN}
            // Generated in ProcurementController::generatePoReference()
            $table->string('po_reference', 50)->nullable()->after('invoice_ref');

            // ── Index for quick open-PO lookups ──────────────────
            $table->index(['company_id', 'po_status']);
            $table->index(['supplier_id', 'po_status']);
        });

        // ── 3. ALTER procurement_lines ───────────────────────────
        Schema::table('procurement_lines', function (Blueprint $table) {
            // These make PO lines useful as breed/sex estimate rows.
            // All nullable — lines are optional detail on the PO.
            $table->string('breed', 255)->nullable()->after('notes');
            $table->enum('sex', ['male', 'female', 'castrated', 'mixed'])
                  ->nullable()
                  ->after('breed');
            $table->decimal('estimated_weight_kg', 8, 2)->nullable()->after('sex');

            // animal_id kept as-is (was always NULL in practice).
            // Will be dropped in Migration 057 after confirming no data exists.
        });

        // ── 4. ALTER animals ──────────────────────────────────────
        Schema::table('animals', function (Blueprint $table) {
            // receipt_batch_id — traceability back to the GRN batch.
            // NULL for born_on_farm animals.
            // NULL for legacy purchased animals (created before this migration).
            $table->foreignId('receipt_batch_id')
                  ->nullable()
                  ->after('lot_id')
                  ->constrained('animal_receipt_batches')
                  ->onDelete('set null');

            // po_price_per_kg — snapshot of the PO price at time of receipt.
            // Immutable after the receipt batch is confirmed.
            // Used by LivestockWIPCosting (#23) as the acquisition cost anchor.
            // NULL for born_on_farm animals.
            $table->decimal('po_price_per_kg', 10, 4)
                  ->nullable()
                  ->after('purchase_price');

            // Index for fast batch membership queries
            $table->index('receipt_batch_id');
        });

        // ── 5. ALTER inventory_transactions — expand type enum ────
        // MySQL requires re-declaring the full enum to add a value.
        // 'livestock_receipt' is the new type for animal purchase accruals.
        // It participates in the same AP matching flow as 'receipt'
        // (feed/medicine) — see ApInvoiceController::matchAccruals().
        DB::statement("
            ALTER TABLE inventory_transactions
            MODIFY COLUMN type ENUM(
                'receipt',
                'consumption',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'livestock_receipt'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // ── Reverse in strict reverse order ──────────────────────

        // 5. Revert inventory_transactions type enum
        DB::statement("
            ALTER TABLE inventory_transactions
            MODIFY COLUMN type ENUM(
                'receipt',
                'consumption',
                'adjustment',
                'transfer_in',
                'transfer_out'
            ) NOT NULL
        ");

        // 4. Revert animals
        Schema::table('animals', function (Blueprint $table) {
            $table->dropForeign(['receipt_batch_id']);
            $table->dropIndex(['receipt_batch_id']);
            $table->dropColumn(['receipt_batch_id', 'po_price_per_kg']);
        });

        // 3. Revert procurement_lines
        Schema::table('procurement_lines', function (Blueprint $table) {
            $table->dropColumn(['breed', 'sex', 'estimated_weight_kg']);
        });

        // 2. Revert procurement_records
        Schema::table('procurement_records', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropIndex(['company_id', 'po_status']);
            $table->dropIndex(['supplier_id', 'po_status']);
            $table->dropColumn([
                'min_head',
                'max_head',
                'received_head',
                'estimated_price_per_kg',
                'estimated_avg_weight_kg',
                'po_status',
                'closure_reason',
                'closed_at',
                'closed_by',
                'po_reference',
            ]);
        });

        // 1. Drop animal_receipt_batches (must be last — animals FK points here)
        Schema::dropIfExists('animal_receipt_batches');
    }
};
