<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_en',
        'name_ar',
        'logo',
        'address_en',
        'address_ar',
        'country',
        'phone',
        'email',
        'website',
        'tax_number',
        'commercial_register',
        'currency',
        'fiscal_year_start_month',
        'accounting_mode',
        'is_active',
        // ── Subscription ─────────────────────────────────────────
        'subscription_starts_at',
        'subscription_ends_at',
        // ── User quota (added with migration add_max_users_to_companies_table)
        // null = unlimited | integer 1-999 = hard cap set by super admin
        'max_users',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'fiscal_year_start_month' => 'integer',
        // Cast to Carbon instances so ->isAfter() etc. work in the service
        'subscription_starts_at'  => 'date',
        'subscription_ends_at'    => 'date',
        // Cast to integer so comparisons in UserController never do string vs int
        // null stays null (unlimited) — only non-null values are cast to int
        'max_users'               => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function farms()
    {
        return $this->hasMany(Farm::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}