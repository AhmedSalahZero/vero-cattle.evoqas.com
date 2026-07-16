<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryLocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',      // NULL = company-level central location
        'name_en',
        'name_ar',
        'type',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    // All stock rows at this location
    public function stockItems()
    {
        return $this->hasMany(InventoryItem::class, 'location_id');
    }

    // Transactions at this location
    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'location_id');
    }

    /**
     * PO item lines that target this location for delivery.
     * Used to check if a location has pending deliveries.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
     */
    public function procurementItemLines()
    {
        return $this->hasMany(ProcurementItemLine::class, 'location_id');
    }

    // ── Computed ──────────────────────────────────────────────────

    // Display label: "Farm A — Medicine Store" or "Central — Main Warehouse"
    public function getDisplayLabelAttribute(): string
    {
        $farmPart = $this->farm
            ? ($this->farm->name_en ?? $this->farm->name_ar ?? 'Farm')
            : 'Central';

        $namePart = $this->name_en ?? $this->name_ar ?? '—';

        return "{$farmPart} — {$namePart}";
    }

    public function getDisplayLabelArAttribute(): string
    {
        $farmPart = $this->farm
            ? ($this->farm->name_ar ?? $this->farm->name_en ?? 'مزرعة')
            : 'مركزي';

        $namePart = $this->name_ar ?? $this->name_en ?? '—';

        return "{$farmPart} — {$namePart}";
    }
}