<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AssetDepreciationLine Model
//
//  One row = one period's depreciation charge for one asset.
//  Created in bulk by DepreciationService at the start of a
//  fiscal year (schedule generation) or run individually when
//  an accountant posts a specific period.
//
//  Lifecycle:
//    is_posted = false  → scheduled / pending
//    is_posted = true   → journal entry created, ledger updated
//
//  The unique index (asset_id, fiscal_year_id, period_month)
//  prevents double-posting.
// ══════════════════════════════════════════════════════════════════

class AssetDepreciationLine extends Model
{
    use HasFactory;

    protected $table = 'asset_depreciation_lines';

    // ── Mass-assignable fields ────────────────────────────────────
    protected $fillable = [
        'asset_id',
        'company_id',
        'fiscal_year_id',
        'period_month',
        'depreciation_date',
        'amount',
        'accumulated_to_date',
        'journal_entry_id',
        'is_posted',
        'posted_at',
        'posted_by',
        'notes',
    ];

    // ── Casts ─────────────────────────────────────────────────────
    protected $casts = [
        'depreciation_date'   => 'date',
        'amount'              => 'decimal:2',
        'accumulated_to_date' => 'decimal:2',
        'is_posted'           => 'boolean',
        'posted_at'           => 'datetime',
        'period_month'        => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    // ── Scopes ────────────────────────────────────────────────────
    public function scopePosted($query)
    {
        return $query->where('is_posted', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_posted', false);
    }

    public function scopeForPeriod($query, int $fiscalYearId, int $month)
    {
        return $query->where('fiscal_year_id', $fiscalYearId)
                     ->where('period_month', $month);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}