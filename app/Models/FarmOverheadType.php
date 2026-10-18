<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  VERO — FarmOverheadType Model
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §4
//
//  Company-level reference table for overhead cost categories.
//  Examples: Electricity, Water, Fuel, Insurance, Rent.
//
//  Each type maps to:
//    accrued_account_id → liability account under 2270 group
//    variance_account_id → expense account under 6320 group
//
//  These mappings drive the automatic journal entry line generation
//  in FarmCostAllocationService::postMonthEndAccrual().
// ══════════════════════════════════════════════════════════════════

class FarmOverheadType extends Model
{
    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'accrued_account_id',
        'variance_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accruedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accrued_account_id');
    }

    public function varianceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'variance_account_id');
    }

    public function overheadLines(): HasMany
    {
        return $this->hasMany(FarmOverheadLine::class, 'overhead_type_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query): mixed
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany($query, int $companyId): mixed
    {
        return $query->where('company_id', $companyId);
    }
}
