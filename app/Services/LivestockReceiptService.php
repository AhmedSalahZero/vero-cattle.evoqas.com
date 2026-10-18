<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\AnimalReceiptBatch;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ProcurementRecord;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Livestock Receipt Service
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//  📌 METHODOLOGY REFERENCE: LivestockReceiptItemIdNullable (#41)
//
//  Single responsibility: confirm a batch of animals received
//  against a Purchase Order — atomically and completely.
//
//  Called by: ProcurementController::confirmReceipt()
//
//  What this service guarantees in ONE DB::transaction:
//    1. PO is valid and open (lockForUpdate)
//    2. Subtotal computed via bcmath
//    3. AnimalReceiptBatch row created
//    4. Animal rows created (status = active, receipt_batch_id set)
//    5. InventoryTransaction (livestock_receipt, item_id = NULL) created
//    6. batch.inventory_transaction_id written back
//    7. PO.received_head incremented
//    8. PO.po_status auto-computed and saved
//    9. Over-received tag applied if applicable
//   10. T1 Journal Entry auto-posted:
//         Dr Livestock WIP (1310)   batch_subtotal
//         Cr AP Accruals    (2230)  batch_subtotal
//
//  If any step throws, the entire transaction rolls back.
//  No partial state is ever written.
//
//  SESSION 46 CHANGE:
//  Migration 057 makes item_id nullable on inventory_transactions.
//  getLivestockSentinelItemId() has been removed. item_id is now
//  passed as NULL for livestock_receipt rows — correct and clean.
// ══════════════════════════════════════════════════════════════════

class LivestockReceiptService
{
    /**
     * Confirm a batch of animals received against a PO.
     *
     * @param  int    $procurementId   The PO being received against
     * @param  array  $animalsData     Array of animal rows (validated upstream)
     * @param  array  $batchMeta       { date, receipt_mode, notes, freight_allocated }
     * @return AnimalReceiptBatch      The confirmed batch record
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException  on guard failures
     */
    public function confirmReceipt(
        int   $procurementId,
        array $animalsData,
        array $batchMeta
    ): AnimalReceiptBatch {

        return DB::transaction(function () use ($procurementId, $animalsData, $batchMeta) {

            // ── STEP 1: Lock the PO ───────────────────────────────
            // lockForUpdate prevents two simultaneous receipt submissions
            // from both reading the same received_head value.
            // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — E14
            $po = ProcurementRecord::lockForUpdate()->findOrFail($procurementId);

            abort_if(
                in_array($po->po_status, ['draft', 'fully_received', 'force_closed']),
                422,
                __('messages.po_not_open_for_receipt')
            );

            abort_if(
                empty($animalsData),
                422,
                __('messages.receipt_no_animals')
            );

            $batchCount   = count($animalsData);
            $receiptDate  = $batchMeta['date'] ?? now()->toDateString();
            $receiptMode  = $batchMeta['receipt_mode'] ?? 'bulk_avg';
            $freightAlloc = $batchMeta['freight_allocated'] ?? 0;

            // ── STEP 2: Compute subtotal via bcmath ───────────────
            // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
            // price_per_head = weight_kg × po.estimated_price_per_kg
            // subtotal       = Σ price_per_head across all animals
            $subtotal    = '0';
            $pricePerKg  = (string) $po->estimated_price_per_kg;

            foreach ($animalsData as &$animal) {
                $weightKg = (string) ($animal['weight_kg'] ?? 0);

                // Individual animal cost: weight × PO price per kg
                // Round each head to whole cents FIRST (half-up) and add up the rounded
                // amounts, so the batch total / journal entry is exactly the sum of what is
                // stored on the animals. (the old bcadd(x,'0',2) only truncated, which lost cents.)
                $pricePerHead = \App\Support\Money::round(bcmul($weightKg, $pricePerKg, 4), 2);
                $animal['purchase_price']  = (float) $pricePerHead;
                $animal['po_price_per_kg'] = (float) $pricePerKg;

                $subtotal = bcadd($subtotal, $pricePerHead, 2);
            }
            unset($animal);

            // Already a sum of 2dp amounts — nothing left to round
            $subtotalStored = (float) $subtotal;

            // Unit cost per head (for inventory_transaction.unit_cost)
            $unitCost = ($batchCount > 0)
                ? (float) bcdiv($subtotal, (string) $batchCount, 4)
                : 0.0;

            // ── STEP 3: Create receipt batch ──────────────────────
            $batch = AnimalReceiptBatch::create([
                'procurement_id'    => $po->id,
                'company_id'        => $po->company_id,
                'farm_id'           => $po->farm_id,
                'lot_id'            => $po->lot_id,
                'date'              => $receiptDate,
                'received_head'     => $batchCount,
                'po_price_per_kg'   => (float) $pricePerKg,
                'po_price_per_head' => $unitCost,
                'freight_allocated' => (float) $freightAlloc,
                'subtotal'          => $subtotalStored,
                'receipt_mode'      => $receiptMode,
                'status'            => 'confirmed',
                'created_by'        => Auth::id(),
                'notes'             => $batchMeta['notes'] ?? null,
            ]);

            // ── STEP 4: Create animal rows ────────────────────────
            // All animals created with status = 'active' — immediately
            // eligible for sale, health records, vaccinations, etc.
            // 📌 METHODOLOGY REFERENCE: SaleAndSlaughterStatusLifecycle (#27)
            // — active animals are eligible for reserveAnimals() in Sales
            $now = Carbon::now();

            // QA-035: freight is split equally, to the cent; the LAST animal takes the cents that remain,
            // so the freight on the animals adds up exactly to the freight of the batch
            // (1000 over 3 animals = 333.33 + 333.33 + 333.34, not 999.99).
            $freightBase = $batchCount > 0
                ? \App\Support\Money::round(bcdiv((string) $freightAlloc, (string) $batchCount, 6), 2)
                : '0.00';
            $freightLast = $batchCount > 0
                ? bcsub(\App\Support\Money::round((string) $freightAlloc, 2), bcmul($freightBase, (string) ($batchCount - 1), 2), 2)
                : '0.00';

            foreach (array_values($animalsData) as $animalIndex => $animalData) {
                $animal = Animal::create([
                    'company_id'          => $po->company_id,
                    'farm_id'             => $po->farm_id,
                    'lot_id'              => $po->lot_id,
                    'receipt_batch_id'    => $batch->id,
                    'tag'                 => $animalData['tag'],
                    'rfid'                => $animalData['rfid'] ?? null,
                    'qr_code'             => null,
                    'breed'               => $animalData['breed'] ?? null,
                    'dob'                 => $animalData['dob'] ?? null,
                    'dob_estimated'       => $animalData['dob_estimated'] ?? false,
                    'sex'                 => $animalData['sex'],
                    'color'               => $animalData['color'] ?? null,
                    'markings'            => $animalData['markings'] ?? null,
                    'source'              => 'purchased',
                    'purchase_date'       => $receiptDate,
                    'supplier_id'         => $po->supplier_id,
                    'purchase_price'      => $animalData['purchase_price'],    // computed above
                    'purchase_weight_kg'  => $animalData['weight_kg'] ?? null,
                    'po_price_per_kg'     => $animalData['po_price_per_kg'],   // immutable snapshot
                    'freight_cost'        => (float) ($animalIndex === $batchCount - 1 ? $freightLast : $freightBase),
                    'status'              => 'active',
                    'status_date'         => $receiptDate,
                    'notes'               => $animalData['notes'] ?? null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);

                // ── STEP 4a: Start the animal's lot history ──────────
                // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §4
                // Animal-days for the monthly labour/overhead accrual are counted
                // from this row, starting on the receipt date.
                app(AnimalLotHistoryService::class)->open($animal, Carbon::parse($receiptDate)->toDateString());

                // ── STEP 4b: Write arrival weight to weight_records ───
                // The receiving user weighs each animal on arrival.
                // This weight is w_initial — the ADG baseline.
                // Without this row, adgFcrForAnimal() has no starting
                // point and returns null for every purchased animal.
                //
                // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
                //
                // session_ref = 'PO-ARRIVAL/{po_reference}' so the
                // weight history is auditable and clearly identifiable
                // as an arrival weigh-in, not a routine mid-period check.
                if (!empty($animalData['weight_kg'])) {
                    WeightRecord::create([
                        'company_id'      => $po->company_id,
                        'farm_id'         => $po->farm_id,
                        'lot_id'          => $po->lot_id,
                        'animal_id'       => $animal->id,
                        'date'            => $receiptDate,
                        'weight_kg'       => (float) $animalData['weight_kg'],
                        'session_ref'     => 'PO-ARRIVAL/' . ($po->po_reference ?? $po->id),
                        'scale_reference' => null,
                        'recorded_by'     => Auth::id(),
                        'notes'           => 'Arrival weight — PO receipt batch #' . $batch->id,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                }
            }

            // ── STEP 5: Create inventory transaction ──────────────
            // type = 'livestock_receipt' — participates in AP accrual
            // matching exactly like 'receipt' for feed/medicine.
            // ap_invoice_id = NULL → unmatched accrual (Dr WIP / Cr AP Accruals)
            // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (#22)
            // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — T1
            // 📌 METHODOLOGY REFERENCE: LivestockReceiptItemIdNullable (#41)
            //
            // item_id = NULL is correct and intentional after Migration 057.
            // Animals are tracked in the `animals` table — they have no
            // inventory_item record. Nullable item_id is permitted only for
            // livestock_receipt type; all other types require a non-null item_id
            // enforced at application level in InventoryController.
            $txn = InventoryTransaction::create([
                'company_id'   => $po->company_id,
                'farm_id'      => $po->farm_id,
                'location_id'  => null,    // livestock has no warehouse location
                'item_id'      => null,    // intentionally null — see Methodology #41
                'type'         => 'livestock_receipt',
                'date'         => $receiptDate,
                'qty'          => $batchCount,
                'unit_cost'    => $unitCost,
                'total'        => $subtotalStored,
                'qty_after'    => null,    // not applicable — animals are not fungible
                'wac_after'    => null,    // WAC not applicable to individual animals
                'ref_type'     => 'animal_receipt_batch',
                'ref_id'       => $batch->id,
                'supplier_id'  => $po->supplier_id,
                'ref_no'       => $po->po_reference,
                'ap_invoice_id'=> null,    // unmatched — accountant will match later
                'recorded_by'  => Auth::id(),
                'notes'        => 'PO #' . ($po->po_reference ?? $po->id)
                                    . ' — ' . $batchCount . ' head received',
            ]);

            // ── STEP 6: Write txn back to batch ──────────────────
            $batch->update(['inventory_transaction_id' => $txn->id]);

            // ── STEP 7: Increment PO received count ──────────────
            $po->received_head += $batchCount;

            // ── STEP 8: Auto-compute PO status ───────────────────
            $po->po_status = $this->computePoStatus($po);
            $po->save();

            // ── STEP 9: Over-received check ───────────────────────
            // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — §10
            // PO is tagged over_received (already set in step 8).
            // Phase 5 dispatches supervisor notification here.
            if ($po->received_head > $po->max_head) {
                // Phase 5 hook:
                // event(new \App\Events\PoOverReceivedEvent($po, $batch));
            }

            // ── STEP 10: Auto-post T1 Journal Entry ──────────────
            // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — T1
            // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
            // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
            //
            //   Dr  Livestock WIP (1310)    batch_subtotal
            //   Cr  AP Accruals   (2230)    batch_subtotal
            //
            // Runs inside the same DB::transaction — if JE fails,
            // animals and inventory_transaction are also rolled back.
            $this->postReceiptJournalEntry($po, $batch, $txn, $subtotalStored);

            return $batch;
        });
    }

    /**
     * Force-close a PO that will not receive its full contracted quantity.
     *
     * Guards:
     *   - received_head must be > 0 (use cancel for zero-receipt POs)
     *   - closure_reason required, minimum 10 characters
     *   - po_status must NOT be 'fully_received' or 'draft'
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — §11
     */
    public function forceClose(ProcurementRecord $po, string $reason): ProcurementRecord
    {
        abort_if(
            $po->po_status === 'draft',
            422,
            __('messages.po_not_confirmed')
        );

        abort_if(
            $po->po_status === 'fully_received',
            422,
            __('messages.po_already_fully_received')
        );

        abort_if(
            $po->received_head === 0,
            422,
            __('messages.po_force_close_no_receipts')
        );

        abort_if(
            strlen(trim($reason)) < 10,
            422,
            __('messages.po_closure_reason_too_short')
        );

        $po->update([
            'po_status'      => 'force_closed',
            'closure_reason' => $reason,
            'closed_at'      => now(),
            'closed_by'      => Auth::id(),
        ]);

        return $po->fresh();
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Auto-post T1 journal entry for a livestock receipt.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — T1
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
     *
     * Dr  Livestock WIP (1310)    batch_subtotal
     * Cr  AC Accruals   (2230)    batch_subtotal
     *
     * Called inside confirmReceipt() DB::transaction — any failure here
     * rolls back the entire receipt (animals, batch, txn, and JE together).
     *
     * If accounts 1310 or 2230 are not seeded for the company, the JE is
     * skipped and a session warning is flashed. The receipt itself succeeds.
     * The accountant must post the JE manually from the Journal Entries page.
     */
    private function postReceiptJournalEntry(
        ProcurementRecord  $po,
        AnimalReceiptBatch $batch,
        InventoryTransaction $txn,
        float              $subtotal
    ): void {
        $companyId = $po->company_id;

        // Look up the two required accounts
        $wipAccount      = Account::where('company_id', $companyId)->where('code', '1310')->first();
        $accrualAccount  = Account::where('company_id', $companyId)->where('code', '2230')->first();

        if (!$wipAccount || !$accrualAccount) {
            // Soft failure — receipt is valid, JE is skipped
            session()->flash('warning',
                'Livestock receipt confirmed. Accounting entries for accounts 1310/2230 could not be posted — please post manually.'
            );
            return;
        }

        // Resolve open fiscal year covering the receipt date
        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $batch->date)
            ->whereDate('end_date',   '>=', $batch->date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning',
                'Livestock receipt confirmed. No open fiscal year covers the receipt date — please post the accounting entry manually.'
            );
            return;
        }

        $amount    = (string) $subtotal;
        $reference = 'LR/' . ($po->po_reference ?? $po->id) . '/B' . $batch->id;
        $memo      = 'Livestock receipt — ' . ($po->po_reference ?? 'PO#' . $po->id)
                   . ' — ' . $batch->received_head . ' head';

        // 📌 BcmathMonetaryPrecision: total_debit and total_credit stored as bcmath-rounded strings
        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $batch->date,
            'reference'      => $reference,
            'description'    => $memo,
            'period'         => (int) date('n', strtotime($batch->date)),
            'status'         => 'posted',
            'entry_type'     => 'procurement',
            'source_type'    => AnimalReceiptBatch::class,
            'source_id'      => $batch->id,
            'total_debit'    => (float) \App\Support\Money::round($amount, 2),
            'total_credit'   => (float) \App\Support\Money::round($amount, 2),
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr Livestock WIP (1310)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $wipAccount->id,
            'debit'      => (float) \App\Support\Money::round($amount, 2),
            'credit'     => 0,
            'farm_id'    => $po->farm_id,
            'lot_id'     => $po->lot_id,
            'memo'       => $memo,
        ]);

        // Cr AP Accruals (2230)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $accrualAccount->id,
            'debit'      => 0,
            'credit'     => (float) \App\Support\Money::round($amount, 2),
            'farm_id'    => $po->farm_id,
            'memo'       => $memo,
        ]);

        // Link the JE back to the inventory transaction for audit trail
        $txn->update(['journal_entry_id' => $je->id]);
    }



    /**
     * Compute PO receipt-progress status based on current received_head.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40) — §6.2
     */
    private function computePoStatus(ProcurementRecord $po): string
    {
        $received = $po->received_head;
        $min      = $po->min_head;
        $max      = $po->max_head;

        if ($received === 0) {
            return 'open';
        }

        if ($received > $max) {
            return 'over_received';
        }

        if ($received === $max) {
            return 'fully_received';
        }

        if ($received >= $min) {
            return 'on_track';
        }

        // received > 0 but < min_head
        return 'partially_received';
    }

}