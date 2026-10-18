<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTask extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'title',
        'description',
        'priority',
        'status',
        'expected_start_date',
        'expected_duration_days',
        'expected_end_date',
        'actual_start_date',
        'actual_duration_days',
        'actual_end_date',
        'reminder_enabled',
        'completion_notes',
    ];

    protected $casts = [
        'expected_start_date' => 'date',
        'expected_end_date'   => 'date',
        'actual_start_date'   => 'date',
        'actual_end_date'     => 'date',
        'reminder_enabled'    => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Computed Attributes ───────────────────────────────────────

    // Is the task overdue?
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) return false;
        if (!$this->expected_end_date) return false;
        return $this->expected_end_date->isPast();
    }

    // Is due today?
    public function getIsDueTodayAttribute(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) return false;
        if (!$this->expected_end_date) return false;
        return $this->expected_end_date->isToday();
    }

    // Delay in days (actual vs expected end)
    public function getDelayDaysAttribute(): ?int
    {
        if (!$this->actual_end_date || !$this->expected_end_date) return null;
        return $this->expected_end_date->diffInDays($this->actual_end_date, false);
    }
}