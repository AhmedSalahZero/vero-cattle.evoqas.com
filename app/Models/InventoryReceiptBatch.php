<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//
// One row per physical receipt event against an inventory PO.
// A single PO may have many batches (partial deliveries).
// Each batch posts exactly one T1 journal entry:
//   Dr  Inventory Account (varies by category)   batch.subtotal
//   Cr  AP Accruals (2230)                        batch.subtotal
//
// Mirrors AnimalReceiptBatch in structure.
// journal_entry_id is written back by InventoryReceiptService after posting.

class InventoryReceiptBatch extends Model
{
    use HasFactory;

    protected $table = 'inventory_receipt_batches';

    protected $fillable = [
        'procurement_id',
        'company_id',
        'farm_id',
        'date',
        'status',           // Always 'confirmed' — no draft state for receipt batches
        'subtotal',         // Σ of all receipt line totals — bcmath computed
        'journal_entry_id', // T1 JE written back by InventoryReceiptService
        'created_by',
        'notes',
    ];

    protected $casts = [
        'date'     => 'datetime:Y-m-d',
        'subtotal' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRecord::class, 'procurement_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * All item lines received in this batch.
     * One row per inventory item in the batch.
     */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'receipt_batch_id');
    }

    /**
     * The T1 journal entry posted when this batch was confirmed.
     * Dr Inventory Account / Cr AP Accruals (2230).
     * NULL until InventoryReceiptService writes it back.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether this batch has been matched to an AP invoice.
     * A batch is matched when ALL its inventory_transactions have ap_invoice_id set.
     * Used to show matched/pending badge on Procurement/Show.vue.
     */
    public function isMatched(): bool
    {
        $lines = $this->receiptLines;

        if ($lines->isEmpty()) {
            return false;
        }

        // All receipt lines must have a linked transaction,
        // and all those transactions must have ap_invoice_id set.
        $transactionIds = $lines->pluck('inventory_transaction_id')->filter();

        if ($transactionIds->count() !== $lines->count()) {
            return false;
        }

        return InventoryTransaction::whereIn('id', $transactionIds)
            ->whereNull('ap_invoice_id')
            ->doesntExist();
    }
}
