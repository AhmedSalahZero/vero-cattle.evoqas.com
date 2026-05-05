<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BankStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'account_id',
        'matched_entry_id',
        'date',
        'description',
        'amount',
        'reference',
        'is_matched',
        'is_reconciled',
    ];

    protected $casts = [
        'date'          => 'date',
        'amount'        => 'decimal:2',
        'is_matched'    => 'boolean',
        'is_reconciled' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function matchedEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'matched_entry_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeUnmatched($query)
    {
        return $query->where('is_matched', false);
    }

    public function scopeUnreconciled($query)
    {
        return $query->where('is_reconciled', false);
    }
}