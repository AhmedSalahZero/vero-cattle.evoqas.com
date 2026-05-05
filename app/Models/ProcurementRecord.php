<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ProcurementRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'procurement_records';

    protected $fillable = [
        'company_id',
        'po_type',                  // 'cattle' (default) or 'inventory' (Migration 061)
        'farm_id',
        'supplier_id',
        'lot_id',
        'date',
        'po_reference',             // Auto-generated on confirmPo() — PO/CO3/YYYY/MM/NNN
        'invoice_ref',
        // ── Head count fields ─────────────────────────────────────
        'head_count',               // Legacy — kept for backward compat (= max_head)
        'min_head',                 // Minimum contracted head count (Migration 056)
        'max_head',                 // Maximum contracted head count (Migration 056)
        'received_head',            // Running total confirmed received (Migration 056)
        // ── PO price estimates ────────────────────────────────────
        'estimated_price_per_kg',   // Locked into each receipt batch (Migration 056)
        'estimated_avg_weight_kg',  // Expected average weight (Migration 056)
        // ── Financial totals ──────────────────────────────────────
        'subtotal',
        'freight_cost',
        'other_costs',
        'total_amount',
        'currency',
        'payment_terms_days',
        'due_date',
        // ── Status ───────────────────────────────────────────────
        'status',                   // Legacy payment-progress status (kept for compat)
        'po_status',                // Receipt-progress status (Migration 056)
        // ── Force-close audit ─────────────────────────────────────
        'closure_reason',           // Mandatory when force_closed (Migration 056)
        'closed_at',                // Timestamp of force close (Migration 056)
        'closed_by',                // User who force-closed (Migration 056)
        // ── Accounting links ──────────────────────────────────────
        'journal_entry_id',
        'ap_invoice_id',            // Deprecated under new flow — kept for legacy compat
        // ── Notes ─────────────────────────────────────────────────
        'notes',
    ];

    protected $casts = [
        'date'                    => 'datetime:Y-m-d',
        'due_date'                => 'datetime:Y-m-d',
        'closed_at'               => 'datetime',
        // ── po_type intentionally NOT cast ────────────────────────
        // Kept as plain string for direct === comparisons in service
        // layer ('cattle' / 'inventory').
        // ── Integers ──────────────────────────────────────────────
        // CRITICAL: must be cast to integer.
        // Without this, received_head += $batchCount in LivestockReceiptService
        // performs string concatenation instead of integer addition.
        'head_count'              => 'integer',
        'min_head'                => 'integer',
        'max_head'                => 'integer',
        'received_head'           => 'integer',
        'payment_terms_days'      => 'integer',
        // ── Decimals ──────────────────────────────────────────────
        'estimated_price_per_kg'  => 'decimal:4',
        'estimated_avg_weight_kg' => 'decimal:2',
        'subtotal'                => 'decimal:2',
        'freight_cost'            => 'decimal:2',
        'other_costs'             => 'decimal:2',
        'total_amount'            => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * Cattle PO breed/sex/weight estimate lines.
     * These are optional reference rows — NOT individual animals.
     * Animals are created via receiptBatches() → animals() chain.
     * Only populated on cattle POs (po_type = 'cattle').
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProcurementLine::class, 'procurement_id');
    }

    /**
     * All delivery batches received against this cattle PO.
     * One batch per physical delivery (partial deliveries allowed).
     * Each batch creates animal rows and one livestock_receipt inventory transaction.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
     */
    public function receiptBatches(): HasMany
    {
        return $this->hasMany(AnimalReceiptBatch::class, 'procurement_id');
    }

    /**
     * All animals received against this cattle PO (through receipt batches).
     * Convenience accessor — goes through the receipt batch chain.
     */
    public function animals(): HasManyThrough
    {
        return $this->hasManyThrough(
            Animal::class,
            AnimalReceiptBatch::class,
            'procurement_id',   // FK on animal_receipt_batches
            'receipt_batch_id', // FK on animals
            'id',               // local key on procurement_records
            'id'                // local key on animal_receipt_batches
        );
    }

    /**
     * Ordered item lines for inventory POs (po_type = 'inventory').
     * One row per unique (inventory_item_id, location_id) per PO.
     * Always null on cattle POs — check po_type before accessing.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    public function itemLines(): HasMany
    {
        return $this->hasMany(ProcurementItemLine::class, 'procurement_id');
    }

    /**
     * All inventory receipt batches against this inventory PO.
     * One batch per physical delivery event (partial deliveries allowed).
     * Each batch posts one T1 journal entry: Dr Inventory / Cr AP Accruals.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    public function inventoryReceiptBatches(): HasMany
    {
        return $this->hasMany(InventoryReceiptBatch::class, 'procurement_id');
    }

    /**
     * The journal entry posted when this procurement was confirmed.
     * Legacy — under the new flow, journal entries are posted per receipt batch.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @deprecated Under the new flow (Migration 056), AP invoices link through
     * the receipt batch chain:
     *   procurement_records → animal_receipt_batches
     *       → inventory_transactions → ap_invoices
     *
     * This direct FK is kept for backward compatibility only.
     * New receipts do NOT populate ap_invoice_id on procurement_records.
     * Use ProcurementController::show() linked invoices query instead.
     */
    public function apInvoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether this is a cattle PO (livestock receipt flow).
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
     */
    public function isCattlePo(): bool
    {
        return $this->po_type === 'cattle';
    }

    /**
     * Whether this is an inventory items PO (inventory receipt flow).
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    public function isInventoryPo(): bool
    {
        return $this->po_type === 'inventory';
    }

    /**
     * Whether this PO is currently open for receiving.
     * Applies to BOTH cattle and inventory POs — same status machine.
     */
    public function isOpenForReceipt(): bool
    {
        return in_array($this->po_status, [
            'open', 'partially_received', 'on_track', 'over_received'
        ]);
    }

    /**
     * Whether the cattle PO has exceeded its maximum head count.
     * Only meaningful on cattle POs.
     */
    public function isOverReceived(): bool
    {
        return $this->received_head > $this->max_head;
    }

    /**
     * How many more animals can be received before hitting max_head.
     * Returns 0 if already at or above max_head.
     * Only meaningful on cattle POs.
     */
    public function remainingHead(): int
    {
        return max(0, $this->max_head - $this->received_head);
    }
}