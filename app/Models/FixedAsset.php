<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FixedAsset Model
//
//  Represents a single depreciable (or non-depreciable) asset
//  on the company's books.  Each asset links to three Chart of
//  Account rows and generates AssetDepreciationLine records
//  via DepreciationService (Phase 4 / Services layer).
//
//  net_book_value is a plain decimal column written explicitly by
//  the controller on every store() and postPeriod() call:
//    store()      → net_book_value = purchase_cost  (initial NBV)
//    postPeriod() → net_book_value = purchase_cost − accumulated_depreciation
//  It is NOT a MySQL generated column — it must be in $fillable.
// ══════════════════════════════════════════════════════════════════

class FixedAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fixed_assets';

    // ── Mass-assignable fields ────────────────────────────────────
    protected $fillable = [
        'company_id',
        'farm_id',
        'asset_code',
        'name_en',
        'name_ar',
        'category',
        'purchase_date',
        'in_service_date',
        'purchase_cost',
        'salvage_value',
        'depreciation_method',
        'useful_life_months',
        'db_rate',
        'accumulated_depreciation',  // updated by controller on each postPeriod()
        'net_book_value',            // ✅ FA-S2 FIX: written explicitly — NOT a generated column
        'asset_account_id',
        'acc_dep_account_id',
        'dep_expense_account_id',
        'status',
        'disposal_date',
        'disposal_proceeds',
        'disposal_journal_entry_id',
        'journal_entry_id',
        'notes',
    ];

    // ── Casts ─────────────────────────────────────────────────────
    protected $casts = [
        'purchase_date'              => 'date',
        'in_service_date'            => 'date',
        'disposal_date'              => 'date',
        'purchase_cost'              => 'decimal:2',
        'salvage_value'              => 'decimal:2',
        'disposal_proceeds'          => 'decimal:2',
        'accumulated_depreciation'   => 'decimal:2',
        'net_book_value'             => 'decimal:2',   // generated column, read-only
        'db_rate'                    => 'decimal:4',
        'useful_life_months'         => 'integer',
    ];

    // ── Ownership ─────────────────────────────────────────────────
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    // ── Chart of Accounts links ───────────────────────────────────
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'acc_dep_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'dep_expense_account_id');
    }

    // ── Journal entries ───────────────────────────────────────────
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function disposalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id');
    }

    // ── Depreciation lines ────────────────────────────────────────
    public function depreciationLines(): HasMany
    {
        return $this->hasMany(AssetDepreciationLine::class, 'asset_id');
    }

    public function postedDepreciationLines(): HasMany
    {
        return $this->hasMany(AssetDepreciationLine::class, 'asset_id')
                    ->where('is_posted', true)
                    ->orderBy('depreciation_date');
    }

    // ── Computed helpers ──────────────────────────────────────────

    /**
     * Depreciable base = purchase_cost - salvage_value
     * Used by DepreciationService for straight-line calculation.
     */
    public function getDepreciableBaseAttribute(): float
    {
        return (float) $this->purchase_cost - (float) $this->salvage_value;
    }

    /**
     * Monthly straight-line charge.
     * Returns 0 for non-depreciable assets or if useful_life_months is null.
     */
    public function getMonthlySlChargeAttribute(): float
    {
        if (
            $this->depreciation_method !== 'straight_line' ||
            ! $this->useful_life_months ||
            $this->useful_life_months <= 0
        ) {
            return 0.0;
        }

        return round($this->depreciable_base / $this->useful_life_months, 2);
    }

    /**
     * Whether the asset can still be depreciated this period.
     */
    public function isFullyDepreciated(): bool
    {
        return (float) $this->accumulated_depreciation >= (float) $this->depreciable_base;
    }

    /**
     * Remaining depreciable amount.
     */
    public function getRemainingDepreciableAttribute(): float
    {
        return max(0.0, $this->depreciable_base - (float) $this->accumulated_depreciation);
    }

    // ── Scopes ────────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDepreciable($query)
    {
        return $query->whereNotIn('depreciation_method', ['none'])
                     ->whereNotIn('status', ['disposed', 'fully_depreciated']);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}