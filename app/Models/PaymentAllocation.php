<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation (#46)
//
// Pivot row linking one payment to one invoice with a specific allocated amount.
// A payment may have many allocations (one per invoice settled).

class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'ar_invoice_id',
        'ap_invoice_id',
        'allocated_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'float',
        'ar_invoice_id'    => 'integer',
        'ap_invoice_id'    => 'integer',
        'payment_id'       => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function arInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class);
    }

    public function apInvoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class);
    }
    
}