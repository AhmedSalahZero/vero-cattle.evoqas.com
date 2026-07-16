<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CalvingRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'dam_id',
        'calf_id',
        'breeding_record_id',
        'calving_date',
        'calving_time',
        'birth_weight_kg',
        'condition',
        'difficulty_score',
        'colostrum_fed',
        'notes',
    ];

    protected $casts = [
        'calving_date'    => 'date',
        'birth_weight_kg' => 'decimal:2',
        'colostrum_fed'   => 'boolean',
        'difficulty_score'=> 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function calf()
    {
        return $this->belongsTo(Animal::class, 'calf_id');
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function breedingRecord()
    {
        return $this->belongsTo(BreedingRecord::class);
    }
}