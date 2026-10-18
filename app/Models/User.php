<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'company_id',
        'name',
        'name_en',
        'name_ar',
        'email',
        'password',
        'phone',
        'avatar',
        'job_title_en',
        'job_title_ar',
        'language',
        'theme',
        'is_active',
        'is_super_admin',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'is_super_admin'    => 'boolean',
        'password'          => 'hashed',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function farms()
    {
        return $this->belongsToMany(Farm::class, 'user_farms')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    public function primaryFarm()
    {
        return $this->farms()->wherePivot('is_primary', true)->first();
    }
}