<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration 045
//
//  Adds supplier accrual tracking columns to inventory_transactions.
//
//  📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
//
//  supplier_id   — which supplier delivered this batch (nullable;
//                  NULL means internal / opening stock, no accrual)
//  ref_no        — supplier delivery note or PO reference number
//                  for matching receipts to invoices manually
//  ap_invoice_id — FK to ap_invoices; NULL = accrual unmatched
//                  (goods received, invoice not yet arrived)
//                  NOT NULL = accrual cleared against this invoice
//
//  These three columns together enable the full GRNI workflow:
//    Receipt     → supplier_id set, ap_invoice_id = NULL
//    Invoice in  → ap_invoice_id set, accrual cleared (Phase 5)
//    Aging query → WHERE type='receipt' AND supplier_id IS NOT NULL
//                    AND ap_invoice_id IS NULL
//                  gives the live unmatched accruals balance
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {

            // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual — Step 5
            // supplier_id: nullable FK — only set on type='receipt' with a known supplier.
            // Placed after 'recorded_by' to keep accounting columns grouped at end.
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('recorded_by')
                ->constrained('suppliers')
                ->nullOnDelete();   // if supplier deleted, trail preserved with NULL

            // ref_no: supplier's delivery note number, GRN ref, or PO reference.
            // Used by accounting to match receipts → invoices.
            $table->string('ref_no', 100)
                ->nullable()
                ->after('supplier_id');

            // ap_invoice_id: set when this receipt is matched to an AP invoice.
            // NULL  = unmatched (in AP Accruals, liability outstanding)
            // !NULL = matched   (accrual cleared, moved to Accounts Payable)
            $table->foreignId('ap_invoice_id')
                ->nullable()
                ->after('ref_no')
                ->constrained('ap_invoices')
                ->nullOnDelete();

            // Index for the critical accrual aging query:
            // WHERE type='receipt' AND supplier_id IS NOT NULL AND ap_invoice_id IS NULL
            $table->index(['company_id', 'supplier_id', 'ap_invoice_id'], 'idx_inv_tx_accrual_aging');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_inv_tx_accrual_aging');
            $table->dropConstrainedForeignId('ap_invoice_id');
            $table->dropColumn('ref_no');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};