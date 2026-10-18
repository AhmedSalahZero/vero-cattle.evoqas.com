<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FiscalYear extends Model
{
    use HasFactory;

   protected $fillable = [
    'company_id',
    'name',
    'year',
    'start_date',
    'end_date',
    'status',
    'closed_at',
    'closed_by',
    ];

    protected $casts = [
        'year'       => 'integer',
        'start_date' => 'date',
        'end_date'   => 'date',
        'closed_at'  => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}