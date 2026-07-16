<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AnimalReceiptBatch Model
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//
//  Represents one delivery batch of animals against a Purchase Order.
//  Created atomically with:
//    — Animal rows (one per animal received)
//    — InventoryTransaction (type = 'livestock_receipt')
//
//  Lifecycle:
//    ProcurementRecord (PO) → AnimalReceiptBatch → Animal(s)
//                                                → InventoryTransaction
//                                                    → ApInvoice (via matchAccruals)
//
//  No SoftDeletes: confirmed batches are immutable.
//  Deletion of a confirmed batch is blocked at the controller level.
// ══════════════════════════════════════════════════════════════════

class AnimalReceiptBatch extends Model
{
    protected $fillable = [
        'procurement_id',
        'company_id',
        'farm_id',
        'lot_id',
        'date',
        'received_head',
        'po_price_per_kg',
        'po_price_per_head',
        'freight_allocated',
        'subtotal',
        'receipt_mode',
        'status',
        'inventory_transaction_id',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'date'              => 'date',
        'received_head'     => 'integer',
        'po_price_per_kg'   => 'decimal:4',
        'po_price_per_head' => 'decimal:2',
        'freight_allocated' => 'decimal:2',
        'subtotal'          => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRecord::class, 'procurement_id');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(Animal::class, 'receipt_batch_id');
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeForProcurement($query, int $procurementId)
    {
        return $query->where('procurement_id', $procurementId);
    }

    public function scopeUnmatched($query)
    {
        return $query->whereHas('inventoryTransaction', function ($q) {
            $q->whereNull('ap_invoice_id');
        });
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function getReceiptModeLabel(): string
    {
        return match($this->receipt_mode) {
            'bulk_avg'   => 'Bulk Average Weight',
            'bulk_range' => 'Bulk Per-Unit Weight',
            'individual' => 'Single Animal',
            default      => $this->receipt_mode,
        };
    }

    public function isMatched(): bool
    {
        return $this->inventoryTransaction?->ap_invoice_id !== null;
    }
}