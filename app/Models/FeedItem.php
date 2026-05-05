<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'type',
        'unit',
        'unit_cost',
        'cp_pct',
        'tdn_pct',
        'dm_pct',
        'ndf_pct',
        'me_mj',
        'supplier_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'cp_pct'    => 'decimal:2',
        'tdn_pct'   => 'decimal:2',
        'dm_pct'    => 'decimal:2',
        'ndf_pct'   => 'decimal:2',
        'me_mj'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function rationLines()
    {
        return $this->hasMany(RationLine::class);
    }

    public function feedConsumptions()
    {
        return $this->hasMany(FeedConsumption::class);
    }

    public function inventoryItem()
    {
        return $this->hasOne(InventoryItem::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}