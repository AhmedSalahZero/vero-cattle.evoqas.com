<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Customer Model
//
//  Scope:  company_id (company-level, no farm_id)
//
//  Partner link:
//    is_supplier = true  → this customer is also a supplier
//    supplier_id         → FK to suppliers table
//    The linked Supplier will have is_customer = true
//
//  Phase 5 note:
//    odoo_partner_id populated by OdooAccountingDriver::syncCustomer()
//    Both supplier and customer share the same Odoo res.partner record
//    identified via supplier_id → suppliers.odoo_partner_id
//
//  Downstream relationships:
//    - ar_invoices   (Accounts Receivable)
//    - salesRecords
// ══════════════════════════════════════════════════════════════════

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'code',
        'contact_person',
        'phone',
        'email',
        'address_en',
        'address_ar',
        'city',
        'country',
        'tax_number',
        'payment_terms_days',
        'is_active',
        'is_supplier',
        'supplier_id',
        'odoo_partner_id',
        'notes',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'is_supplier'        => 'boolean',
        'payment_terms_days' => 'integer',
        'odoo_partner_id'    => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The linked Supplier record when is_supplier = true */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function arInvoices(): HasMany
    {
        return $this->hasMany(ArInvoice::class);
    }

    public function salesRecords(): HasMany
    {
        return $this->hasMany(SalesRecord::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function displayName(string $locale = 'en'): string
    {
        if ($locale === 'ar') {
            return $this->name_ar ?? $this->name_en ?? '';
        }
        return $this->name_en ?? $this->name_ar ?? '';
    }

    public function outstandingBalance(): float
    {
        return (float) $this->arInvoices()
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->sum('balance');
    }
}