<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FarmCostAllocation;
use App\Models\FarmOverheadLine;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO — Farm Cost Allocation Service
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//  📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//
//  Owns all calculations for monthly farm labor and overhead
//  absorption into Livestock WIP (1310).
//
//  Three public methods:
//    computeAnimalDays()      — pure calculation, no writes
//    postMonthEndAccrual()    — posts Dr WIP + Dr COGS / Cr Accruals
//    settleActual()           — posts Dr Accruals / Cr Cash ± Variance
//
//  The COGS read method:
//    laborOverheadCostForAnimal() — called by LivestockIntelligenceService
//
//  All monetary arithmetic uses bcmath (#39).
//  No writes occur outside DB::transaction().
// ══════════════════════════════════════════════════════════════════

class FarmCostAllocationService
{
    // ──────────────────────────────────────────────────────────────
    // PUBLIC — Compute animal-days for a farm/month
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §6
    //
    // Returns array keyed by animal_id → days_in_month.
    // Also returns total_animal_days as a summary value.
    //
    // Pure read — no writes. Used for preview display and post.
    // ──────────────────────────────────────────────────────────────

    public function computeAnimalDays(int $farmId, int $companyId, string $monthStart, string $monthEnd): array
    {
        // 📌 FarmLaborOverheadAbsorption §6 — exact formula:
        // days = MIN(exited_at, month_end) - MAX(entered_at, month_start) + 1
        // clamped to >= 0 via HAVING clause
        //
        // Source: animal_lot_history joined to lots to obtain farm_id
        // exited_at = NULL means animal still alive → substitute month_end

        $rows = DB::table('animal_lot_history as alh')
            ->join('lots as l', 'l.id', '=', 'alh.lot_id')
            ->where('l.farm_id', $farmId)
            ->where('l.company_id', $companyId)
            ->where('alh.entered_at', '<=', $monthEnd)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('alh.exited_at')
                  ->orWhere('alh.exited_at', '>=', $monthStart);
            })
            ->select(
                'alh.animal_id',
                // NOTE: positional `?` placeholders only. The query builder always uses
                // positional bindings for the other conditions, and MySQL/PDO refuses to
                // mix `?` with named `:placeholders` in one statement (error HY093).
                DB::raw("
                    DATEDIFF(
                        LEAST(COALESCE(alh.exited_at, ?), ?),
                        GREATEST(alh.entered_at, ?)
                    ) + 1 AS days_in_month
                ")
            )
            ->addBinding([$monthEnd, $monthEnd, $monthStart], 'select')
            ->havingRaw('days_in_month > 0')
            ->get();

        $animalDays   = [];
        $totalDays    = 0;

        foreach ($rows as $row) {
            $days = max(0, (int) $row->days_in_month);
            $animalDays[$row->animal_id] = $days;
            $totalDays += $days;
        }

        return [
            'animal_days'       => $animalDays,       // [animal_id => days]
            'total_animal_days' => $totalDays,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLIC — Post month-end accrual journal entry
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §9, §20
    //
    // Posts one journal entry covering labor + all overhead types:
    //   Dr  Livestock WIP (1310)         [alive animals' share]
    //   Dr  COGS — Animal Cost (5100)    [exited animals' share]
    //   Cr  Accrued Labor (2260)         [labor_estimated]
    //   Cr  Accrued Electricity (2271)   [electricity estimated]
    //   ... (one Cr per overhead type)
    //
    // Stores total_animal_days and rate_per_animal_day for audit.
    // Idempotent: aborts if labor_journal_entry_id already set.
    // ──────────────────────────────────────────────────────────────

    public function postMonthEndAccrual(FarmCostAllocation $allocation, int $postedByUserId): JournalEntry
    {
        return DB::transaction(function () use ($allocation, $postedByUserId) {

            // ── Idempotency guard ─────────────────────────────────
            $fresh = FarmCostAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($fresh->labor_journal_entry_id !== null) {
                throw new \RuntimeException('Already posted — labor_journal_entry_id is set.');
            }

            if ($fresh->status !== 'draft') {
                throw new \RuntimeException('Cannot post — status is ' . $fresh->status);
            }

            // ── Fiscal year guard ─────────────────────────────────
            $fy = FiscalYear::where('company_id', $fresh->company_id)
                ->where('status', 'open')
                ->where('start_date', '<=', $fresh->month_end)
                ->where('end_date', '>=', $fresh->month_end)
                ->first();

            if (!$fy) {
                throw new \RuntimeException(
                    'No open fiscal year covers ' . $fresh->month_end->toDateString()
                );
            }

            // ── Compute animal-days ───────────────────────────────
            $dayData       = $this->computeAnimalDays(
                $fresh->farm_id,
                $fresh->company_id,
                $fresh->month_start->toDateString(),
                $fresh->month_end->toDateString()
            );
            $animalDays    = $dayData['animal_days'];       // [animal_id => days]
            $totalDays     = $dayData['total_animal_days'];

            if ($totalDays <= 0) {
                throw new \RuntimeException(
                    'No animal activity recorded on this farm for ' . $fresh->monthLabel() . '. Cannot post.'
                );
            }

            // ── Identify animals that exited during this month ────
            // These animals' shares go to COGS, not WIP.
            // Exited = status in sold/slaughtered/dead AND status_date in this month.
            $exitedAnimalIds = DB::table('animals')
                ->where('farm_id', $fresh->farm_id)
                ->where('company_id', $fresh->company_id)
                ->whereIn('status', ['sold', 'slaughtered', 'dead'])
                ->where('status_date', '>=', $fresh->month_start->toDateString())
                ->where('status_date', '<=', $fresh->month_end->toDateString())
                ->whereNull('deleted_at')
                ->pluck('id')
                ->flip()   // use as a set for O(1) lookup
                ->all();

            // ── Resolve accounts ──────────────────────────────────
            $wipAccount  = $this->requireAccount($fresh->company_id, '1310');
            $cogsAccount = $this->requireAccount($fresh->company_id, '5100');
            $laborAccrualAccount = $this->requireAccount($fresh->company_id, '2260');

            // ── Labor — partition WIP vs COGS ─────────────────────
            $laborEstimated  = (string) $fresh->labor_estimated;
            $laborRatePerDay = (bccomp((string) $totalDays, '0', 0) > 0)
                ? bcdiv($laborEstimated, (string) $totalDays, 8)
                : '0';

            [$laborWip, $laborCogs] = $this->partitionShares(
                $laborRatePerDay,
                $animalDays,
                $exitedAnimalIds,
                $laborEstimated
            );

            // ── Overhead — partition WIP vs COGS per type ─────────
            $fresh->load('overheadLines.overheadType');

            $overheadPartitions = [];   // overhead_type_id → [wip, cogs, estimated, accounts]
            foreach ($fresh->overheadLines as $line) {
                $ohType      = $line->overheadType;
                $ohEstimated = (string) $line->estimated_amount;
                $ohRate      = (bccomp((string) $totalDays, '0', 0) > 0)
                    ? bcdiv($ohEstimated, (string) $totalDays, 8)
                    : '0';

                [$ohWip, $ohCogs] = $this->partitionShares(
                    $ohRate,
                    $animalDays,
                    $exitedAnimalIds,
                    $ohEstimated
                );

                $overheadPartitions[$line->overhead_type_id] = [
                    'wip'       => $ohWip,
                    'cogs'      => $ohCogs,
                    'estimated' => $ohEstimated,
                    'accrued_account_id'  => $ohType->accrued_account_id,
                    'type_name' => $ohType->name_en ?? ('Overhead #' . $line->overhead_type_id),
                ];
            }

            // ── Build journal lines ───────────────────────────────
            $lines = [];
            $monthLabel = $fresh->monthLabel();
            $farmName = optional($fresh->farm)->name_en
                        ?? optional($fresh->farm)->name_ar
                        ?? 'Farm #' . $fresh->farm_id;

            // Dr WIP — labor share for alive animals
            if (bccomp($laborWip, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $wipAccount->id,
                    'debit'      => (float) $laborWip,
                    'credit'     => 0,
                    'farm_id'    => $fresh->farm_id,
                    'memo'       => "Labor — alive animals — {$monthLabel}",
                ];
            }

            // Dr COGS — labor share for exited animals
            if (bccomp($laborCogs, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $cogsAccount->id,
                    'debit'      => (float) $laborCogs,
                    'credit'     => 0,
                    'farm_id'    => $fresh->farm_id,
                    'memo'       => "Labor — sold/slaughtered animals — {$monthLabel}",
                ];
            }

            // Cr Accrued Labor
            $lines[] = [
                'account_id' => $laborAccrualAccount->id,
                'debit'      => 0,
                'credit'     => (float) $laborEstimated,
                'farm_id'    => null,
                'memo'       => "Accrued labor — {$monthLabel}",
            ];

            // Dr WIP / Dr COGS / Cr per overhead type
            foreach ($overheadPartitions as $typeId => $p) {
                if (bccomp($p['wip'], '0', 2) > 0) {
                    $lines[] = [
                        'account_id' => $wipAccount->id,
                        'debit'      => (float) $p['wip'],
                        'credit'     => 0,
                        'farm_id'    => $fresh->farm_id,
                        'memo'       => "{$p['type_name']} — alive animals — {$monthLabel}",
                    ];
                }

                if (bccomp($p['cogs'], '0', 2) > 0) {
                    $lines[] = [
                        'account_id' => $cogsAccount->id,
                        'debit'      => (float) $p['cogs'],
                        'credit'     => 0,
                        'farm_id'    => $fresh->farm_id,
                        'memo'       => "{$p['type_name']} — sold/slaughtered animals — {$monthLabel}",
                    ];
                }

                if (bccomp($p['estimated'], '0', 2) > 0) {
                    $lines[] = [
                        'account_id' => $p['accrued_account_id'],
                        'debit'      => 0,
                        'credit'     => (float) $p['estimated'],
                        'farm_id'    => null,
                        'memo'       => "Accrued {$p['type_name']} — {$monthLabel}",
                    ];
                }
            }

            // ── Balance check (#1 JournalBalanceValidation) ───────
            $totalDr = $this->sumLines($lines, 'debit');
            $totalCr = $this->sumLines($lines, 'credit');

            if (!$this->isBalanced($totalDr, $totalCr)) {
                throw new \RuntimeException(
                    "Journal entry is not balanced: Dr {$totalDr} ≠ Cr {$totalCr}. Aborting."
                );
            }

            // If all amounts are zero (no labor, no overheads), skip posting
            if (bccomp($totalDr, '0', 2) === 0 && bccomp($totalCr, '0', 2) === 0) {
                // Still mark as posted with zero — preserves status machine integrity
                $fresh->update([
                    'status'             => 'posted',
                    'total_animal_days'  => $totalDays,
                    'rate_per_animal_day'=> $laborRatePerDay,
                    'posted_by'          => $postedByUserId,
                    'posted_at'          => now(),
                ]);

                // Return a dummy — no JE created for zero amounts
                return new JournalEntry(['id' => null, 'description' => 'Zero amounts — no JE created']);
            }

            // ── Post journal entry ────────────────────────────────
            $period = (int) $fresh->month;

            $je = JournalEntry::create([
                'company_id'   => $fresh->company_id,
                'fiscal_year_id' => $fy->id,
                'date'         => $fresh->month_end->toDateString(),
                'period'       => $period,
                'description'  => "Labor & Overhead Accrual — {$farmName} — {$monthLabel}",
                'entry_type'   => 'labor_overhead_accrual',
                'status'       => 'posted',
                'total_debit'  => $totalDr,
                'total_credit' => $totalCr,
                'posted_by'    => $postedByUserId,
                'posted_at'    => now(),
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit'],
                    'credit'     => $line['credit'],
                    'farm_id'    => $line['farm_id'],
                    'memo'       => $line['memo'],
                ]);
            }

            // ── Write-back ────────────────────────────────────────
            $fresh->update([
                'status'                   => 'posted',
                'labor_journal_entry_id'   => $je->id,
                'overhead_journal_entry_id'=> $je->id,  // same JE covers both
                'total_animal_days'        => $totalDays,
                'rate_per_animal_day'      => $laborRatePerDay,
                'posted_by'                => $postedByUserId,
                'posted_at'                => now(),
            ]);

            return $je;
        });
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLIC — Settle actual costs
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §10, §22
    //
    // Posts settlement JE:
    //   Dr  Accrued Labor (2260)       [estimated]
    //   Dr/Cr Labor Variance (6310)    [actual - estimated]
    //   Cr  Payment Account            [actual]
    //   (repeated per overhead type)
    //
    // overheadActuals: [overhead_type_id => actual_amount_string]
    // ──────────────────────────────────────────────────────────────

    public function settleActual(
        FarmCostAllocation $allocation,
        string             $laborActual,
        array              $overheadActuals,
        int                $paymentAccountId,
        int                $settledByUserId
    ): JournalEntry {

        return DB::transaction(function () use (
            $allocation, $laborActual, $overheadActuals, $paymentAccountId, $settledByUserId
        ) {
            $fresh = FarmCostAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($fresh->status !== 'posted') {
                throw new \RuntimeException(
                    'Cannot settle — accrual not yet posted. Current status: ' . $fresh->status
                );
            }

            if ($fresh->labor_settlement_je_id !== null) {
                throw new \RuntimeException('Already settled — labor_settlement_je_id is set.');
            }

            // ── Resolve accounts ──────────────────────────────────
            $laborAccrualAcct  = $this->requireAccount($fresh->company_id, '2260');
            $laborVarianceAcct = $this->requireAccount($fresh->company_id, '6310');
            $paymentAcct       = Account::where('company_id', $fresh->company_id)
                ->where('id', $paymentAccountId)
                ->where('allow_direct_posting', true)
                ->firstOrFail();

            $fresh->load('overheadLines.overheadType');

            $lines         = [];
            $monthLabel    = $fresh->monthLabel();
            $farmName = optional($fresh->farm)->name_en
                    ?? optional($fresh->farm)->name_ar
                    ?? 'Farm #' . $fresh->farm_id;

            // ── Labor settlement ──────────────────────────────────
            $laborEstimated = (string) $fresh->labor_estimated;
            $laborVariance  = bcsub($laborActual, $laborEstimated, 2);

            // Dr Accrued Labor (clears the accrual)
            $lines[] = [
                'account_id' => $laborAccrualAcct->id,
                'debit'      => (float) $laborEstimated,
                'credit'     => 0,
                'memo'       => "Clear accrued labor — {$monthLabel}",
            ];

            // Dr or Cr Labor Variance
            if (bccomp($laborVariance, '0', 2) !== 0) {
                $isUnfavorable = bccomp($laborVariance, '0', 2) > 0;
                $lines[] = [
                    'account_id' => $laborVarianceAcct->id,
                    'debit'      => $isUnfavorable ? (float) $laborVariance : 0,
                    'credit'     => $isUnfavorable ? 0 : (float) bcsub('0', $laborVariance, 2),
                    'memo'       => 'Labor variance — ' . ($isUnfavorable ? 'unfavorable' : 'favorable') . " — {$monthLabel}",
                ];
            }

            // Cr Payment account — actual labor paid
            $lines[] = [
                'account_id' => $paymentAcct->id,
                'debit'      => 0,
                'credit'     => (float) $laborActual,
                'memo'       => "Labor payment — {$monthLabel}",
            ];

            // ── Overhead settlement (per type) ────────────────────
            $ohVariances = [];   // overhead_type_id → variance string

            foreach ($fresh->overheadLines as $line) {
                $ohType      = $line->overheadType;
                $ohEstimated = (string) $line->estimated_amount;
                $ohActual    = (string) ($overheadActuals[$line->overhead_type_id] ?? '0');
                $ohVariance  = bcsub($ohActual, $ohEstimated, 2);

                $ohVariances[$line->overhead_type_id] = $ohVariance;

                // Dr Accrued overhead (clears the accrual)
                $lines[] = [
                    'account_id' => $ohType->accrued_account_id,
                    'debit'      => (float) $ohEstimated,
                    'credit'     => 0,
                    'memo'       => "Clear accrued {$ohType->name_en} — {$monthLabel}",
                ];

                // Dr or Cr Variance
                if (bccomp($ohVariance, '0', 2) !== 0) {
                    $isUnfav = bccomp($ohVariance, '0', 2) > 0;
                    $lines[] = [
                        'account_id' => $ohType->variance_account_id,
                        'debit'      => $isUnfav ? (float) $ohVariance : 0,
                        'credit'     => $isUnfav ? 0 : (float) bcsub('0', $ohVariance, 2),
                        'memo'       => ($ohType->name_en ?? 'Overhead') . ' variance — ' . ($isUnfav ? 'unfavorable' : 'favorable') . " — {$monthLabel}",
                    ];
                }

                // Cr Payment account — actual overhead paid
                $lines[] = [
                    'account_id' => $paymentAcct->id,
                    'debit'      => 0,
                    'credit'     => (float) $ohActual,
                    'memo'       => ($ohType->name_en ?? 'Overhead') . " payment — {$monthLabel}",
                ];
            }

            // ── Balance check ─────────────────────────────────────
            $totalDr = $this->sumLines($lines, 'debit');
            $totalCr = $this->sumLines($lines, 'credit');

            if (!$this->isBalanced($totalDr, $totalCr)) {
                throw new \RuntimeException(
                    "Settlement JE not balanced: Dr {$totalDr} ≠ Cr {$totalCr}. Aborting."
                );
            }

            // ── Post ──────────────────────────────────────────────
            // The settlement is dated today when an open fiscal year covers today;
            // otherwise (e.g. settling an old month after the year rolled over) it is
            // dated the allocation's month-end, whose open fiscal year must exist.
            // This keeps the entry's date inside its own fiscal year.
            $settleDate = now()->toDateString();
            $fy = FiscalYear::where('company_id', $fresh->company_id)
                ->where('status', 'open')
                ->where('start_date', '<=', $settleDate)
                ->where('end_date', '>=', $settleDate)
                ->first();

            if (!$fy) {
                $settleDate = $fresh->month_end->toDateString();
                $fy = FiscalYear::where('company_id', $fresh->company_id)
                    ->where('status', 'open')
                    ->where('start_date', '<=', $settleDate)
                    ->where('end_date', '>=', $settleDate)
                    ->first();
            }

            if (!$fy) {
                throw new \RuntimeException('No open fiscal year found for settlement.');
            }

            $je = JournalEntry::create([
                'company_id'    => $fresh->company_id,
                'fiscal_year_id'=> $fy->id,
                'date'          => $settleDate,
                'period'        => (int) \Carbon\Carbon::parse($settleDate)->month,
                'description'   => "Labor & Overhead Settlement — {$farmName} — {$monthLabel}",
                'entry_type'    => 'labor_overhead_settlement',
                'status'        => 'posted',
                'total_debit'   => $totalDr,
                'total_credit'  => $totalCr,
                'posted_by'     => $settledByUserId,
                'posted_at'     => now(),
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit'],
                    'credit'     => $line['credit'],
                    'memo'       => $line['memo'],
                ]);
            }

            // ── Write-backs ───────────────────────────────────────
            $fresh->update([
                'status'                   => 'settled',
                'labor_actual'             => (float) $laborActual,
                'labor_variance'           => (float) $laborVariance,
                'labor_settlement_je_id'   => $je->id,
                'overhead_settlement_je_id'=> $je->id,
                'settled_by'               => $settledByUserId,
                'settled_at'               => now(),
            ]);

            // Update each overhead line with actual + variance
            foreach ($fresh->overheadLines as $line) {
                $ohActual   = (string) ($overheadActuals[$line->overhead_type_id] ?? '0');
                $ohVariance = $ohVariances[$line->overhead_type_id] ?? '0';
                $line->update([
                    'actual_amount'   => (float) $ohActual,
                    'variance_amount' => (float) $ohVariance,
                ]);
            }

            return $je;
        });
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLIC — Labor + overhead cost for one animal (COGS read)
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §12, §21
    //
    // Called by LivestockIntelligenceService::costingForAnimal().
    // Reads posted/settled/locked allocation records only.
    // Draft months return 0 — not yet confirmed figures.
    //
    // Returns: ['labor' => float, 'overhead' => float]
    // ──────────────────────────────────────────────────────────────

    public function laborOverheadCostForAnimal(int $animalId, int $companyId): array
    {
        $totalLabor    = '0';
        $totalOverhead = '0';

        // Get all lot memberships for this animal
        $memberships = DB::table('animal_lot_history as alh')
            ->join('lots as l', 'l.id', '=', 'alh.lot_id')
            ->where('alh.animal_id', $animalId)
            ->where('l.company_id', $companyId)
            ->select(
                'l.farm_id',
                'alh.entered_at',
                'alh.exited_at'
            )
            ->orderBy('alh.entered_at')
            ->get();

        if ($memberships->isEmpty()) {
            return ['labor' => 0.0, 'overhead' => 0.0];
        }

        foreach ($memberships as $m) {
            $farmId          = $m->farm_id;
            $membershipStart = Carbon::parse($m->entered_at);
            $membershipEnd   = $m->exited_at ? Carbon::parse($m->exited_at) : Carbon::today();

            // Iterate month by month across the animal's membership period
            $cursor = $membershipStart->copy()->startOfMonth();

            while ($cursor->lte($membershipEnd)) {
                $monthStart = $cursor->copy()->startOfMonth()->toDateString();
                $monthEnd   = $cursor->copy()->endOfMonth()->toDateString();

                // Find posted/settled/locked allocation for this farm/month
                $allocation = \App\Models\FarmCostAllocation::with('overheadLines')
                    ->where('farm_id', $farmId)
                    ->where('company_id', $companyId)
                    ->where('year', $cursor->year)
                    ->where('month', $cursor->month)
                    ->whereIn('status', ['posted', 'settled', 'locked'])
                    ->first();

                if ($allocation && $allocation->total_animal_days > 0) {

                    // Animal's days in this month
                    // Always measure from the EARLIER date to the LATER date:
                    // Carbon 3 (Laravel 12) returns a negative number the other way round,
                    // which silently turned every animal's labor/overhead share into 0.
                    $overlapStart = Carbon::parse(max($membershipStart->toDateString(), $monthStart));
                    $overlapEnd   = Carbon::parse(min($membershipEnd->toDateString(), $monthEnd));
                    $animalDaysInMonth = $overlapEnd->lt($overlapStart)
                        ? 0
                        : (int) $overlapStart->diffInDays($overlapEnd) + 1;

                    if ($animalDaysInMonth > 0) {
                        $totalDays = (string) $allocation->total_animal_days;

                        // Labor share
                        $laborRate  = bcdiv((string) $allocation->labor_estimated, $totalDays, 8);
                        $laborShare = bcmul($laborRate, (string) $animalDaysInMonth, 4);
                        $totalLabor = bcadd($totalLabor, $laborShare, 4);

                        // Overhead shares (all types combined)
                        foreach ($allocation->overheadLines as $line) {
                            $ohRate    = bcdiv((string) $line->estimated_amount, $totalDays, 8);
                            $ohShare   = bcmul($ohRate, (string) $animalDaysInMonth, 4);
                            $totalOverhead = bcadd($totalOverhead, $ohShare, 4);
                        }
                    }
                }

                $cursor->addMonth();
            }
        }

        return [
            'labor'    => (float) \App\Support\Money::round($totalLabor, 2),
            'overhead' => (float) \App\Support\Money::round($totalOverhead, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLIC — Preview data for CreateEdit form
    //
    // Returns provisional rates for display — no writes.
    // ──────────────────────────────────────────────────────────────

    public function previewRates(int $farmId, int $companyId, string $monthStart, string $monthEnd, string $laborEstimated): array
    {
        $dayData    = $this->computeAnimalDays($farmId, $companyId, $monthStart, $monthEnd);
        $totalDays  = $dayData['total_animal_days'];

        if ($totalDays <= 0) {
            return [
                'total_animal_days'       => 0,
                'daily_rate'              => 0,
                'rate_per_animal_day'     => 0,
                'projected_per_animal_month' => 0,
            ];
        }

        $daysInMonth    = Carbon::parse($monthStart)->daysInMonth;
        $dailyRate      = bcdiv($laborEstimated, (string) $daysInMonth, 4);
        $ratePerDay     = bcdiv($laborEstimated, (string) $totalDays, 8);
        $avgDaysPerAnimal = bcdiv((string) $totalDays, (string) count($dayData['animal_days']), 4);
        $projectedPerAnimal = bcmul($ratePerDay, $avgDaysPerAnimal, 2);

        return [
            'total_animal_days'          => $totalDays,
            'active_animal_count'        => count($dayData['animal_days']),
            'daily_rate'                 => (float) \App\Support\Money::round($dailyRate, 2),
            'rate_per_animal_day'        => (float) \App\Support\Money::round($ratePerDay, 4),
            'projected_per_animal_month' => (float) \App\Support\Money::round($projectedPerAnimal, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE — Partition total cost between WIP (alive) and COGS (exited)
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §7, §8
    //
    // Returns [wipShare, cogsShare] both as bcmath strings at scale=2.
    // Rounding residual absorbed into wipShare.
    // ──────────────────────────────────────────────────────────────

    private function partitionShares(
        string $ratePerDay,
        array  $animalDays,    // [animal_id => days]
        array  $exitedAnimalIds, // flipped array used as set
        string $totalCost
    ): array {

        $wipTotal  = '0';
        $cogsTotal = '0';

        foreach ($animalDays as $animalId => $days) {
            $share = bcmul($ratePerDay, (string) $days, 4);
            $share = \App\Support\Money::round($share, 2);  // round to cents

            if (isset($exitedAnimalIds[$animalId])) {
                $cogsTotal = bcadd($cogsTotal, $share, 2);
            } else {
                $wipTotal = bcadd($wipTotal, $share, 2);
            }
        }

        // Absorb rounding residual into WIP
        $sumCheck = bcadd($wipTotal, $cogsTotal, 2);
        $residual = bcsub($totalCost, $sumCheck, 2);
        $wipTotal = bcadd($wipTotal, $residual, 2);

        return [\App\Support\Money::round($wipTotal, 2), \App\Support\Money::round($cogsTotal, 2)];
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE — bcmath helpers for journal totals
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
    // ──────────────────────────────────────────────────────────────

    /** Sum one column ('debit' / 'credit') of the journal lines as a 2-dp bcmath string. */
    private function sumLines(array $lines, string $column): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = bcadd($total, \App\Support\Money::round($line[$column] ?? 0, 2), 2);
        }
        return $total;
    }

    /** 📌 JournalBalanceValidation (#1): |Dr − Cr| ≤ 0.005 */
    private function isBalanced(string $debit, string $credit): bool
    {
        $diff = ltrim(bcsub($debit, $credit, 4), '-');
        return bccomp($diff, '0.0050', 4) <= 0;
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE — Require account by code, throw if missing
    // ──────────────────────────────────────────────────────────────

    private function requireAccount(int $companyId, string $code): Account
    {
        $account = Account::where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            throw new \RuntimeException(
                "Required account {$code} not found for company {$companyId}. " .
                "Run ChartOfAccountsSeeder::seedNewAccountsForCompany() first."
            );
        }

        return $account;
    }
}
