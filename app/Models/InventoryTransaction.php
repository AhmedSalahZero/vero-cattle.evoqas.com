<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — InventoryTransaction Model
//
//  📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
//
//  supplier_id   — set on type='receipt' when supplier is known
//  ref_no        — supplier delivery note / GRN / PO reference
//  ap_invoice_id — NULL = unmatched accrual; set = cleared
//  is_accrued    — computed accessor: receipt + supplier + no invoice
// ══════════════════════════════════════════════════════════════════

class InventoryTransaction extends Model
{
    protected $fillable = [
        'company_id',
        'farm_id',
        'location_id',
        'counterpart_location_id',
        'item_id',
        'type',
        'date',
        'qty',
        'unit_cost',
        'total',
        'qty_after',
        'wac_after',
        'ref_type',
        'ref_id',
        'transfer_batch_ref',
        'journal_entry_id',
        'supplier_id',       // ← NEW: 📌 InventoryReceiptAccrual
        'ref_no',            // ← NEW: 📌 InventoryReceiptAccrual
        'ap_invoice_id',     // ← NEW: 📌 InventoryReceiptAccrual
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'date'       => 'date',
        'qty'        => 'decimal:3',
        'unit_cost'  => 'decimal:4',
        'total'      => 'decimal:2',
        'qty_after'  => 'decimal:3',
        'wac_after'  => 'decimal:4',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function counterpartLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'counterpart_location_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    // The supplier who delivered this batch.
    // Only populated on type='receipt' rows.
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
    // The AP invoice this receipt was eventually matched to.
    // NULL = still in AP Accruals (GRNI — goods received, not invoiced).
    // NOT NULL = accrual has been cleared.
    public function apInvoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class);
    }

    // ── Computed accessors ────────────────────────────────────────

    /**
     * Signed qty — positive for inbound (receipt, transfer_in, positive adjustment),
     * negative for outbound (consumption, transfer_out).
     */
    public function getQtySignedAttribute(): float
    {
        return in_array($this->type, ['consumption', 'transfer_out'])
            ? -(float) $this->qty
            : (float) $this->qty;
    }

    /**
     * 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual
     *
     * is_accrued: true when this transaction represents a GRNI accrual —
     * i.e. goods were received from a known supplier but no AP invoice
     * has been matched yet. These are the rows that inflate AP Accruals
     * on the balance sheet until cleared.
     *
     * Condition: type='receipt' AND supplier_id IS NOT NULL AND ap_invoice_id IS NULL
     */
    public function getIsAccruedAttribute(): bool
    {
        return $this->type === 'receipt'
            && $this->supplier_id !== null
            && $this->ap_invoice_id === null;
    }
}