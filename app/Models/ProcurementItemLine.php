<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//
// One row per unique (procurement_id, inventory_item_id, location_id).
// Belongs to inventory POs only (po_type = 'inventory').
// Carries estimated vs actual pricing and a running qty_received counter.
// line_status is auto-computed by InventoryReceiptService after each batch.

class ProcurementItemLine extends Model
{
    use HasFactory;

    protected $table = 'procurement_item_lines';

    protected $fillable = [
        'procurement_id',
        'inventory_item_id',    // Master row only (is_master = true)
        'location_id',          // Destination location for this item
        'qty_ordered',
        'qty_received',         // Running total — incremented per receipt batch
        'unit_cost_estimated',  // Agreed estimate at PO creation time
        'unit_cost_actual',     // Filled on most recent receipt batch
        'total_estimated',      // qty_ordered × unit_cost_estimated — server-computed
        'total_actual',         // Running sum of receipt line totals — server-computed
        'line_status',          // Auto-computed: open / fully_received / over_received / force_closed
        'notes',
    ];

    protected $casts = [
        'qty_ordered'          => 'decimal:3',
        'qty_received'         => 'decimal:3',
        'unit_cost_estimated'  => 'decimal:4',
        'unit_cost_actual'     => 'decimal:4',
        'total_estimated'      => 'decimal:2',
        'total_actual'         => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRecord::class, 'procurement_id');
    }

    /**
     * The master inventory item being ordered.
     * Always is_master = true — enforced at application layer.
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
     * All receipt lines recorded against this PO item line
     * across all receipt batches.
     */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'procurement_item_line_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * How much remains to be received before hitting qty_ordered.
     * Returns '0.000' if already at or above qty_ordered.
     */
    public function remainingQty(): string
    {
        $remaining = bcsub((string) $this->qty_ordered, (string) $this->qty_received, 3);
        return bccomp($remaining, '0', 3) > 0 ? $remaining : '0.000';
    }

    /**
     * Whether this line has been over-received.
     */
    public function isOverReceived(): bool
    {
        return bccomp((string) $this->qty_received, (string) $this->qty_ordered, 3) > 0;
    }

    /**
     * Whether this line is still open for more receipts.
     */
    public function isOpen(): bool
    {
        return $this->line_status === 'open';
    }
}