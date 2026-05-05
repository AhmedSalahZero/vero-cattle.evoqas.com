<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — VaccinationRecord Model
//
//  📌 METHODOLOGY REFERENCE: VaccinationBatchStore (#18)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
//
//  SESSION 49 CHANGES — Migration 059:
//
//  The vaccination record now binds directly to an inventory item
//  (category=vaccine) at a specific warehouse location, replacing
//  the old vaccine_id catalog architecture.
//
//  New fields:
//    inventory_item_id — FK → inventory_items (category=vaccine)
//    location_id       — FK → inventory_locations (explicit warehouse)
//    journal_entry_id  — T4 journal entry write-back
//
//  Retired fields (kept nullable for historical records):
//    vaccine_id    — was required; now nullable; do not use for new records
//    dose_given_ml — was free-text ml; superseded by dose on inventory item
//
//  Cost source change:
//    OLD: cost entered manually by user — no link to physical vaccine stock
//    NEW: cost auto-computed from inventory stock row WAC × dose
//         — reflects the actual cost of the vaccine vials consumed
//
//  Inventory impact (new):
//    VaccinationController decrements the location-specific stock row
//    by dose_given_ml (or dose unit from item) and writes an
//    InventoryTransaction of type=consumption.
//
//  Accounting impact (new):
//    journal_entry_id links to the T4 auto-posted entry:
//    Dr Livestock WIP (1310) / Cr Vaccine Inventory (1330)
//    For batch vaccinations: one JE for total batch cost.
// ══════════════════════════════════════════════════════════════════

class VaccinationRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'farm_id',
        'animal_id',
        'lot_id',
        // ── New fields (Migration 059) ────────────────────────────
        'inventory_item_id',    // direct FK → inventory_items (category=vaccine)
        'location_id',          // explicit warehouse FK → inventory_locations
        'journal_entry_id',     // T4 journal entry: Dr WIP / Cr Vaccine Inventory
        // ── Retired fields (kept for history) ────────────────────
        'vaccine_id',           // nullable — legacy records only; do not use for new records
        // ── Unchanged fields ─────────────────────────────────────
        'date',
        'batch_number',
        'next_due_date',
        'dose_given_ml',        // kept for legacy records; new records use inventory item's unit
        'cost',                 // auto-computed: WAC × dose for new records
        'administered_by',
        'notes',
    ];

    protected $casts = [
        'date'          => 'date',
        'next_due_date' => 'date',
        'cost'          => 'decimal:2',
        'dose_given_ml' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * The inventory item (category=vaccine) used in this vaccination.
     * VaccinationController decrements the location-specific stock row
     * using inventory_item_id + location_id together.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
     */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * The warehouse location the vaccine was taken from.
     * Added by Migration 059.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    /**
     * The T4 journal entry auto-posted on vaccination recording.
     * Dr Livestock WIP (1310) / Cr Vaccine Inventory (1330)
     * For batch: one entry for total batch cost, lot_id stamped.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Legacy: vaccine from the retired vaccines catalog.
     * Only populated on records created before Migration 059.
     * Do not use for new records or any business logic.
     *
     * @deprecated Use inventoryItem() for all new code.
     */
    public function vaccine()
    {
        return $this->belongsTo(Vaccine::class);
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether this record was created under the new workflow
     * (inventory_item_id set) or the legacy workflow (vaccine_id set).
     *
     * Used to choose the correct display path in Vue and the correct
     * cost calculation path in LivestockIntelligenceService.
     */
    public function isLegacy(): bool
    {
        return $this->inventory_item_id === null && $this->vaccine_id !== null;
    }
}