<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AnimalReceiptBatch;
use App\Models\FiscalYear;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retroactively post missing T1 journal entries for livestock receipt batches.
 *
 * A T1 entry is missing when:
 *   - animal_receipt_batches row exists (confirmed)
 *   - The linked inventory_transaction has journal_entry_id = NULL
 *
 * This is exactly what LivestockReceiptService::postReceiptJournalEntry() does,
 * re-implemented here as a safe, idempotent repair command.
 *
 * Usage:
 *   php artisan vero:post-livestock-receipt-journals          ← dry run (default)
 *   php artisan vero:post-livestock-receipt-journals --commit ← actually post
 *   php artisan vero:post-livestock-receipt-journals --company=3 --commit
 */
class PostMissingLivestockReceiptJournals extends Command
{
    protected $signature = 'vero:post-livestock-receipt-journals
                            {--company= : Limit to a specific company_id}
                            {--commit   : Actually write journal entries (default is dry-run)}';

    protected $description = 'Retroactively post missing T1 journal entries (Dr 1310 / Cr 2230) for confirmed livestock receipt batches that have no accounting entry.';

    public function handle(): int
    {
        $isDryRun  = !$this->option('commit');
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        if ($isDryRun) {
            $this->warn('DRY RUN — no entries will be written. Add --commit to post.');
        } else {
            $this->info('COMMIT MODE — journal entries will be posted.');
        }

        $this->line('');

        // ── Find all receipt batches whose linked inventory_transaction
        //    has journal_entry_id = NULL
        $query = AnimalReceiptBatch::query()
            ->where('status', 'confirmed')
            ->whereHas('inventoryTransaction', fn($q) =>
                $q->whereNull('journal_entry_id')
            )
            ->with(['inventoryTransaction', 'procurement']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $batches = $query->get();

        if ($batches->isEmpty()) {
            $this->info('✅ No missing T1 journal entries found. Everything is clean.');
            return 0;
        }

        $this->info("Found {$batches->count()} batch(es) missing T1 journal entries:");
        $this->line('');

        $posted  = 0;
        $skipped = 0;

        foreach ($batches as $batch) {
            $txn       = $batch->inventoryTransaction;
            $cid       = $batch->company_id;
            $amount    = (string) $batch->subtotal;
            $batchDate = is_string($batch->date)
                ? $batch->date
                : $batch->date->toDateString();

            $this->line("Batch #{$batch->id} | Company {$cid} | Date {$batchDate} | Amount {$amount} EGP");

            // ── Guard 1: accounts must exist
            $wipAccount     = Account::where('company_id', $cid)->where('code', '1310')->first();
            $accrualAccount = Account::where('company_id', $cid)->where('code', '2230')->first();

            if (!$wipAccount || !$accrualAccount) {
                $this->error("  ✗ SKIP — Account 1310 or 2230 not found for company {$cid}");
                $skipped++;
                continue;
            }

            // ── Guard 2: open fiscal year must cover batch date
            $fiscalYear = FiscalYear::where('company_id', $cid)
                ->where('status', 'open')
                ->whereDate('start_date', '<=', $batchDate)
                ->whereDate('end_date',   '>=', $batchDate)
                ->first();

            if (!$fiscalYear) {
                $this->error("  ✗ SKIP — No open fiscal year covers {$batchDate} for company {$cid}");
                $skipped++;
                continue;
            }

            // ── Guard 3: transaction row must exist
            if (!$txn) {
                $this->error("  ✗ SKIP — No linked inventory_transaction found for batch #{$batch->id}");
                $skipped++;
                continue;
            }

            $po        = $batch->procurement;
            $reference = 'LR/' . ($po?->po_reference ?? 'PO#' . $batch->procurement_id) . '/B' . $batch->id;
            $memo      = 'Livestock receipt — ' . ($po?->po_reference ?? 'PO#' . $batch->procurement_id)
                       . ' — ' . $batch->received_head . ' head';

            $this->line("  → Will post: Dr 1310 ({$wipAccount->id}) / Cr 2230 ({$accrualAccount->id}) = {$amount} EGP");
            $this->line("  → Reference: {$reference} | FY: {$fiscalYear->id}");

            if ($isDryRun) {
                $this->warn("  ↷ DRY RUN — skipping write");
                $posted++;
                continue;
            }

            // ── Post the JE inside a transaction
            try {
                DB::transaction(function () use (
                    $batch, $txn, $wipAccount, $accrualAccount,
                    $fiscalYear, $cid, $amount, $batchDate,
                    $reference, $memo, $po
                ) {
                    // Re-lock the transaction row to prevent race conditions
                    $freshTxn = InventoryTransaction::lockForUpdate()->findOrFail($txn->id);

                    // Idempotency check — another process may have posted already
                    if ($freshTxn->journal_entry_id !== null) {
                        $this->line("  ↷ Already has JE #{$freshTxn->journal_entry_id} — skipping");
                        return;
                    }

                    $roundedAmount = (float) \App\Support\Money::round($amount, 2);

                    $je = JournalEntry::create([
                        'company_id'     => $cid,
                        'fiscal_year_id' => $fiscalYear->id,
                        'date'           => $batchDate,
                        'reference'      => $reference,
                        'description'    => $memo,
                        'period'         => (int) date('n', strtotime($batchDate)),
                        'status'         => 'posted',
                        'entry_type'     => 'procurement',
                        'source_type'    => AnimalReceiptBatch::class,
                        'source_id'      => $batch->id,
                        'total_debit'    => $roundedAmount,
                        'total_credit'   => $roundedAmount,
                        'posted_by'      => 1,   // system — super admin user id
                        'posted_at'      => now(),
                    ]);

                    // Dr Livestock WIP (1310)
                    JournalLine::create([
                        'entry_id'   => $je->id,
                        'account_id' => $wipAccount->id,
                        'debit'      => $roundedAmount,
                        'credit'     => 0,
                        'farm_id'    => $batch->farm_id,
                        'lot_id'     => $batch->lot_id,
                        'memo'       => $memo,
                    ]);

                    // Cr AP Accruals (2230)
                    JournalLine::create([
                        'entry_id'   => $je->id,
                        'account_id' => $accrualAccount->id,
                        'debit'      => 0,
                        'credit'     => $roundedAmount,
                        'farm_id'    => $batch->farm_id,
                        'memo'       => $memo,
                    ]);

                    // Link JE back to the inventory_transaction
                    $freshTxn->update(['journal_entry_id' => $je->id]);
                });

                $this->info("  ✓ Posted JE successfully for batch #{$batch->id}");
                $posted++;

            } catch (\Throwable $e) {
                $this->error("  ✗ FAILED for batch #{$batch->id}: " . $e->getMessage());
                $skipped++;
            }

            $this->line('');
        }

        $this->line('');
        $this->line('─────────────────────────────');

        if ($isDryRun) {
            $this->warn("DRY RUN complete. {$posted} entries would be posted, {$skipped} would be skipped.");
            $this->warn("Run with --commit to actually post them.");
        } else {
            $this->info("Done. {$posted} entries posted, {$skipped} skipped.");
        }

        return 0;
    }
}