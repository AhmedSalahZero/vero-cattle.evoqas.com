<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Farm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'address_en',
        'address_ar',
        'city',
        'region',
        'country',
        'latitude',
        'longitude',
        'phone',
        'email',
        'area_acres',
        'capacity_head',
        'notes_en',
        'notes_ar',
        'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'latitude'     => 'decimal:7',
        'longitude'    => 'decimal:7',
        'area_acres'   => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_farms')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

        public function animals()
    {
        return $this->hasMany(Animal::class);
    }
 
    public function lots()
    {
        return $this->hasMany(Lot::class);
    }
}