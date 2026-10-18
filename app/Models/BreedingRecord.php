<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BreedingRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'dam_id',
        'sire_id',
        'method',
        'breeding_date',
        'expected_calving_date',
        'pregnancy_status',
        'confirmation_date',
        'notes',
    ];

    protected $casts = [
        'breeding_date'         => 'date',
        'expected_calving_date' => 'date',
        'confirmation_date'     => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire()
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function calvingRecord()
    {
        return $this->hasOne(CalvingRecord::class, 'breeding_record_id');
    }
}