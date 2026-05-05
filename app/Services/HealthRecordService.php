<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\HealthRecord;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Health Record Service
//
//  📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//
//  SESSION 49 CHANGES — Migration 059:
//
//  The service now requires location_id in addition to
//  inventory_item_id. All inventory operations target the
//  location-specific stock row (is_master=false), not the master.
//
//  What changed:
//    — resolveStockRow() added: finds location-specific stock row
//      using master_item_id + location_id
//    — create(): decrements location stock row (not master)
//    — create(): writes InventoryTransaction (type=consumption)
//    — create(): posts T3 journal entry: Dr WIP (1310) / Cr Medicine (1330)
//    — update(): restores and decrements location stock rows
//    — update(): reverses old InventoryTransaction, writes new one
//    — update(): reverses old journal entry (if posted), posts new one
//    — delete(): restores location stock row, deletes InventoryTransaction,
//                reverses journal entry if posted
//
//  SEC-GAP-5 preserved: update() still acquires both old and new
//  stock row locks in a single whereIn() lockForUpdate() call.
//
//  NOTE: The service locks location-stock rows (is_master=false),
//  not master items. All qty_on_hand lives on location rows.
//  Masters carry no qty — they are definition rows only.
// ══════════════════════════════════════════════════════════════════

class HealthRecordService
{
    // ── Create a health record with stock decrement ────────────────
    /**
     * 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     *
     * Steps:
     * 1. Resolve + lock location-specific stock row
     * 2. Hard-block if qty_on_hand < dose
     * 3. Capture WAC from stock row (cost source of truth)
     * 4. Decrement stock row by exact dose
     * 5. Denormalize medicine_name from master inventory item
     * 6. Auto-calculate cost: WAC × dose (bcmath)
     * 7. Create HealthRecord
     * 8. Write InventoryTransaction (type=consumption)
     * 9. Post T3 journal entry: Dr WIP (1310) [animal_id] / Cr Medicine (1330)
     * 10. Write journal_entry_id back to HealthRecord
     */
    public function create(array $data, int $companyId): HealthRecord
    {
        return DB::transaction(function () use ($data, $companyId) {

            // 📌 Step 1 — Resolve + lock location-specific stock row
            // inventory_item_id points to the MASTER item (is_master=true)
            // We need the location stock row (is_master=false) for this location
            $stockRow = $this->resolveAndLockStockRow(
                (int) $data['inventory_item_id'],
                (int) $data['location_id'],
                $companyId
            );

            // Load master item for name denormalization
            $masterItem = InventoryItem::findOrFail($data['inventory_item_id']);

            // 📌 Step 2 — Hard block: insufficient stock
            $this->assertSufficientStock($stockRow, $data['dose'], $masterItem);

            // 📌 Step 3 — Capture WAC before decrement
            // WAC is the cost source of truth — replaces any user-submitted cost
            $unitCost = (string) $stockRow->unit_cost;

            // 📌 Step 4 — Decrement location stock row
            // Capture qty_after BEFORE the decrement for the transaction record
            $qtyAfter = (float) bcadd(
                bcsub((string) $stockRow->qty_on_hand, (string) $data['dose'], 4),
                '0', 3
            );
            $stockRow->decrement('qty_on_hand', (float) $data['dose']);

            // 📌 Step 5 — Denormalize medicine name (server-side, never user input)
            $data['medicine_name'] = $masterItem->name_en ?? $masterItem->name_ar;

            // 📌 Step 6 — Auto-calculate cost: WAC × dose (bcmath)
            // Overrides user-submitted cost — WAC is authoritative
            // 📌 BcmathMonetaryPrecision (#39)
            $cost = (float) bcadd(
                bcmul($unitCost, (string) $data['dose'], 4),
                '0', 2
            );
            $data['cost'] = $cost;

            // 📌 Step 7 — Persist HealthRecord
            $record = HealthRecord::create([...$data, 'company_id' => $companyId]);

            // 📌 Step 8 — Write InventoryTransaction (type=consumption)
            // System-created only — never created manually for medicine
            InventoryTransaction::create([
                'company_id'  => $companyId,
                'farm_id'     => $stockRow->farm_id,
                'location_id' => $data['location_id'],
                'item_id'     => $stockRow->id,       // location stock row id
                'type'        => 'consumption',
                'date'        => $data['date'],
                'qty'         => (float) $data['dose'],
                'unit_cost'   => (float) $unitCost,
                'total'       => $cost,
                'qty_after'   => $qtyAfter,
                'wac_after'   => (float) $unitCost,   // WAC unchanged on consumption
                'ref_type'    => 'health_record',
                'ref_id'      => $record->id,
                'recorded_by' => auth()->id(),
                'notes'       => 'Medicine: ' . $data['medicine_name'] . ' — Animal #' . $data['animal_id'],
            ]);

            // 📌 Step 9 — Post T3 journal entry
            // Dr Livestock WIP (1310) [animal_id stamped]
            // Cr Medicine Inventory (1330)
            $je = $this->postMedicineJournalEntry(
                $record,
                $companyId,
                $cost,
                $data['date'],
                (int) $data['animal_id'],
                $data['lot_id'] ?? null
            );

            // 📌 Step 10 — Write journal_entry_id back
            if ($je) {
                $record->update(['journal_entry_id' => $je->id]);
            }

            return $record;
        });
    }

    // ── Update a health record with stock delta adjustment ─────────
    /**
     * 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     *
     * Steps:
     * 1. Collect all stock row IDs (old location + new location)
     *    Lock them ALL in one whereIn() query (SEC-GAP-5 preserved)
     * 2. Restore stock on OLD location stock row
     * 3. Hard-block if NEW location stock row has insufficient stock
     * 4. Decrement NEW location stock row
     * 5. Re-denormalize medicine_name from new master item
     * 6. Recompute cost from new WAC × new dose
     * 7. Delete old InventoryTransaction, write new one
     * 8. Reverse old journal entry if posted, post new one
     * 9. Update HealthRecord
     */
    public function update(HealthRecord $record, array $data): HealthRecord
    {
        return DB::transaction(function () use ($record, $data) {

            $oldMasterItemId = (int) $record->inventory_item_id;
            $newMasterItemId = (int) $data['inventory_item_id'];
            $oldLocationId   = (int) $record->location_id;
            $newLocationId   = (int) $data['location_id'];
            $companyId       = $record->company_id;

            // ── SEC-GAP-5 preserved ───────────────────────────────
            // Resolve both old and new location stock rows, then lock
            // them in a SINGLE whereIn() call — no TOCTOU window.
            // Sort IDs ascending (MySQL deadlock prevention rule).
            $oldStockRow = $this->findStockRow($oldMasterItemId, $oldLocationId, $companyId);
            $newStockRow = $this->findStockRow($newMasterItemId, $newLocationId, $companyId);

            $stockIds = collect([$oldStockRow?->id, $newStockRow?->id])
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $locked = InventoryItem::lockForUpdate()
                ->whereIn('id', $stockIds)
                ->get()
                ->keyBy('id');

            $oldStock = $oldStockRow ? $locked->get($oldStockRow->id) : null;
            $newStock = $newStockRow ? $locked->get($newStockRow->id) : null;

            abort_unless($newStock, 422, __('validation.health.medicine_not_found'));

            // 📌 Step 2 — Restore old stock
            if ($oldStock && $record->dose > 0) {
                $oldStock->increment('qty_on_hand', (float) $record->dose);
            }

            // 📌 Step 3 — Hard-block on new stock (post-restore balance)
            $newMasterItem = InventoryItem::findOrFail($newMasterItemId);
            $this->assertSufficientStock($newStock, $data['dose'], $newMasterItem);

            // 📌 Step 4 — Decrement new stock
            $newUnitCost = (string) $newStock->unit_cost;
            $newQtyAfter = (float) bcadd(
                bcsub((string) $newStock->qty_on_hand, (string) $data['dose'], 4),
                '0', 3
            );
            $newStock->decrement('qty_on_hand', (float) $data['dose']);

            // 📌 Step 5 — Re-denormalize medicine name
            $data['medicine_name'] = $newMasterItem->name_en ?? $newMasterItem->name_ar;

            // 📌 Step 6 — Recompute cost from new WAC × new dose
            $newCost = (float) bcadd(
                bcmul($newUnitCost, (string) $data['dose'], 4),
                '0', 2
            );
            $data['cost'] = $newCost;

            // 📌 Step 7 — Replace InventoryTransaction
            InventoryTransaction::where('ref_type', 'health_record')
                ->where('ref_id', $record->id)
                ->delete();

            InventoryTransaction::create([
                'company_id'  => $companyId,
                'farm_id'     => $newStock->farm_id,
                'location_id' => $newLocationId,
                'item_id'     => $newStock->id,
                'type'        => 'consumption',
                'date'        => $data['date'],
                'qty'         => (float) $data['dose'],
                'unit_cost'   => (float) $newUnitCost,
                'total'       => $newCost,
                'qty_after'   => $newQtyAfter,
                'wac_after'   => (float) $newUnitCost,
                'ref_type'    => 'health_record',
                'ref_id'      => $record->id,
                'recorded_by' => auth()->id(),
                'notes'       => 'Medicine (updated): ' . $data['medicine_name'],
            ]);

            // 📌 Step 8 — Handle journal entry
            // If old JE was posted: reverse it, post new one
            // If old JE was draft: delete it, post new one
            if ($record->journal_entry_id) {
                $oldJe = JournalEntry::find($record->journal_entry_id);
                if ($oldJe) {
                    if ($oldJe->status === 'posted') {
                        // Post reversal entry
                        $this->reverseJournalEntry($oldJe);
                    } else {
                        $oldJe->lines()->delete();
                        $oldJe->delete();
                    }
                }
            }

            $newJe = $this->postMedicineJournalEntry(
                $record,
                $companyId,
                $newCost,
                $data['date'],
                (int) $data['animal_id'],
                $data['lot_id'] ?? null
            );

            $data['journal_entry_id'] = $newJe?->id;

            // 📌 Step 9 — Persist
            $record->update($data);

            return $record->fresh();
        });
    }

    // ── Delete a health record — restore stock ────────────────────
    /**
     * 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement (#14)
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     */
    public function delete(HealthRecord $record): void
    {
        DB::transaction(function () use ($record) {

            // Restore stock to location-specific stock row
            if ($record->inventory_item_id && $record->location_id && $record->dose > 0) {
                $stockRow = $this->findStockRow(
                    (int) $record->inventory_item_id,
                    (int) $record->location_id,
                    $record->company_id
                );

                if ($stockRow) {
                    InventoryItem::lockForUpdate()
                        ->find($stockRow->id)
                        ?->increment('qty_on_hand', (float) $record->dose);
                }
            }

            // Delete linked InventoryTransaction
            InventoryTransaction::where('ref_type', 'health_record')
                ->where('ref_id', $record->id)
                ->delete();

            // Handle journal entry
            if ($record->journal_entry_id) {
                $je = JournalEntry::find($record->journal_entry_id);
                if ($je) {
                    if ($je->status === 'posted') {
                        $this->reverseJournalEntry($je);
                    } else {
                        $je->lines()->delete();
                        $je->delete();
                    }
                }
            }

            $record->delete();
        });
    }

    // ── Get medicine meta for Vue auto-fill ───────────────────────
    /**
     * Returns cost preview data when user selects a medicine + location.
     * Called by controller to return data for frontend auto-fill.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
     */
    public function getMedicineMeta(int $masterItemId, int $locationId, int $companyId): array
    {
        $masterItem = InventoryItem::where('id', $masterItemId)
            ->where('is_master', true)
            ->firstOrFail();

        $stockRow = $this->findStockRow($masterItemId, $locationId, $companyId);

        return [
            'name_en'     => $masterItem->name_en,
            'name_ar'     => $masterItem->name_ar,
            'unit'        => $masterItem->unit,
            'unit_cost'   => $stockRow ? (float) $stockRow->unit_cost : 0,   // WAC
            'qty_on_hand' => $stockRow ? (float) $stockRow->qty_on_hand : 0,
        ];
    }

    // ── Private: resolve and lock stock row ───────────────────────

    /**
     * Finds the location-specific stock row (is_master=false) for a
     * given master item at a given location, then locks it for update.
     *
     * Must be called inside an active DB::transaction().
     *
     * @throws ValidationException if no stock row found
     */
    private function resolveAndLockStockRow(
        int $masterItemId,
        int $locationId,
        int $companyId
    ): InventoryItem {
        $stockRow = InventoryItem::where('master_item_id', $masterItemId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->where('is_master', false)
            ->lockForUpdate()
            ->first();

        if (!$stockRow) {
            throw ValidationException::withMessages([
                'location_id' => __('validation.health.no_stock_row_at_location'),
            ]);
        }

        return $stockRow;
    }

    /**
     * Finds the location-specific stock row WITHOUT locking.
     * Used in update() to collect IDs before the bulk lock.
     */
    private function findStockRow(
        int $masterItemId,
        int $locationId,
        int $companyId
    ): ?InventoryItem {
        return InventoryItem::where('master_item_id', $masterItemId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->where('is_master', false)
            ->first();
    }

    // ── Private: post T3 journal entry ────────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
    //
    // T3: Dr Livestock WIP (1310) [animal_id stamped]
    //     Cr Medicine Inventory (1330)
    //
    // Soft-fails with session warning if accounts or fiscal year missing.
    private function postMedicineJournalEntry(
        HealthRecord $record,
        int          $companyId,
        float        $cost,
        string       $date,
        int          $animalId,
        ?int         $lotId
    ): ?JournalEntry {

        $wipAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1310%')
            ->where('allow_direct_posting', true)
            ->first();

        $medicineAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1330%')
            ->where('allow_direct_posting', true)
            ->first();

        if (!$wipAccount || !$medicineAccount) {
            session()->flash('warning', __('messages.health_je_accounts_missing'));
            return null;
        }

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning', __('messages.health_je_no_fiscal_year'));
            return null;
        }

        // 📌 BcmathMonetaryPrecision (#39)
        $amount = (float) bcadd((string) $cost, '0', 2);
        $period = (int) date('n', strtotime($date));

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $date,
            'period'         => $period,
            'reference'      => 'HLTH-' . now()->format('YmdHis'),
            'description'    => 'Medicine: ' . ($record->medicine_name ?? 'Treatment') . ' — Animal #' . $animalId,
            'entry_type'     => 'health',
            'status'         => 'posted',
            'total_debit'    => $amount,
            'total_credit'   => $amount,
            'posted_by'      => auth()->id(),
            'posted_at'      => now(),
            'source_type'    => HealthRecord::class,
            'source_id'      => $record->id,
        ]);

        // Dr Livestock WIP (1310) — animal_id stamped for per-animal COGS tracing
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $wipAccount->id,
            'debit'      => $amount,
            'credit'     => 0,
            'animal_id'  => $animalId,   // 📌 required per Methodology #43 §9
            'lot_id'     => $lotId,
            'memo'       => 'Medicine administered: ' . ($record->medicine_name ?? ''),
        ]);

        // Cr Medicine Inventory (1330)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $medicineAccount->id,
            'debit'      => 0,
            'credit'     => $amount,
            'animal_id'  => $animalId,
            'lot_id'     => $lotId,
            'memo'       => 'Medicine consumed from inventory',
        ]);

        return $je;
    }

    // ── Private: reverse a posted journal entry ───────────────────
    //
    // Creates a mirror entry with debits ↔ credits swapped.
    // Links back via reversal_of_id.
    // Per Methodology #1 (JournalBalanceValidation) — posted entries
    // cannot be deleted; they must be reversed.
    private function reverseJournalEntry(JournalEntry $original): JournalEntry
    {
        $reversal = JournalEntry::create([
            'company_id'     => $original->company_id,
            'fiscal_year_id' => $original->fiscal_year_id,
            'date'           => now()->toDateString(),
            'period'         => (int) date('n'),
            'reference'      => 'REV-' . $original->reference,
            'description'    => 'Reversal of: ' . $original->description,
            'entry_type'     => $original->entry_type,
            'status'         => 'posted',
            'total_debit'    => $original->total_credit,
            'total_credit'   => $original->total_debit,
            'posted_by'      => auth()->id(),
            'posted_at'      => now(),
            'reversal_of_id' => $original->id,
        ]);

        foreach ($original->lines as $line) {
            JournalLine::create([
                'entry_id'   => $reversal->id,
                'account_id' => $line->account_id,
                'debit'      => $line->credit,   // swapped
                'credit'     => $line->debit,    // swapped
                'animal_id'  => $line->animal_id,
                'lot_id'     => $line->lot_id,
                'memo'       => 'Reversal: ' . $line->memo,
            ]);
        }

        // Mark original as reversed
        $original->update(['status' => 'reversed']);

        return $reversal;
    }

    // ── Private: stock guard ───────────────────────────────────────
    private function assertSufficientStock(
        InventoryItem  $stockRow,
        float          $dose,
        InventoryItem  $masterItem
    ): void {
        if ($dose <= 0) {
            throw ValidationException::withMessages([
                'dose' => __('validation.health.dose_positive'),
            ]);
        }

        if ($stockRow->qty_on_hand < $dose) {
            throw ValidationException::withMessages([
                'dose' => __('validation.health.insufficient_stock', [
                    'available' => $stockRow->qty_on_hand,
                    'unit'      => $masterItem->unit,
                    'medicine'  => $masterItem->name_en ?? $masterItem->name_ar,
                ]),
            ]);
        }
    }
}