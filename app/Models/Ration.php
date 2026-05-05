<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Ration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'lot_id',
        'name',
        'effective_date',
        'is_active',
        'cost_per_head_day',
        'total_cp_pct',
        'total_tdn_pct',
        'total_dm_kg',
        'notes',
    ];

    protected $casts = [
        'effective_date'    => 'date',
        'is_active'         => 'boolean',
        'cost_per_head_day' => 'decimal:4',
        'total_cp_pct'      => 'decimal:2',
        'total_tdn_pct'     => 'decimal:2',
        'total_dm_kg'       => 'decimal:3',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function lines()
    {
        return $this->hasMany(RationLine::class);
    }

    public function feedConsumptions()
    {
        return $this->hasMany(FeedConsumption::class);
    }
}