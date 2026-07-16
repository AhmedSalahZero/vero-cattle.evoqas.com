<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// ══════════════════════════════════════════════════════════════════
//  InventoryItem — Two row types on the same table:
//
//  MASTER ROW  (is_master=true, location_id=NULL, master_item_id=NULL)
//    Carries: name_en, name_ar, sku, category, unit, feed_item_id
//    One per company per distinct item.
//
//  LOCATION-STOCK ROW  (is_master=false, location_id set, master_item_id set)
//    Carries: qty_on_hand, unit_cost, reorder_point
//    Auto-created for every active location when master is created.
//    name/category/unit are NULL — always read from master.
// ══════════════════════════════════════════════════════════════════

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',          // Nullable — set on location-stock rows (derived from location)
        'location_id',      // NULL on master rows
        'master_item_id',   // NULL on master rows; FK on location-stock rows
        'is_master',
        'name_en',          // Only on master rows
        'name_ar',          // Only on master rows
        'sku',              // Only on master rows
        'category',         // Only on master rows
        'unit',             // Only on master rows
        'unit_cost',        // Per location (WAC differs per location)
        'qty_on_hand',      // Per location
        'reorder_point',    // Per location
        'feed_item_id',     // Only on master rows
        'is_active',
        'notes',
    ];

    protected $casts = [
        'unit_cost'     => 'decimal:4',
        'qty_on_hand'   => 'decimal:3',
        'reorder_point' => 'decimal:3',
        'is_active'     => 'boolean',
        'is_master'     => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    // Master row → its location-stock rows
    public function locationStocks()
    {
        return $this->hasMany(InventoryItem::class, 'master_item_id');
    }

    // Location-stock row → its master
    public function master()
    {
        return $this->belongsTo(InventoryItem::class, 'master_item_id');
    }

    public function feedItem()
    {
        return $this->belongsTo(FeedItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id');
    }

    /**
     * PO item lines that reference this master inventory item.
     * Only meaningful on master rows (is_master = true).
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    public function procurementItemLines()
    {
        return $this->hasMany(ProcurementItemLine::class, 'inventory_item_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeMasters($query)
    {
        return $query->where('is_master', true);
    }

    public function scopeLocationStocks($query)
    {
        return $query->where('is_master', false);
    }

    // ── Computed ──────────────────────────────────────────────────

    public function getIsLowStockAttribute(): bool
    {
        if (!$this->reorder_point) return false;
        return $this->qty_on_hand <= $this->reorder_point;
    }

    public function getIsOutOfStockAttribute(): bool
    {
        return $this->qty_on_hand <= 0;
    }

    // Total qty across all location-stock rows (used on master row)
    public function getTotalQtyAttribute(): float
    {
        if (!$this->is_master) return (float) $this->qty_on_hand;
        return (float) $this->locationStocks->sum('qty_on_hand');
    }

    // Resolved name — works on both master and location-stock rows
    public function getResolvedNameEnAttribute(): ?string
    {
        return $this->is_master
            ? $this->name_en
            : ($this->master?->name_en ?? $this->name_en);
    }

    public function getResolvedNameArAttribute(): ?string
    {
        return $this->is_master
            ? $this->name_ar
            : ($this->master?->name_ar ?? $this->name_ar);
    }
}