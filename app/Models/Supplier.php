<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Supplier Model  (updated Session 9)
//
//  Partner link addition:
//    is_customer = true  → this supplier is also a customer
//    linkedCustomer()    → the Customer record that links back here
//
//  Add 'is_customer' to $fillable and $casts.
//  Add linkedCustomer() HasOne relationship.
//  All other fields unchanged from Session 8.
// ══════════════════════════════════════════════════════════════════

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'code',
        'type',
        'contact_person',
        'phone',
        'email',
        'website',
        'address_en',
        'address_ar',
        'city',
        'country',
        'tax_number',
        'bank_name',
        'bank_account',
        'payment_terms_days',
        'odoo_partner_id',
        'is_active',
        'is_customer',   // ← partner link flag (added Session 9)
        'notes',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'is_customer'        => 'boolean',  // ← added Session 9
        'payment_terms_days' => 'integer',
        'odoo_partner_id'    => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apInvoices(): HasMany
    {
        return $this->hasMany(ApInvoice::class);
    }

    public function procurementRecords(): HasMany
    {
        return $this->hasMany(ProcurementRecord::class);
    }

    public function animals(): HasMany
    {
        return $this->hasMany(Animal::class);
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(FeedItem::class);
    }

    /**
     * The linked Customer record when is_customer = true.
     * Added Session 9 — partner link pattern.
     */
    public function linkedCustomer(): HasOne
    {
        return $this->hasOne(Customer::class);
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

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function displayName(string $locale = 'en'): string
    {
        if ($locale === 'ar') {
            return $this->name_ar ?? $this->name_en ?? '';
        }
        return $this->name_en ?? $this->name_ar ?? '';
    }
}