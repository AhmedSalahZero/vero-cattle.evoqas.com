<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'supplier_id',
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
        'invoice_type',
        'expense_account_id',
        'notes',
    ];

    protected $casts = [
        'date'     => 'date',
        'due_date' => 'date',
        'total'    => 'decimal:2',
        'paid'     => 'decimal:2',
        'balance'  => 'decimal:2',
        'invoice_type' => 'string',
        'exchange_rate'  => 'decimal:6',  // ← ADD
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'ap_invoice_id');
    }

    public function procurementRecord()
    {
        return $this->hasOne(ProcurementRecord::class, 'ap_invoice_id');
    }
    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
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