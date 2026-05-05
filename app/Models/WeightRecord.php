<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WeightRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'animal_id',
        'lot_id',
        'date',
        'weight_kg',
        'scale_reference',
        'session_ref',
        'adg_kg',
        'total_gain_kg',
        'days_on_feed',
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'date'          => 'date',
        'weight_kg'     => 'decimal:2',
        'adg_kg'        => 'decimal:3',
        'total_gain_kg' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}