<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory, SoftDeletes; 

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'date',
        'reference',
        'description',
        'period',
        'status',
        'source_type',
        'source_id',
        'entry_type',
        'total_debit',
        'total_credit',
        'posted_by',
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'date'         => 'date',
        'posted_at'    => 'datetime',
        'period'       => 'integer',
        'total_debit'  => 'decimal:2',
        'total_credit' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    // ── Polymorphic source ────────────────────────────────────────

    public function source()
    {
        return $this->morphTo('source');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
}