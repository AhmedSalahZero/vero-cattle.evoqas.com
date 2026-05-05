<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalesRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',
        'customer_id',
        'date',
        'invoice_ref',
        'subtotal',
        'discount',
        'total_amount',
        'currency',
        'payment_terms_days',
        'due_date',
        'status',
        'journal_entry_id',
        'ar_invoice_id',
        'notes',
    ];

    protected $casts = [
        'date'               => 'date',
        'due_date'           => 'date',
        'subtotal'           => 'decimal:2',
        'discount'           => 'decimal:2',
        'total_amount'       => 'decimal:2',
        'payment_terms_days' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesLine::class, 'sale_id');
    }

    /**
     * The journal entry posted when this sale is confirmed.
     * Dr AR (1200) + Dr COGS (5100) / Cr Revenue (4100) + Cr Livestock Inventory (1300)
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The AR invoice generated on confirmation.
     * Links this sale to the receivables ledger.
     */
    public function arInvoice()
    {
        return $this->belongsTo(ArInvoice::class);
    }
}