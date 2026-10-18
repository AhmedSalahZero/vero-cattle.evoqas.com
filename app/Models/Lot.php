<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Lot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',
        'name',
        'lot_number',
        'start_date',
        'target_sale_date',   // renamed from target_slaughter_date — Migration 071
        'closed_date',
        'pen',
        'status',
        'notes_en',
        'notes_ar',
    ];

    protected $casts = [
        'start_date'       => 'date',
        'target_sale_date' => 'date',   // renamed from target_slaughter_date — Migration 071
        'closed_date'      => 'date',
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

    public function animals()
    {
        return $this->hasMany(Animal::class);
    }

    public function weightRecords()
    {
        return $this->hasMany(WeightRecord::class);
    }

    public function healthRecords()
    {
        return $this->hasMany(HealthRecord::class);
    }

    public function feedConsumptions()
    {
        return $this->hasMany(FeedConsumption::class);
    }

    public function rations()
    {
        return $this->hasMany(Ration::class);
    }

    public function procurementRecords()
    {
        return $this->hasMany(ProcurementRecord::class);
    }

    public function slaughterRecords()
    {
        return $this->hasMany(SlaughterRecord::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}