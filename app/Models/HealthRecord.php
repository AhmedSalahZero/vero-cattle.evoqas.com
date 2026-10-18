<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — HealthRecord Model
//
//  📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
//
//  SESSION 49 CHANGES — Migration 059:
//
//  Added location_id — the specific warehouse location the medicine
//  was taken from. This fixes the blind inventory decrement in
//  HealthRecordService which previously decremented the master
//  inventory item's qty without knowing which location stock row
//  to touch.
//
//  inventory_item_id already existed on this table (column was
//  present but not in $fillable — added here).
//
//  Accounting impact (new):
//    journal_entry_id links to the T3 auto-posted entry:
//    Dr Livestock WIP (1310) [animal_id stamped] / Cr Medicine Inventory (1330)
// ══════════════════════════════════════════════════════════════════

class HealthRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',
        'animal_id',
        'lot_id',
        'inventory_item_id',    // FK → inventory_items (category=medicine) — master item
        'location_id',          // FK → inventory_locations — explicit warehouse (Migration 059)
        'type',
        'date',
        'condition',
        'medicine_name',        // denormalized from inventory item by HealthRecordService
        'dose',
        'dose_unit',
        'route',
        'cost',                 // auto-computed: unit_cost × dose (bcmath)
        'currency',
        'vet_id',
        'vet_name',
        'outcome',
        'withdrawal_clear_date',
        'is_quarantine',
        'journal_entry_id',     // T3 journal entry: Dr WIP / Cr Medicine Inventory
        'notes',
    ];

    protected $casts = [
        'date'                  => 'date',
        'withdrawal_clear_date' => 'date',
        'is_quarantine'         => 'boolean',
        'cost'                  => 'decimal:2',
        'dose'                  => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * The inventory item (category=medicine) used in this treatment.
     * HealthRecordService decrements the location-specific stock row
     * using inventory_item_id + location_id together.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * The warehouse location the medicine was taken from.
     * Added by Migration 059.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    /**
     * The T3 journal entry auto-posted on treatment record creation.
     * Dr Livestock WIP (1310) [animal_id stamped] / Cr Medicine Inventory (1330)
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function vet()
    {
        return $this->belongsTo(User::class, 'vet_id');
    }
}