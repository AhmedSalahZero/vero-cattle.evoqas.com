<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\InventoryReceiptBatch;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ProcurementItemLine;
use App\Models\ProcurementRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Receipt Service
//
//  📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)
//  📌 METHODOLOGY REFERENCE: WAC (#10)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//
//  Single responsibility: confirm a batch of inventory items received
//  against an Inventory PO — atomically and completely.
//
//  Called by: ProcurementController::confirmInventoryReceipt()
//
//  What this service guarantees in ONE DB::transaction:
//    1.  PO is valid, inventory type, and open (lockForUpdate)
//    2.  Open fiscal year covers the receipt date
//    3.  For each item line in the batch:
//        3a. PO item line locked and validated (still open)
//        3b. Location stock row locked
//        3c. Pre-receipt values captured (wac_before, qty_before)
//        3d. Line total computed via bcmath
//        3e. WAC updated on stock row (bcmath, scale=6)
//        3f. qty_on_hand incremented on stock row
//        3g. InventoryTransaction (type='receipt') created
//        3h. ProcurementItemLine.qty_received incremented
//        3i. ProcurementItemLine.line_status auto-computed
//        3j. InventoryReceiptLine created with WAC audit trail
//    4.  InventoryReceiptBatch created with batch subtotal
//    5.  T1 Journal Entry auto-posted (grouped by account code):
//          Dr  Feed Inventory (1320)      [feed lines total]
//          Dr  Medicine Inventory (1330)  [medicine lines total]
//          Dr  Vaccine Inventory (1340)   [vaccine lines total]
//          Dr  Other Inventory (1350)     [equipment/supply/other total]
//          Cr  AP Accruals (2230)         [batch subtotal]
//    6.  JE id written back to batch and all inventory_transactions
//    7.  PO po_status auto-computed from all line statuses
//
//  If any step throws, the entire transaction rolls back.
//  No partial state is ever written.
//
//  Account routing by category:
//    feed      → 1320  Feed Inventory
//    medicine  → 1330  Medicine Inventory
//    vaccine   → 1340  Vaccine Inventory
//    equipment, supply, other → 1350  Other Inventory
// ══════════════════════════════════════════════════════════════════

class InventoryReceiptService
{
    /**
     * Confirm a batch of inventory items received against an Inventory PO.
     *
     * @param  int    $procurementId   The PO being received against
     * @param  array  $lines           Array of receipt line data (validated upstream)
     *                                 Each line: {
     *                                   procurement_item_line_id: int,
     *                                   qty_received: string|float,
     *                                   unit_cost_actual: string|float,
     *                                   notes: string|null
     *                                 }
     * @param  array  $batchMeta       { date: string, notes: string|null }
     * @return InventoryReceiptBatch   The confirmed batch record
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException  on guard failures
     */
    public function confirmReceipt(
        int   $procurementId,
        array $lines,
        array $batchMeta
    ): InventoryReceiptBatch {

        return DB::transaction(function () use ($procurementId, $lines, $batchMeta) {

            // ── STEP 1: Lock the PO ───────────────────────────────
            // lockForUpdate prevents two simultaneous receipt submissions
            // from both reading the same po_status / line quantities.
            // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §15
            $po = ProcurementRecord::lockForUpdate()->findOrFail($procurementId);

            abort_if(
                $po->po_type !== 'inventory',
                422,
                'This PO is a cattle PO. Use the cattle receipt flow.'
            );

            abort_if(
                in_array($po->po_status, ['draft', 'fully_received', 'force_closed']),
                422,
                __('messages.po_not_open_for_receipt')
            );

            abort_if(
                empty($lines),
                422,
                'No items provided for receipt.'
            );

            $receiptDate = $batchMeta['date'] ?? now()->toDateString();

            // ── STEP 2: Resolve open fiscal year ──────────────────
            // Hard block — receipt cannot post without a valid FY.
            // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §15
            $fiscalYear = FiscalYear::where('company_id', $po->company_id)
                ->where('status', 'open')
                ->whereDate('start_date', '<=', $receiptDate)
                ->whereDate('end_date',   '>=', $receiptDate)
                ->first();

            if ($fiscalYear === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'batch_date' => "No open fiscal year covers {$receiptDate}. Please change the receipt date or open a fiscal year that covers this date.",
                ]);
            }

            // ── STEP 3: Process each item line ────────────────────
            $batchSubtotal  = '0';       // bcmath accumulator
            $accountTotals  = [];        // keyed by account_code → bcmath string
            $receiptLineData = [];       // collected for batch insert after JE
            $transactionIds  = [];       // for JE write-back

            foreach ($lines as $lineInput) {

                // ── 3a: Lock PO item line ─────────────────────────
                $itemLine = ProcurementItemLine::lockForUpdate()
                    ->where('procurement_id', $po->id)
                    ->where('id', $lineInput['procurement_item_line_id'])
                    ->firstOrFail();

                abort_if(
                    $itemLine->line_status !== 'open',
                    422,
                    "Item line #{$itemLine->id} is no longer open for receipt (status: {$itemLine->line_status})."
                );

                $qtyReceiving    = (string) $lineInput['qty_received'];
                $unitCostActual  = (string) $lineInput['unit_cost_actual'];
                $locationId      = (int) $lineInput['location_id'];

                abort_if(
                    bccomp($qtyReceiving, '0', 3) <= 0,
                    422,
                    "Quantity received must be greater than zero for item line #{$itemLine->id}."
                );

                // ── 3b: Load master item (needed for auto-create and account routing)
                $masterItem = InventoryItem::lockForUpdate()
                    ->where('id', $itemLine->inventory_item_id)
                    ->where('is_master', true)
                    ->first();

                abort_unless(
                    $masterItem !== null,
                    422,
                    "Master inventory item #{$itemLine->inventory_item_id} not found."
                );

                // ── 3b: Resolve location stock row — auto-create if first receipt ──
                // When goods are received at a location for the first time, no
                // location-stock row exists yet. We create it on the fly (qty=0,
                // unit_cost=0) so the WAC calculation starts from a clean slate.
                // The lockForUpdate on the SELECT prevents race conditions on the
                // first-ever receipt, but firstOrCreate is still safe inside the
                // outer DB::transaction.
                $stockRow = InventoryItem::where('master_item_id', $masterItem->id)
                    ->where('location_id', $locationId)
                    ->where('is_master', false)
                    ->first();

                if ($stockRow === null) {
                    $location = \App\Models\InventoryLocation::findOrFail($locationId);

                    // company_id always comes from the PO — it is the single
                    // authoritative source set at PO creation time via resolveCompanyId().
                    $stockCompanyId = $po->company_id;

                    $stockRow = InventoryItem::create([
                        'company_id'     => $stockCompanyId,
                        'farm_id'        => $location->farm_id,
                        'master_item_id' => $masterItem->id,
                        'location_id'    => $locationId,
                        'is_master'      => false,
                        'name_en'        => $masterItem->name_en,
                        'name_ar'        => $masterItem->name_ar,
                        'sku'            => $masterItem->sku,
                        'category'       => $masterItem->category,
                        'unit'           => $masterItem->unit,
                        'unit_cost'      => 0,
                        'qty_on_hand'    => 0,
                        'reorder_point'  => $masterItem->reorder_point ?? 0,
                        'feed_item_id'   => $masterItem->feed_item_id,
                        'is_active'      => true,
                    ]);
                }

                // Now lock the row for the WAC update
                $stockRow = InventoryItem::lockForUpdate()->findOrFail($stockRow->id);

                // ── 3c: Capture pre-receipt values ────────────────
                // CRITICAL: capture BEFORE any write — never use refresh() after
                // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
                $qtyBefore  = (string) $stockRow->qty_on_hand;
                $wacBefore  = (string) $stockRow->unit_cost;

                // ── 3d: Compute line total ────────────────────────
                // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
                $lineTotal = bcmul($qtyReceiving, $unitCostActual, 4);
                $lineTotal = bcadd($lineTotal, '0', 2);  // round to cents

                // ── 3e: Compute new WAC ───────────────────────────
                // 📌 METHODOLOGY REFERENCE: WAC (#10)
                // WAC_new = (Q_old × W_old + Q_in × C_in) / (Q_old + Q_in)
                // Edge case: first receipt (qty_before = 0) → WAC_new = unit_cost_actual
                if (bccomp($qtyBefore, '0', 3) === 0) {
                    $wacNew = $unitCostActual;
                } else {
                    $numerator = bcadd(
                        bcmul($qtyBefore, $wacBefore, 6),
                        bcmul($qtyReceiving, $unitCostActual, 6),
                        6
                    );
                    $denominator = bcadd($qtyBefore, $qtyReceiving, 3);
                    $wacNew = bcdiv($numerator, $denominator, 6);
                }

                // ── 3f: Update stock row ──────────────────────────
                $newQty = bcadd($qtyBefore, $qtyReceiving, 3);
                $stockRow->qty_on_hand = (float) $newQty;
                $stockRow->unit_cost   = (float) bcadd($wacNew, '0', 4);
                $stockRow->save();

                // ── 3g: Create inventory transaction ─────────────
                // type = 'receipt' — participates in AP accrual matching
                // item_id = location stock row id (is_master=false)
                // ap_invoice_id = NULL → unmatched accrual
                // 📌 METHODOLOGY REFERENCE: InventoryReceiptAccrual (#22)
                $txn = InventoryTransaction::create([
                    'company_id'    => $po->company_id,
                    'farm_id'       => $po->farm_id,
                    'location_id'   => $locationId,
                    'item_id'       => $stockRow->id,
                    'type'          => 'receipt',
                    'date'          => $receiptDate,
                    'qty'           => (float) $qtyReceiving,
                    'unit_cost'     => (float) $unitCostActual,
                    'total'         => (float) $lineTotal,
                    'qty_after'     => (float) $newQty,
                    'wac_after'     => (float) bcadd($wacNew, '0', 4),
                    'ref_type'      => 'inventory_receipt_batch',
                    'supplier_id'   => $po->supplier_id,
                    'ref_no'        => $po->po_reference,
                    'ap_invoice_id' => null,   // unmatched — accountant will match later
                    'recorded_by'   => Auth::id(),
                    'notes'         => 'PO ' . ($po->po_reference ?? '#' . $po->id)
                                        . ' — ' . $masterItem->name_en,
                ]);

                $transactionIds[] = $txn->id;

                // ── 3h: Increment PO item line qty_received ───────
                $newQtyReceived = bcadd(
                    (string) $itemLine->qty_received,
                    $qtyReceiving,
                    3
                );
                $itemLine->qty_received     = (float) $newQtyReceived;
                $itemLine->unit_cost_actual = (float) $unitCostActual;
                $itemLine->total_actual     = (float) bcadd(
                    (string) $itemLine->total_actual,
                    $lineTotal,
                    2
                );

                // ── 3i: Auto-compute line status ──────────────────
                $itemLine->line_status = $this->computeLineStatus($itemLine);
                $itemLine->save();

                // ── Accumulate batch subtotal ─────────────────────
                $batchSubtotal = bcadd($batchSubtotal, $lineTotal, 2);

                // ── Accumulate journal entry grouping by account ──
                $accountCode = $this->resolveInventoryAccount($masterItem->category);
                $accountTotals[$accountCode] = bcadd(
                    $accountTotals[$accountCode] ?? '0',
                    $lineTotal,
                    2
                );

                // ── Collect receipt line data for batch creation ──
                $receiptLineData[] = [
                    'procurement_item_line_id' => $itemLine->id,
                    'inventory_item_id'        => $itemLine->inventory_item_id,
                     'location_id'              => $locationId,
                    'qty_received'             => (float) $qtyReceiving,
                    'unit_cost_actual'         => (float) $unitCostActual,
                    'total_actual'             => (float) $lineTotal,
                    'inventory_transaction_id' => $txn->id,
                    'wac_before'               => (float) $wacBefore,
                    'wac_after'                => (float) bcadd($wacNew, '0', 4),
                    'notes'                    => $lineInput['notes'] ?? null,
                ];
            }

            // ── STEP 4: Create receipt batch ──────────────────────
            $batch = InventoryReceiptBatch::create([
                'procurement_id' => $po->id,
                'company_id'     => $po->company_id,
                'farm_id'        => $po->farm_id,
                'date'           => $receiptDate,
                'status'         => 'confirmed',
                'subtotal'       => (float) $batchSubtotal,
                'created_by'     => Auth::id(),
                'notes'          => $batchMeta['notes'] ?? null,
            ]);

            // ── Create receipt lines ──────────────────────────────
            foreach ($receiptLineData as $rld) {
                InventoryReceiptLine::create([
                    'receipt_batch_id' => $batch->id,
                    ...$rld,
                ]);
            }

            // ── Write batch_id back to inventory_transactions ─────
            // ref_id on the transaction now points to the batch
            InventoryTransaction::whereIn('id', $transactionIds)
                ->update(['ref_id' => $batch->id]);

            // ── STEP 5: Post T1 Journal Entry ─────────────────────
            // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §8
            // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
            // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
            $je = $this->postReceiptJournalEntry(
                $po,
                $batch,
                $fiscalYear,
                $batchSubtotal,
                $accountTotals,
                $receiptDate
            );

            // ── STEP 6: Write JE id back to batch and transactions ─
            if ($je !== null) {
                $batch->update(['journal_entry_id' => $je->id]);

                InventoryTransaction::whereIn('id', $transactionIds)
                    ->update(['journal_entry_id' => $je->id]);
            }

            // ── STEP 7: Auto-compute PO status ────────────────────
            // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §19
            $po->po_status = $this->computePoStatus($po->id);
            $po->save();

            return $batch;
        });
    }

    /**
     * Force-close an inventory PO that will not receive its full ordered quantity.
     *
     * Guards:
     *   - po_type must be 'inventory'
     *   - At least one receipt batch must exist
     *   - closure_reason required, minimum 10 characters
     *   - po_status must NOT be 'fully_received' or 'draft'
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §12
     */
    public function forceClose(ProcurementRecord $po, string $reason): ProcurementRecord
    {
        abort_if(
            $po->po_type !== 'inventory',
            422,
            'This is not an inventory PO.'
        );

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
            $po->inventoryReceiptBatches()->count() === 0,
            422,
            __('messages.po_force_close_no_receipts')
        );

        abort_if(
            strlen(trim($reason)) < 10,
            422,
            __('messages.po_closure_reason_too_short')
        );

        DB::transaction(function () use ($po, $reason) {
            // Force-close all open item lines
            ProcurementItemLine::where('procurement_id', $po->id)
                ->where('line_status', 'open')
                ->update(['line_status' => 'force_closed']);

            $po->update([
                'po_status'      => 'force_closed',
                'closure_reason' => $reason,
                'closed_at'      => now(),
                'closed_by'      => Auth::id(),
            ]);
        });

        return $po->fresh();
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Resolve the Dr inventory account code by item category.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §7
     * Confirmed against ChartOfAccountsSeeder — all 4 codes exist.
     *
     * feed      → 1320  Feed Inventory
     * medicine  → 1330  Medicine Inventory
     * vaccine   → 1340  Vaccine Inventory
     * equipment, supply, other → 1350  Other Inventory
     */
    private function resolveInventoryAccount(string $category): string
    {
        return match($category) {
            'feed'     => '1320',
            'medicine' => '1330',
            'vaccine'  => '1340',
            default    => '1350',  // equipment, supply, other
        };
    }

    /**
     * Auto-compute line status from current qty_received vs qty_ordered.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §6
     */
    private function computeLineStatus(ProcurementItemLine $line): string
    {
        $received = (string) $line->qty_received;
        $ordered  = (string) $line->qty_ordered;

        $cmp = bccomp($received, $ordered, 3);

        if ($cmp > 0) return 'over_received';
        if ($cmp === 0) return 'fully_received';

        return 'open';
    }

    /**
     * Auto-compute PO status from all item line statuses.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §19
     */
    private function computePoStatus(int $procurementId): string
    {
        $lines = ProcurementItemLine::where('procurement_id', $procurementId)->get();

        if ($lines->isEmpty()) {
            return 'open';
        }

        $statuses = $lines->pluck('line_status')->unique()->toArray();

        // Any over_received line → PO is over_received
        if (in_array('over_received', $statuses)) {
            return 'over_received';
        }

        // All lines fully received or force_closed → PO complete
        $allDone = $lines->every(fn($l) =>
            in_array($l->line_status, ['fully_received', 'force_closed'])
        );

        if ($allDone) {
            // If any were force_closed, tag PO accordingly
            return in_array('force_closed', $statuses)
                ? 'force_closed'
                : 'fully_received';
        }

        // Any qty_received > 0 → partially received
        $anyReceived = $lines->contains(fn($l) =>
            bccomp((string) $l->qty_received, '0', 3) > 0
        );

        return $anyReceived ? 'partially_received' : 'open';
    }

    /**
     * Auto-post T1 journal entry for an inventory receipt batch.
     *
     * 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45) §8
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
     * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
     *
     * Dr lines grouped by account code (one Dr line per category present in batch).
     * Cr AP Accruals (2230) — single credit for the full batch subtotal.
     *
     * Soft-fails with a session warning if any account is missing.
     * The receipt itself is never rolled back due to a missing account.
     *
     * @param  array  $accountTotals  keyed by account_code → bcmath string amount
     * @return JournalEntry|null
     */
    private function postReceiptJournalEntry(
        ProcurementRecord    $po,
        InventoryReceiptBatch $batch,
        $fiscalYear,
        string               $batchSubtotal,
        array                $accountTotals,
        string               $receiptDate
    ): ?JournalEntry {

        $companyId = $po->company_id;

        // Resolve Cr account — AP Accruals (2230)
        $accrualAccount = Account::where('company_id', $companyId)
            ->where('code', '2230')
            ->first();

        if (!$accrualAccount) {
            session()->flash('warning',
                'Inventory receipt confirmed. Account 2230 (AP Accruals) not found — please post the accounting entry manually.'
            );
            return null;
        }

        // Resolve all Dr accounts and check they exist
        $drAccounts = [];
        foreach ($accountTotals as $code => $amount) {
            $account = Account::where('company_id', $companyId)
                ->where('code', $code)
                ->first();

            if (!$account) {
                session()->flash('warning',
                    "Inventory receipt confirmed. Account {$code} not found — please post the accounting entry manually."
                );
                return null;
            }

            $drAccounts[$code] = [
                'account'  => $account,
                'amount'   => $amount,
            ];
        }

        $reference  = 'INV-RCP/' . ($po->po_reference ?? $po->id) . '/B' . $batch->id;
        $memo       = 'Inventory Receipt — ' . ($po->po_reference ?? 'PO#' . $po->id)
                    . ' — ' . ($po->supplier?->name_en ?? 'Supplier');

        // 📌 BcmathMonetaryPrecision: total_debit = total_credit = batch subtotal
        $storedAmount = (float) bcadd($batchSubtotal, '0', 2);

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $receiptDate,
            'reference'      => $reference,
            'description'    => $memo,
            'period'         => (int) date('n', strtotime($receiptDate)),
            'status'         => 'posted',
            'entry_type'     => 'procurement',
            'source_type'    => InventoryReceiptBatch::class,
            'source_id'      => $batch->id,
            'total_debit'    => $storedAmount,
            'total_credit'   => $storedAmount,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr lines — one per account code (grouped)
        foreach ($drAccounts as $code => $data) {
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $data['account']->id,
                'debit'      => (float) bcadd($data['amount'], '0', 2),
                'credit'     => 0,
                'farm_id'    => $po->farm_id,
                'memo'       => $memo . ' — ' . $code,
            ]);
        }

        // Cr AP Accruals (2230) — single credit for full batch subtotal
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $accrualAccount->id,
            'debit'      => 0,
            'credit'     => $storedAmount,
            'farm_id'    => $po->farm_id,
            'memo'       => $memo,
        ]);

        return $je;
    }
}