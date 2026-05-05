<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  VERO — FarmCostAllocation Model
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//
//  One record per farm per calendar month. Carries:
//    - Labor estimate / actual / variance
//    - Links to month-end accrual JE and settlement JE
//    - total_animal_days and rate_per_animal_day stored at post time
//    - Overhead detail in child farm_overhead_lines rows
//
//  Status machine: draft → posted → settled → locked
//
//  Unique constraint: farm_id + year + month (DB-enforced)
// ══════════════════════════════════════════════════════════════════

class FarmCostAllocation extends Model
{
    protected $fillable = [
        'company_id',
        'farm_id',
        'fiscal_year_id',
        'month',
        'year',
        'month_start',
        'month_end',
        'labor_estimated',
        'labor_actual',
        'labor_variance',
        'labor_journal_entry_id',
        'labor_settlement_je_id',
        'overhead_journal_entry_id',
        'overhead_settlement_je_id',
        'total_animal_days',
        'rate_per_animal_day',
        'status',
        'created_by',
        'posted_by',
        'settled_by',
        'posted_at',
        'settled_at',
        'notes',
    ];

    protected $casts = [
        'month'              => 'integer',
        'year'               => 'integer',
        'month_start'        => 'date',
        'month_end'          => 'date',
        'labor_estimated'    => 'decimal:2',
        'labor_actual'       => 'decimal:2',
        'labor_variance'     => 'decimal:2',
        'total_animal_days'  => 'integer',
        'rate_per_animal_day'=> 'decimal:8',
        'posted_at'          => 'datetime',
        'settled_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function overheadLines(): HasMany
    {
        return $this->hasMany(FarmOverheadLine::class, 'farm_cost_allocation_id');
    }

    public function laborJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'labor_journal_entry_id');
    }

    public function laborSettlementJe(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'labor_settlement_je_id');
    }

    public function overheadJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'overhead_journal_entry_id');
    }

    public function overheadSettlementJe(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'overhead_settlement_je_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    // ── Status Helpers ────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function canPost(): bool
    {
        return $this->status === 'draft'
            && $this->labor_journal_entry_id === null;
    }

    public function canSettle(): bool
    {
        return $this->status === 'posted'
            && $this->labor_settlement_je_id === null;
    }

    // ── Computed Helpers ──────────────────────────────────────────

    /**
     * Total estimated overhead across all overhead lines.
     */
    public function totalOverheadEstimated(): string
    {
        return (string) $this->overheadLines->sum('estimated_amount');
    }

    /**
     * Total actual overhead across all overhead lines (if settled).
     */
    public function totalOverheadActual(): ?string
    {
        if ($this->status === 'draft' || $this->status === 'posted') {
            return null;
        }
        return (string) $this->overheadLines->sum('actual_amount');
    }

    /**
     * Human-readable month label e.g. "January 2026"
     */
    public function monthLabel(): string
    {
        return \Carbon\Carbon::createFromDate($this->year, $this->month, 1)
            ->format('F Y');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForCompany($query, int $companyId): mixed
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForFarm($query, int $farmId): mixed
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeDraft($query): mixed
    {
        return $query->where('status', 'draft');
    }

    public function scopePosted($query): mixed
    {
        return $query->where('status', 'posted');
    }

    public function scopePendingPost($query): mixed
    {
        return $query->where('status', 'draft');
    }

    public function scopeForMonth($query, int $year, int $month): mixed
    {
        return $query->where('year', $year)->where('month', $month);
    }
}