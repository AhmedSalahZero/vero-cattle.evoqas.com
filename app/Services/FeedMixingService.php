<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FeedMixingBatch;
use App\Models\FeedMixingLine;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FeedMixingService
//
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//
//  Optional intermediate stage between raw inventory and feeding.
//
//    START   raw stock ──► Feed Mixing WIP (1360)      [mix_out + JE]
//    FINISH  WIP (1360) ──► finished mix in stock 1320 [mix_in  + JE]
//    CANCEL  undo START while still in progress        [mix_in  + reversing JE]
//
//  Rules (all enforced here, never in the controller):
//    • Unit costs always come from the stock row's WAC — never from user input
//    • bcmath only for money and costs (BcmathMonetaryPrecision #38)
//    • lockForUpdate() on the batch and every stock row touched
//    • Hard blocks (insufficient stock, missing accounts, no open fiscal
//      year) throw a ValidationException BEFORE any stock moves — the whole
//      DB::transaction rolls back, so nothing is ever half-done
//    • Posted journal entries are never edited or deleted: cancel posts a
//      mirror entry, and both stay 'posted' so reports net to zero
// ══════════════════════════════════════════════════════════════════

class FeedMixingService
{
    private const WIP_ACCOUNT  = '1360';
    private const FEED_ACCOUNT = '1320';

    // ══════════════════════════════════════════════════════════════
    //  PURE MATHS — no database access. Unit-testable on their own.
    // ══════════════════════════════════════════════════════════════

    /**
     * Round a bcmath string to 2 decimals, half away from zero.
     * (bcadd alone truncates, which would drift a cent per line.)
     */
    public function round2(string $value): string
    {
        return \App\Support\Money::round($value, 2);
    }

    /** Ingredient cost = qty × WAC, rounded to cents. */
    public function lineCost(string $qty, string $unitCost): string
    {
        return $this->round2(bcmul($qty, $unitCost, 6));
    }

    /** Cost per kg of finished mix = batch cost ÷ finished kg (scale 4). */
    public function costPerKg(string $totalCost, string $finishedKg): string
    {
        return $this->round4(bcdiv($totalCost, $finishedKg, 8));
    }

    /**
     * New weighted-average cost after adding the batch to existing stock.
     * Uses the EXACT batch cost (not finished_kg × rounded cost_per_kg)
     * so the value entering stock always equals the value leaving WIP.
     */
    public function newWac(string $qtyBefore, string $wacBefore, string $batchCost, string $finishedKg): string
    {
        $numerator   = bcadd(bcmul($qtyBefore, $wacBefore, 8), $batchCost, 8);
        $denominator = bcadd($qtyBefore, $finishedKg, 3);
        return $this->round4(bcdiv($numerator, $denominator, 8));
    }

    /** WAC after returning ingredient qty at a given cost (used by cancel). */
    public function wacAfterReturn(string $qtyNow, string $wacNow, string $qtyBack, string $costBack): string
    {
        $numerator   = bcadd(bcmul($qtyNow, $wacNow, 8), bcmul($qtyBack, $costBack, 8), 8);
        $denominator = bcadd($qtyNow, $qtyBack, 3);
        return $this->round4(bcdiv($numerator, $denominator, 8));
    }

    private function round4(string $value): string
    {
        return \App\Support\Money::round($value, 4);
    }

    // ══════════════════════════════════════════════════════════════
    //  START — ingredients leave raw stock and enter WIP
    // ══════════════════════════════════════════════════════════════

    public function start(FeedMixingBatch $batch): FeedMixingBatch
    {
        return DB::transaction(function () use ($batch) {

            $fresh = FeedMixingBatch::lockForUpdate()->findOrFail($batch->id);

            if ($fresh->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'This batch is not a draft — it may already have been started.',
                ]);
            }

            $lines = FeedMixingLine::where('batch_id', $fresh->id)
                ->orderBy('item_id')            // fixed lock order → no deadlocks
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one ingredient before starting.',
                ]);
            }

            $date      = $fresh->start_date->toDateString();
            $companyId = (int) $fresh->company_id;

            // ── Checks that must pass BEFORE any stock moves ──────
            $fiscalYear = $this->requireOpenFiscalYear($companyId, $date, 'start_date');
            $wip        = $this->requireAccount($companyId, self::WIP_ACCOUNT);

            $accountsByCode = [];            // code => Account
            $totalCost      = '0.00';
            $totalKg        = '0.000';
            $drafted        = [];            // computed lines, applied after all checks pass

            foreach ($lines as $line) {
                $master = InventoryItem::where('company_id', $companyId)
                    ->where('is_master', true)
                    ->findOrFail($line->item_id);

                $qty = (string) $line->qty;
                if (bccomp($qty, '0', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'Every ingredient quantity must be greater than zero.',
                    ]);
                }

                $stock = InventoryItem::where('master_item_id', $master->id)
                    ->where('location_id', $fresh->location_id)
                    ->where('company_id', $companyId)
                    ->where('is_master', false)
                    ->lockForUpdate()
                    ->first();

                $name = $master->name_en ?: $master->name_ar;

                if (!$stock) {
                    throw ValidationException::withMessages([
                        'lines' => "No stock of \"{$name}\" exists at this location.",
                    ]);
                }

                if (bccomp((string) $stock->qty_on_hand, $qty, 3) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => "Not enough \"{$name}\": available {$stock->qty_on_hand}, needed {$qty}.",
                    ]);
                }

                $code = $this->resolveInventoryAccount((string) $master->category);
                if (!isset($accountsByCode[$code])) {
                    $accountsByCode[$code] = $this->requireAccount($companyId, $code);
                }

                $unitCost = (string) $stock->unit_cost;                  // server WAC
                $cost     = $this->lineCost($qty, $unitCost);

                $totalCost = bcadd($totalCost, $cost, 2);
                if ($master->unit === 'kg') {
                    $totalKg = bcadd($totalKg, $qty, 3);
                }

                $drafted[] = compact('line', 'master', 'stock', 'qty', 'unitCost', 'cost', 'code');
            }

            if (bccomp($totalCost, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'lines' => 'The ingredients have no cost. Check the stock cost of each item before mixing.',
                ]);
            }

            // ── Apply: decrement stock, write mix_out rows ────────
            $creditTotals = [];             // code => string amount
            foreach ($drafted as $d) {
                $stock    = $d['stock'];
                $qtyAfter = bcsub((string) $stock->qty_on_hand, $d['qty'], 3);

                $stock->qty_on_hand = $qtyAfter;
                $stock->save();

                InventoryTransaction::create([
                    'company_id'  => $companyId,
                    'farm_id'     => $fresh->farm_id,
                    'location_id' => $fresh->location_id,
                    'item_id'     => $stock->id,
                    'type'        => 'mix_out',
                    'date'        => $date,
                    'qty'         => $d['qty'],
                    'unit_cost'   => $d['unitCost'],
                    'total'       => $d['cost'],
                    'qty_after'   => $qtyAfter,
                    'wac_after'   => $d['unitCost'],       // WAC unchanged on an outflow
                    'ref_type'    => FeedMixingBatch::class,
                    'ref_id'      => $fresh->id,
                    'recorded_by' => Auth::id(),
                    'notes'       => 'Feed mixing — ' . ($fresh->reference ?? 'batch #' . $fresh->id),
                ]);

                $d['line']->update([
                    'stock_row_id' => $stock->id,
                    'unit_cost'    => $d['unitCost'],
                    'total_cost'   => $d['cost'],
                    'account_code' => $d['code'],
                ]);

                $creditTotals[$d['code']] = bcadd($creditTotals[$d['code']] ?? '0.00', $d['cost'], 2);
            }

            // ── Journal: Dr 1360 / Cr ingredient accounts ─────────
            $je = $this->postJournal(
                companyId:   $companyId,
                fiscalYear:  $fiscalYear,
                date:        $date,
                reference:   'MIX-START/' . ($fresh->reference ?? $fresh->id),
                description: 'Feed mixing started — ' . ($fresh->reference ?? 'batch #' . $fresh->id),
                entryType:   'feed_mixing',
                batch:       $fresh,
                debits:      [[$wip->id, $totalCost]],
                credits:     collect($creditTotals)->map(
                    fn ($amount, $code) => [$accountsByCode[$code]->id, $amount]
                )->values()->all(),
            );

            $fresh->update([
                'status'                 => 'in_progress',
                'total_cost'             => $totalCost,
                'total_ingredient_kg'    => $totalKg,
                'start_journal_entry_id' => $je->id,
            ]);

            return $fresh->fresh(['lines']);
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  FINISH — WIP becomes finished mix in stock
    // ══════════════════════════════════════════════════════════════

    public function finish(FeedMixingBatch $batch, string $finishedKg, ?string $finishDate = null): FeedMixingBatch
    {
        return DB::transaction(function () use ($batch, $finishedKg, $finishDate) {

            $fresh = FeedMixingBatch::lockForUpdate()->findOrFail($batch->id);

            if ($fresh->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'Only a batch that is in progress can be finished.',
                ]);
            }

            if (!is_numeric($finishedKg) || bccomp($finishedKg, '0', 3) <= 0) {
                throw ValidationException::withMessages([
                    'finished_qty_kg' => 'Finished quantity must be greater than zero. To abandon the batch, cancel it instead.',
                ]);
            }
            $finishedKg = bcadd($finishedKg, '0', 3);

            $date = $finishDate ?: now()->toDateString();
            if ($date < $fresh->start_date->toDateString()) {
                throw ValidationException::withMessages([
                    'finish_date' => 'Finish date cannot be before the start date.',
                ]);
            }

            $companyId  = (int) $fresh->company_id;
            $fiscalYear = $this->requireOpenFiscalYear($companyId, $date, 'finish_date');
            $wip        = $this->requireAccount($companyId, self::WIP_ACCOUNT);
            $feedAcct   = $this->requireAccount($companyId, self::FEED_ACCOUNT);

            // ── The output mix item and its stock row at this location ──
            $master = InventoryItem::where('company_id', $companyId)
                ->where('is_master', true)
                ->findOrFail($fresh->output_item_id);

            $stock = InventoryItem::where('master_item_id', $master->id)
                ->where('location_id', $fresh->location_id)
                ->where('company_id', $companyId)
                ->where('is_master', false)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                // Same self-healing rule used by InventoryReceiptService
                $location = InventoryLocation::findOrFail($fresh->location_id);
                $stock = InventoryItem::create([
                    'company_id'     => $companyId,
                    'farm_id'        => $location->farm_id,
                    'master_item_id' => $master->id,
                    'location_id'    => $fresh->location_id,
                    'is_master'      => false,
                    'category'       => $master->category,
                    'unit'           => $master->unit,
                    'unit_cost'      => 0,
                    'qty_on_hand'    => 0,
                    'is_active'      => true,
                ]);
                $stock = InventoryItem::lockForUpdate()->findOrFail($stock->id);
            }

            $batchCost = (string) $fresh->total_cost;
            $costPerKg = $this->costPerKg($batchCost, $finishedKg);

            $qtyBefore = (string) $stock->qty_on_hand;
            $wacBefore = (string) $stock->unit_cost;

            $wacNew = bccomp($qtyBefore, '0', 3) <= 0
                ? $costPerKg                                   // first receipt into empty stock
                : $this->newWac($qtyBefore, $wacBefore, $batchCost, $finishedKg);

            $qtyAfter = bcadd($qtyBefore, $finishedKg, 3);

            $stock->qty_on_hand = $qtyAfter;
            $stock->unit_cost   = $wacNew;
            $stock->save();

            InventoryTransaction::create([
                'company_id'  => $companyId,
                'farm_id'     => $fresh->farm_id,
                'location_id' => $fresh->location_id,
                'item_id'     => $stock->id,
                'type'        => 'mix_in',
                'date'        => $date,
                'qty'         => $finishedKg,
                'unit_cost'   => $costPerKg,
                'total'       => $batchCost,
                'qty_after'   => $qtyAfter,
                'wac_after'   => $wacNew,
                'ref_type'    => FeedMixingBatch::class,
                'ref_id'      => $fresh->id,
                'recorded_by' => Auth::id(),
                'notes'       => 'Feed mixing finished — ' . ($fresh->reference ?? 'batch #' . $fresh->id),
            ]);

            $je = $this->postJournal(
                companyId:   $companyId,
                fiscalYear:  $fiscalYear,
                date:        $date,
                reference:   'MIX-FINISH/' . ($fresh->reference ?? $fresh->id),
                description: 'Feed mixing finished — ' . ($fresh->reference ?? 'batch #' . $fresh->id),
                entryType:   'feed_mixing',
                batch:       $fresh,
                debits:      [[$feedAcct->id, $batchCost]],
                credits:     [[$wip->id, $batchCost]],
            );

            $fresh->update([
                'status'                  => 'completed',
                'finish_date'             => $date,
                'finished_qty_kg'         => $finishedKg,
                'cost_per_kg'             => $costPerKg,
                'finish_journal_entry_id' => $je->id,
            ]);

            return $fresh->fresh(['lines']);
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  CANCEL — undo START (only while in progress)
    // ══════════════════════════════════════════════════════════════

    public function cancel(FeedMixingBatch $batch, ?string $cancelDate = null): FeedMixingBatch
    {
        return DB::transaction(function () use ($batch, $cancelDate) {

            $fresh = FeedMixingBatch::lockForUpdate()->findOrFail($batch->id);

            if ($fresh->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'Only a batch that is in progress can be cancelled.',
                ]);
            }

            $date       = $cancelDate ?: now()->toDateString();
            $companyId  = (int) $fresh->company_id;

            if ($date < $fresh->start_date->toDateString()) {
                throw ValidationException::withMessages([
                    'cancel_date' => 'Cancel date cannot be before the start date.',
                ]);
            }

            $fiscalYear = $this->requireOpenFiscalYear($companyId, $date, 'cancel_date');

            $original = JournalEntry::with('lines')->findOrFail($fresh->start_journal_entry_id);

            $lines = FeedMixingLine::where('batch_id', $fresh->id)->orderBy('item_id')->get();

            // ── Return each ingredient at the cost it left at ─────
            foreach ($lines as $line) {
                $stock = InventoryItem::lockForUpdate()->findOrFail($line->stock_row_id);

                $qtyNow   = (string) $stock->qty_on_hand;
                $wacNow   = (string) $stock->unit_cost;
                $qtyBack  = (string) $line->qty;
                $costBack = (string) $line->unit_cost;

                $wacNew   = bccomp($qtyNow, '0', 3) <= 0
                    ? $costBack
                    : $this->wacAfterReturn($qtyNow, $wacNow, $qtyBack, $costBack);
                $qtyAfter = bcadd($qtyNow, $qtyBack, 3);

                $stock->qty_on_hand = $qtyAfter;
                $stock->unit_cost   = $wacNew;
                $stock->save();

                InventoryTransaction::create([
                    'company_id'  => $companyId,
                    'farm_id'     => $fresh->farm_id,
                    'location_id' => $fresh->location_id,
                    'item_id'     => $stock->id,
                    'type'        => 'mix_in',
                    'date'        => $date,
                    'qty'         => $qtyBack,
                    'unit_cost'   => $costBack,
                    'total'       => (string) $line->total_cost,
                    'qty_after'   => $qtyAfter,
                    'wac_after'   => $wacNew,
                    'ref_type'    => FeedMixingBatch::class,
                    'ref_id'      => $fresh->id,
                    'recorded_by' => Auth::id(),
                    'notes'       => 'Feed mixing CANCELLED — ingredient returned — '
                                     . ($fresh->reference ?? 'batch #' . $fresh->id),
                ]);
            }

            // ── Mirror journal entry (original stays 'posted') ─────
            $creditsOfReversal = [];
            $debitsOfReversal  = [];
            foreach ($original->lines as $l) {
                if (bccomp((string) $l->debit, '0', 2) > 0) {
                    $creditsOfReversal[] = [$l->account_id, (string) $l->debit];
                }
                if (bccomp((string) $l->credit, '0', 2) > 0) {
                    $debitsOfReversal[] = [$l->account_id, (string) $l->credit];
                }
            }

            $rev = $this->postJournal(
                companyId:   $companyId,
                fiscalYear:  $fiscalYear,
                date:        $date,
                reference:   'REV/' . $original->reference,
                description: 'Reversal of: ' . $original->description,
                entryType:   'reversal',
                batch:       $fresh,
                debits:      $debitsOfReversal,
                credits:     $creditsOfReversal,
            );

            $fresh->update([
                'status'                 => 'cancelled',
                'cancel_journal_entry_id' => $rev->id,
            ]);

            return $fresh->fresh(['lines']);
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════

    /** feed → 1320, medicine → 1330, vaccine → 1340, everything else → 1350. */
    public function resolveInventoryAccount(string $category): string
    {
        return match ($category) {
            'feed'     => '1320',
            'medicine' => '1330',
            'vaccine'  => '1340',
            default    => '1350',
        };
    }

    private function requireOpenFiscalYear(int $companyId, string $date, string $field): FiscalYear
    {
        $fy = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if (!$fy) {
            throw ValidationException::withMessages([
                $field => "No open fiscal year covers {$date}. Open or create the fiscal year first.",
            ]);
        }
        return $fy;
    }

    private function requireAccount(int $companyId, string $code): Account
    {
        $account = Account::where('company_id', $companyId)->where('code', $code)->first();

        if (!$account) {
            throw ValidationException::withMessages([
                'accounts' => "Account {$code} was not found in the chart of accounts. "
                            . 'Run the latest database update, then try again.',
            ]);
        }
        return $account;
    }

    /**
     * Post a balanced journal entry. Amounts are bcmath strings.
     * Throws (rolling the whole transaction back) if debits ≠ credits.
     *
     * @param array<int,array{0:int,1:string}> $debits  [account_id, amount]
     * @param array<int,array{0:int,1:string}> $credits [account_id, amount]
     */
    private function postJournal(
        int $companyId,
        FiscalYear $fiscalYear,
        string $date,
        string $reference,
        string $description,
        string $entryType,
        FeedMixingBatch $batch,
        array $debits,
        array $credits
    ): JournalEntry {

        $totalDr = '0.00';
        foreach ($debits as [, $amt]) { $totalDr = bcadd($totalDr, $amt, 2); }
        $totalCr = '0.00';
        foreach ($credits as [, $amt]) { $totalCr = bcadd($totalCr, $amt, 2); }

        // 📌 JournalBalanceValidation (#1): |Σdr − Σcr| ≤ 0.005
        if (bccomp(ltrim(bcsub($totalDr, $totalCr, 4), '-'), '0.0050', 4) > 0) {
            throw ValidationException::withMessages([
                'accounts' => 'Internal error: the journal entry does not balance. Nothing was saved.',
            ]);
        }

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $date,
            'reference'      => $reference,
            'description'    => $description,
            'period'         => (int) date('n', strtotime($date)),
            'status'         => 'posted',
            'entry_type'     => $entryType,
            'source_type'    => FeedMixingBatch::class,
            'source_id'      => $batch->id,
            'total_debit'    => $totalDr,
            'total_credit'   => $totalCr,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        foreach ($debits as [$accountId, $amt]) {
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $accountId,
                'debit'      => $amt,
                'credit'     => 0,
                'farm_id'    => $batch->farm_id,
                'memo'       => $description,
            ]);
        }
        foreach ($credits as [$accountId, $amt]) {
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $accountId,
                'debit'      => 0,
                'credit'     => $amt,
                'farm_id'    => $batch->farm_id,
                'memo'       => $description,
            ]);
        }

        return $je;
    }
}
