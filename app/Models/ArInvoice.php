<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ArInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'journal_entry_id',
        'date',
        'due_date',
        'reference',
        'description',
        'total',
        'paid',
        'balance',
        'currency',
        'exchange_rate',  // ← ADD
        'status',
        'notes',
    ];

    protected $casts = [
        'date'     => 'date',
        'due_date' => 'date',
        'total'    => 'decimal:2',
        'paid'     => 'decimal:2',
        'balance'  => 'decimal:2',
        'exchange_rate'  => 'decimal:6',  // ← ADD
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'ar_invoice_id');
    }

    public function salesRecord()
    {
        return $this->hasOne(SalesRecord::class, 'ar_invoice_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'partially_paid', 'overdue']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }
}