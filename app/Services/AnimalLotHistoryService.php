<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\AnimalLotHistory;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  AnimalLotHistoryService
//
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
//
//  The single place that writes animal_lot_history. Every animal that
//  sits in a lot has exactly ONE open row (exited_at IS NULL); a row
//  is closed when the animal moves lot, is sold, slaughtered or dies,
//  and re-opened when a sale or slaughter is reversed.
//
//  Why it matters: FarmCostAllocationService counts animal-days from
//  this table, and LivestockIntelligenceService uses it for date-bound
//  feed cost. Without rows, monthly labour/overhead cannot be posted.
//
//  Rules
//    1. open():    one open row per animal; no lot → no row; a second
//                  call for the same lot is a no-op (idempotent).
//    2. move():    close the open row (reason 'transferred') and open a
//                  new one on the same date.
//    3. close():   close open rows; exit date is never before entry date.
//    4. reopen():  re-open the most recently closed row of each animal
//                  (only if the animal has no open row).
//
//  Dates are 'Y-m-d' strings. Same-day in/out gives a zero-day row,
//  which is harmless (animal-days = exit − entry).
// ══════════════════════════════════════════════════════════════════

class AnimalLotHistoryService
{
    /** Start a lot membership for one animal. */
    public function open(Animal $animal, string $date): void
    {
        if (! $animal->lot_id) {
            return;
        }

        $current = AnimalLotHistory::where('animal_id', $animal->id)->whereNull('exited_at')->first();
        if ($current) {
            if ((int) $current->lot_id === (int) $animal->lot_id) {
                return; // already in this lot
            }
            $this->closeRow($current, $date, 'transferred');
        }

        AnimalLotHistory::create([
            'animal_id'  => $animal->id,
            'lot_id'     => $animal->lot_id,
            'farm_id'    => $animal->farm_id,
            'company_id' => $animal->company_id,
            'entered_at' => $date,
        ]);
    }

    /** Move animals to another lot (or out of every lot when $lotId is null). */
    public function move(array $animalIds, ?int $lotId, string $date): void
    {
        foreach (Animal::whereIn('id', $animalIds)->get() as $animal) {
            $current = AnimalLotHistory::where('animal_id', $animal->id)->whereNull('exited_at')->first();

            if ($current && (int) $current->lot_id === (int) $lotId) {
                continue;
            }
            if ($current) {
                $this->closeRow($current, $date, 'transferred');
            }
            if ($lotId) {
                $animal->lot_id = $lotId; // value already saved by the caller; keeps the row in step
                $this->open($animal, $date);
            }
        }
    }

    /** Close the open membership of the given animals (sold / slaughtered / dead). */
    public function close(array $animalIds, string $date, string $reason): void
    {
        if (empty($animalIds)) {
            return;
        }
        $rows = AnimalLotHistory::whereIn('animal_id', $animalIds)->whereNull('exited_at')->get();
        foreach ($rows as $row) {
            $this->closeRow($row, $date, $reason);
        }
    }

    /** Undo a sale / slaughter: the latest closed row of each animal is opened again. */
    public function reopen(array $animalIds): void
    {
        foreach (array_unique($animalIds) as $animalId) {
            if (AnimalLotHistory::where('animal_id', $animalId)->whereNull('exited_at')->exists()) {
                continue;
            }
            $last = AnimalLotHistory::where('animal_id', $animalId)
                ->whereIn('exit_reason', ['sold', 'slaughtered', 'dead'])
                ->orderByDesc('exited_at')->orderByDesc('id')->first();
            if ($last) {
                $last->update(['exited_at' => null, 'exit_reason' => null]);
            }
        }
    }

    /**
     * React to an animal edit: lot change and/or status change.
     * $before = the animal's lot_id and status BEFORE the update.
     */
    public function syncAfterEdit(Animal $animal, ?int $oldLotId, string $oldStatus): void
    {
        $date     = ($animal->status_date?->toDateString()) ?: now()->toDateString();
        $terminal = ['sold' => 'sold', 'slaughtered' => 'slaughtered', 'dead' => 'dead'];

        if (isset($terminal[$animal->status])) {
            $this->close([$animal->id], $date, $terminal[$animal->status]);
            return;
        }

        if (isset($terminal[$oldStatus])) {
            // brought back from sold / slaughtered / dead
            $this->reopen([$animal->id]);
            $this->open($animal, $date);
            return;
        }

        if ((int) $oldLotId !== (int) $animal->lot_id) {
            $this->move([$animal->id], $animal->lot_id ? (int) $animal->lot_id : null, now()->toDateString());
        } else {
            $this->open($animal, now()->toDateString()); // no-op when a row already exists
        }
    }

    private function closeRow(AnimalLotHistory $row, string $date, string $reason): void
    {
        $entered = $row->entered_at?->toDateString();
        $exit    = ($entered && $date < $entered) ? $entered : $date;
        $row->update(['exited_at' => $exit, 'exit_reason' => $reason]);
    }
}
