<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vaccine extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'manufacturer',
        'disease_target',
        'dose_ml',
        'route',
        'withdrawal_days',
        'storage_conditions',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'dose_ml'         => 'decimal:2',
        'withdrawal_days' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class);
    }
}