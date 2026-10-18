<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FeedConsumption Model
//
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
//  📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
//
//  SESSION 49 CHANGES — Migration 059:
//    — inventory_item_id + location_id replace feed_item_id + ration_id
//    — unit_cost now sourced from inventory stock row WAC
//    — journal_entry_id links to auto-posted T2 entry
//
//  SESSION 53 CHANGES — Migration 060:
//    — animals() BelongsToMany added via feed_consumption_animals pivot
//    — head_count is now SERVER-COMPUTED from count(animal_ids)
//      It is NO LONGER entered by the user — never trust a client value
//    — hasAnimalSelection() helper added for COGS routing
//
//  Retired fields (kept nullable for historical records only):
//    feed_item_id  — do not use for new records
//    ration_id     — do not use for new records
//    planned_qty_kg — ration concept retired
//    variance_kg    — ration concept retired
// ══════════════════════════════════════════════════════════════════

class FeedConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'lot_id',

        // ── New fields (Migration 059) ────────────────────────────
        'inventory_item_id',    // FK → inventory_items (master, category=feed)
        'location_id',          // FK → inventory_locations (explicit warehouse)

        // ── Retired fields (kept nullable for legacy records) ─────
        'feed_item_id',         // nullable — pre-059 records only
        'ration_id',            // nullable — pre-059 records only

        // ── Core consumption fields ───────────────────────────────
        'date',

        // head_count: SESSION 53 — now SERVER-COMPUTED as count(animal_ids)
        // from the feed_consumption_animals pivot table.
        // For legacy records (no pivot rows) this retains the original
        // user-entered snapshot value for backward-compatible COGS fallback.
        // NEVER populate this from user input on new records.
        'head_count',

        'qty_kg',
        'unit_cost',            // WAC from inventory stock row at time of consumption
        'total_cost',           // server-computed: qty_kg × unit_cost (bcmath, scale=4)

        // ── Retired calculation fields ────────────────────────────
        'planned_qty_kg',       // nullable — ration concept retired; not set on new records
        'variance_kg',          // nullable — ration concept retired; not set on new records

        // ── Accounting + audit ────────────────────────────────────
        'journal_entry_id',     // T2: Dr Livestock WIP (1310) / Cr Feed Inventory (1320)
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'date'           => 'date',
        'head_count'     => 'integer',
        'qty_kg'         => 'decimal:3',
        'unit_cost'      => 'decimal:4',
        'total_cost'     => 'decimal:2',
        'planned_qty_kg' => 'decimal:3',
        'variance_kg'    => 'decimal:3',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function lot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * The inventory item (category=feed) consumed.
     * Populated for all new records after Migration 059.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
     */
    public function inventoryItem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * The warehouse location the feed was consumed from.
     * Populated for all new records after Migration 059.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
     */
    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    /**
     * The T2 journal entry auto-posted on consumption.
     * Dr Livestock WIP (1310) [lot_id stamped] / Cr Feed Inventory (1320)
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
     */
    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The animals explicitly selected for this feeding session.
     *
     * Pivot table: feed_consumption_animals
     *   feed_consumption_id → feed_consumptions.id  (cascade delete)
     *   animal_id           → animals.id
     *
     * SESSION 53 — Migration 060.
     * This is the source of truth for head_count on new records.
     * head_count = count of rows in this pivot for this feed_consumption_id.
     *
     * 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
     */
    public function animals(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Animal::class,
            'feed_consumption_animals',
            'feed_consumption_id',
            'animal_id'
        );
    }

    /**
     * Legacy: feed item from the retired feed catalog.
     * Only populated on records created before Migration 059.
     * Do not use for new records or any business logic.
     *
     * @deprecated Use inventoryItem() for all new code.
     */
    public function feedItem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FeedItem::class);
    }

    /**
     * Legacy: ration from the retired ration module.
     * Only populated on records created before Migration 059.
     * Do not use for new records or any business logic.
     *
     * @deprecated Ration module retired — see Methodology #43.
     */
    public function ration(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ration::class);
    }

    public function recorder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether this record has an explicit animal selection in the pivot table.
     *
     * TRUE  → new-style record (Migration 060+)
     *          COGS uses: pivot lookup — animal gets share only if in pivot
     *
     * FALSE → legacy record (before Migration 060)
     *          COGS uses: total_cost / head_count applied to all animals
     *          in the lot during that date range via animal_lot_history
     *
     * 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
     */
    public function hasAnimalSelection(): bool
    {
        return $this->animals()->exists();
    }

    /**
     * Whether this record was created under the old feed catalog workflow.
     *
     * TRUE  → feed_item_id is set, inventory_item_id is null
     *          Created before Migration 059
     *          Uses legacy COGS path in LivestockIntelligenceService
     *
     * FALSE → inventory_item_id is set (new workflow)
     *          Uses current COGS path
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §13
     */
    public function isLegacy(): bool
    {
        return $this->inventory_item_id === null && $this->feed_item_id !== null;
    }
}