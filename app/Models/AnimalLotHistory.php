<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AnimalLotHistory Model
//
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
//
//  Tracks every lot an animal has ever been in, with entry and
//  exit dates. This is the foundation for date-bounded feed cost
//  allocation at COGS calculation time.
//
//  Invariants (enforced at application layer — AnimalController):
//    1. Only one open row per animal (exited_at IS NULL) at any time
//    2. No gaps between periods for the same animal
//    3. No overlapping date ranges for the same animal
//    4. animals.lot_id always mirrors the open row's lot_id
//
//  Lifecycle:
//    Animal purchased/born → INSERT open row (entered_at = purchase_date/dob)
//    Animal transferred     → CLOSE current row + INSERT new open row
//    Animal sold            → CLOSE current row (exit_reason = sold)
//    Animal slaughtered     → CLOSE current row (exit_reason = slaughtered)
//    Animal dies            → CLOSE current row (exit_reason = dead)
//    Sale reversed          → REOPEN last row (exited_at = NULL)
// ══════════════════════════════════════════════════════════════════

class AnimalLotHistory extends Model
{
    use HasFactory;

    protected $table = 'animal_lot_history';

    protected $fillable = [
        'animal_id',
        'lot_id',
        'farm_id',
        'company_id',
        'entered_at',
        'exited_at',
        'exit_reason',
        'notes',
    ];

    protected $casts = [
        'entered_at' => 'date',
        'exited_at'  => 'date',
    ];

    // Valid exit reasons — mirrors migration enum
    public const EXIT_REASONS = [
        'transferred',
        'sold',
        'slaughtered',
        'dead',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    /**
     * Open rows only — the animal is currently in this lot.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('exited_at');
    }

    /**
     * Closed rows only — historical periods.
     */
    public function scopeClosed($query)
    {
        return $query->whereNotNull('exited_at');
    }

    /**
     * All lot memberships that were active on a given date.
     * Used by LivestockIntelligenceService::costingForAnimal()
     *
     * A membership is active on $date if:
     *   entered_at <= $date AND (exited_at IS NULL OR exited_at > $date)
     */
    public function scopeActiveOnDate($query, string $date)
    {
        return $query->where('entered_at', '<=', $date)
                     ->where(function ($q) use ($date) {
                         $q->whereNull('exited_at')
                           ->orWhere('exited_at', '>', $date);
                     });
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether this membership period is currently open.
     */
    public function isOpen(): bool
    {
        return $this->exited_at === null;
    }

    /**
     * Close this membership period.
     * Called by AnimalController::transferLot(), SalesController::confirm(),
     * SlaughterController::confirm(), and mortality recording.
     */
    public function close(string $exitDate, string $reason, ?string $notes = null): void
    {
        $this->update([
            'exited_at'   => $exitDate,
            'exit_reason' => $reason,
            'notes'       => $notes ?? $this->notes,
        ]);
    }
}