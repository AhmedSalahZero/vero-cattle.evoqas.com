<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Animal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',
        'lot_id',
        'receipt_batch_id',         // FK → animal_receipt_batches (Migration 056)
        'tag',
        'rfid',
        'qr_code',
        'breed',
        'dob',
        'dob_estimated',
        'sex',
        'color',
        'markings',
        'source',
        'dam_id',
        'sire_id',
        'purchase_date',
        'supplier_id',
        'purchase_price',
        'po_price_per_kg',          // PO price snapshot — immutable after receipt (Migration 056)
        'purchase_weight_kg',
        'freight_cost',
        'photo',
        'status',
        'status_date',
        'notes',
    ];

    protected $casts = [
        'dob'                => 'date',
        'purchase_date'      => 'date',
        'status_date'        => 'date',
        'dob_estimated'      => 'boolean',
        'purchase_price'     => 'decimal:2',
        'po_price_per_kg'    => 'decimal:4',
        'purchase_weight_kg' => 'decimal:2',
        'freight_cost'       => 'decimal:2',
    ];

    // Valid status values — updated to include 'reserved' (Migration 049)
    // active      → available for sale, slaughter, health events
    // reserved    → held by a draft sale — hidden from new sale selectors
    // sold        → confirmed sale completed
    // slaughtered → slaughter record exists
    // dead        → mortality recorded
    // quarantine  → health quarantine
    // transferred → moved to another farm/company
    public const STATUSES = [
        'active', 'reserved', 'sold', 'slaughtered', 'dead', 'quarantine', 'transferred',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function dam(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }

    public function offspring(): HasMany
    {
        return $this->hasMany(Animal::class, 'dam_id');
    }

    public function weightRecords(): HasMany
    {
        return $this->hasMany(WeightRecord::class)->orderBy('date');
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(HealthRecord::class)->orderByDesc('date');
    }

    public function vaccinationRecords(): HasMany
    {
        return $this->hasMany(VaccinationRecord::class)->orderByDesc('date');
    }

    public function breedingRecords(): HasMany
    {
        return $this->hasMany(BreedingRecord::class, 'dam_id')->orderByDesc('breeding_date');
    }

    public function calvingRecords(): HasMany
    {
        return $this->hasMany(CalvingRecord::class, 'dam_id')->orderByDesc('calving_date');
    }

    public function slaughterRecord(): HasOne
    {
        return $this->hasOne(SlaughterRecord::class);
    }

    public function salesLines(): HasMany
    {
        return $this->hasMany(SalesLine::class);
    }

    /**
     * The receipt batch this animal was created from.
     * NULL for born_on_farm animals.
     * NULL for legacy purchased animals created before Migration 056.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
     * Traceability chain: receiptBatch → procurement (PO) → supplier → AP invoice
     */
    public function receiptBatch(): BelongsTo
    {
        return $this->belongsTo(AnimalReceiptBatch::class, 'receipt_batch_id');
    }

    // ── NOTE: procurementLine() relationship removed ──────────────
    // The old hasOne(ProcurementLine::class) relied on animal_id on
    // procurement_lines — a column that was always NULL in practice.
    // Under Migration 056, procurement_lines are pure PO estimate rows
    // and no longer reference individual animals.
    // Traceability is now: animal → receiptBatch() → procurement()
    // ─────────────────────────────────────────────────────────────

    // ── Lot History Relationships ─────────────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
    //
    // lotHistory() is the complete audit trail of every lot this
    // animal has ever been in.
    //
    // currentLotHistory() is the single open row — the animal's
    // current lot membership. Used when transferring or closing
    // the animal's lot period on sale/slaughter/death.
    //
    // IMPORTANT: animals.lot_id is a denormalized convenience cache.
    // For any date-sensitive calculation (COGS feed allocation),
    // always query through lotHistory(), never animals.lot_id alone.

    /**
     * Full lot history — all periods, ordered chronologically.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
     */
    public function lotHistory(): HasMany
    {
        return $this->hasMany(AnimalLotHistory::class)->orderBy('entered_at');
    }

    /**
     * The current open lot membership — exactly one row with exited_at IS NULL.
     * Returns NULL if the animal has no lot assigned or has been sold/slaughtered.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
     */
    public function currentLotHistory(): HasOne
    {
        return $this->hasOne(AnimalLotHistory::class)->whereNull('exited_at');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Animals available for operational selection (health records, vaccination,
     * breeding, calvings, weight records, slaughter).
     * Does NOT include 'reserved' — those are pending sales.
     */
    public function scopeOperational($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Animals available for sale creation — includes 'active' only.
     * 'reserved' animals are excluded (they belong to another draft sale).
     * The sales controller handles showing reserved animals that belong
     * to the CURRENT sale being edited via the exclude_sale_id param.
     */
    public function scopeAvailableForSale($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInLot($query, $lotId)
    {
        return $query->where('lot_id', $lotId);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Returns the best available entry date for lot history.
     * Used by AnimalController when creating the first lot history row.
     *
     * Priority: purchase_date → dob → created_at date
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
     */
    public function getLotEntryDate(): string
    {
        return ($this->purchase_date ?? $this->dob ?? $this->created_at)
            ->format('Y-m-d');
    }
}