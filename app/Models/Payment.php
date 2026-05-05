<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\PaymentAllocation;
use App\Models\Account;

class Payment extends Model
{
    use HasFactory, SoftDeletes; 

   protected $fillable = [
    'company_id', 'type', 'payment_category', 'sub_type', 'sub_type_comment',
    'payee_name', 'ap_invoice_id', 'ar_invoice_id', 'journal_entry_id',
    'date', 'amount', 'currency', 'exchange_rate', 'method',
    'bank_name', 'bank_account_id',
    'check_number', 'check_bank', 'check_drawn_on_acct', 'check_status',
    'check_received_date', 'check_sent_to_bank_date', 'check_collection_date',
    'check_rejection_date', 'recollection_bank_name',
    'expense_account_id', 'reversal_journal_entry_id',
    'reference', 'notes',
    ];

   protected $casts = [
    'date'                    => 'date',
    'check_received_date'     => 'date',
    'check_sent_to_bank_date' => 'date',
    'check_collection_date'   => 'date',
    'check_rejection_date'    => 'date',
    'amount'                  => 'decimal:2',
    'exchange_rate'           => 'decimal:6',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function apInvoice()
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id');
    }

    public function arInvoice()
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
    public function bankAccount()
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeIncoming($query)
    {
        return $query->where('type', 'incoming');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('type', 'outgoing');
    }
}