<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//
// One row per inventory item per receipt batch.
// Carries the WAC audit trail (wac_before / wac_after) so every WAC
// change is fully traceable to a specific receipt event.
//
// Deleted automatically (CASCADE) when its parent batch is deleted.
// inventory_transaction_id written back by InventoryReceiptService
// immediately after the stock row update.

class InventoryReceiptLine extends Model
{
    use HasFactory;

    protected $table = 'inventory_receipt_lines';

    protected $fillable = [
        'receipt_batch_id',
        'procurement_item_line_id',
        'inventory_item_id',        // Denormalised from PO item line — master row
        'location_id',              // Denormalised from PO item line — destination
        'qty_received',
        'unit_cost_actual',
        'total_actual',             // qty_received × unit_cost_actual — bcmath, scale=2
        'inventory_transaction_id', // Written back after stock row update
        'wac_before',               // WAC captured PRE-write — never null after confirmation
        'wac_after',                // WAC computed POST-write — bcmath, scale=4
        'notes',
    ];

    protected $casts = [
        'qty_received'       => 'decimal:3',
        'unit_cost_actual'   => 'decimal:4',
        'total_actual'       => 'decimal:2',
        'wac_before'         => 'decimal:4',
        'wac_after'          => 'decimal:4',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function receiptBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptBatch::class, 'receipt_batch_id');
    }

    public function procurementItemLine(): BelongsTo
    {
        return $this->belongsTo(ProcurementItemLine::class, 'procurement_item_line_id');
    }

    /**
     * The master inventory item received.
     * Denormalised from PO item line for query performance.
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /**
     * The inventory_transactions row written on receipt.
     * type = 'receipt', item_id = location stock row id.
     * ap_invoice_id is NULL until matched to an AP invoice.
     */
    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * WAC movement for display on receipt history.
     * Returns the difference between wac_after and wac_before.
     * Positive = WAC increased, Negative = WAC decreased.
     */
    public function wacMovement(): string
    {
        if ($this->wac_before === null || $this->wac_after === null) {
            return '0.0000';
        }

        return bcsub(
            (string) $this->wac_after,
            (string) $this->wac_before,
            4
        );
    }

    /**
     * Whether this line's transaction has been matched to an AP invoice.
     */
    public function isMatched(): bool
    {
        if (!$this->inventory_transaction_id) {
            return false;
        }

        return InventoryTransaction::where('id', $this->inventory_transaction_id)
            ->whereNotNull('ap_invoice_id')
            ->exists();
    }
}
