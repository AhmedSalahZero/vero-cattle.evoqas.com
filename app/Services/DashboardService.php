<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Dashboard Service
//
//  SCHEMA FIXES APPLIED (2026-04):
//
//  BUG 1 — `inventory_ledger` does not exist.
//    Was: DB::table('inventory_ledger as il')
//    Fix: DB::table('inventory_transactions as it')
//         The real table is inventory_transactions with
//         type enum('receipt','consumption','adjustment',
//                   'transfer_in','transfer_out')
//
//  BUG 2 — Unnecessary raw math on a pre-calculated column.
//    Was: ->sum(DB::raw('il.qty * il.unit_cost'))
//    Fix: ->sum('it.total')
//         inventory_transactions already stores total = qty × unit_cost
//
//  BUG 3 — `treatment_cost` column does not exist on health_records.
//    Was: ->sum('treatment_cost')
//    Fix: ->sum('cost')
//         health_records schema column is: cost decimal(12,2)
//
//  BUG 4 — `withdrawal_days` column does not exist on health_records.
//    Was: ->whereRaw('DATE_ADD(hr.date, INTERVAL hr.withdrawal_days DAY) >= ?')
//         ->whereRaw('hr.withdrawal_days > 0')
//    Fix: ->whereNotNull('hr.withdrawal_clear_date')
//         ->whereDate('hr.withdrawal_clear_date', '>=', $today)
//         health_records schema column is: withdrawal_clear_date date NULL
//
//  KPIs exposed:
//    Operational: active head count, avg ADG, feed cost MTD,
//                 vet cost MTD, vaccination cost MTD, mortality MTD
//    Financial:   revenue MTD/YTD, gross margin % MTD/YTD,
//                 outstanding payables, outstanding receivables,
//                 overdue payables
//    Alerts:      animals due for slaughter ≤30 days,
//                 vaccinations due ≤7 days, quarantine count,
//                 withdrawal pending, overdue AP invoices
// ══════════════════════════════════════════════════════════════════

class DashboardService
{
    public function __construct(
        private LivestockIntelligenceService $livestock,
        private FinancialReportService       $financial,
    ) {}

    // ──────────────────────────────────────────────────────────────
    // MAIN ENTRY POINT — returns full dashboard payload
    // ──────────────────────────────────────────────────────────────
    public function getData(int $companyId, ?int $farmId = null): array
    {
        $today    = Carbon::today();
        $mtdStart = $today->copy()->startOfMonth()->toDateString();
        $ytdStart = $today->copy()->startOfYear()->toDateString();
        $todayStr = $today->toDateString();

        return [
            'operational'  => $this->operationalKpis($companyId, $farmId, $mtdStart, $todayStr),
            'financial'    => $this->financialKpis($companyId, $mtdStart, $ytdStart, $todayStr),
            'alerts'       => $this->alerts($companyId, $farmId, $todayStr),
            'charts'       => $this->chartData($companyId, $farmId, $ytdStart, $todayStr),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // OPERATIONAL KPIs
    // ──────────────────────────────────────────────────────────────
    private function operationalKpis(
        int    $companyId,
        ?int   $farmId,
        string $mtdStart,
        string $today
    ): array {
        $scope = fn($table) => DB::table($table)
            ->where('company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId));

        // Active head count
        $activeHead = $scope('animals')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        // Active lots count
        $activeLots = $scope('lots')
            ->where('status', 'active')
            ->count();

        // Average ADG across all active animals (from stored adg_kg on latest weight record)
        $avgAdg = DB::table('weight_records as wr')
            ->join('animals as a', 'a.id', '=', 'wr.animal_id')
            ->where('wr.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('wr.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNotNull('wr.adg_kg')
            ->whereRaw('wr.id IN (
                SELECT MAX(id) FROM weight_records
                WHERE company_id = ? GROUP BY animal_id
            )', [$companyId])
            ->avg('wr.adg_kg');

        // ── FIX BUG 1 & 2 ─────────────────────────────────────────
        // Feed cost MTD — sourced from inventory_transactions (not inventory_ledger)
        // type = 'consumption' covers feed items drawn from inventory.
        // Uses pre-calculated `total` column (= qty × unit_cost stored at write time).
        $feedCostMtd = DB::table('inventory_transactions as it')
            ->where('it.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('it.farm_id', $farmId))
            ->where('it.type', 'consumption')
            ->whereBetween('it.date', [$mtdStart, $today])
            ->sum('it.total'); // BUG FIX: was sum(DB::raw('il.qty * il.unit_cost'))

        // ── FIX BUG 3 ─────────────────────────────────────────────
        // Vet cost MTD — health_records column is `cost`, not `treatment_cost`
        $vetCostMtd = $scope('health_records')
            ->whereBetween('date', [$mtdStart, $today])
            ->sum('cost'); // BUG FIX: was sum('treatment_cost')

        // Vaccination cost MTD — vaccination_records.cost is correct per schema
        $vaccCostMtd = $scope('vaccination_records')
            ->whereBetween('date', [$mtdStart, $today])
            ->sum('cost');

        // Mortality count MTD
        $mortalityMtd = $scope('animals')
            ->where('status', 'dead')
            ->whereBetween('status_date', [$mtdStart, $today])
            ->count();

        return [
            'active_head_count'    => $activeHead,
            'active_lots'          => $activeLots,
            'avg_adg_kg_day'       => $avgAdg ? round((float) $avgAdg, 3) : null,
            'feed_cost_mtd'        => round((float) $feedCostMtd, 2),
            'vet_cost_mtd'         => round((float) $vetCostMtd,  2),
            'vaccination_cost_mtd' => round((float) $vaccCostMtd, 2),
            'mortality_mtd'        => $mortalityMtd,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // FINANCIAL KPIs
    // ──────────────────────────────────────────────────────────────
    private function financialKpis(
        int    $companyId,
        string $mtdStart,
        string $ytdStart,
        string $today
    ): array {
        // Revenue MTD and YTD from confirmed/paid sales_records
        $revenueMtd = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('date', [$mtdStart, $today])
            ->sum('total_amount');

        $revenueYtd = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'partially_paid', 'paid'])
            ->whereBetween('date', [$ytdStart, $today])
            ->sum('total_amount');

        // P&L for MTD/YTD gross margin %
        $plMtd = $this->financial->profitAndLoss($companyId, $mtdStart, $today);
        $plYtd = $this->financial->profitAndLoss($companyId, $ytdStart, $today);

        // Outstanding payables (open + partially_paid + overdue AP invoices)
        $outstandingPayables = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->sum('balance');

        // Outstanding receivables (open + partially_paid + overdue AR invoices)
        $outstandingReceivables = DB::table('ar_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->sum('balance');

        // Overdue payables (due_date < today)
        $overduePayables = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->whereDate('due_date', '<', $today)
            ->sum('balance');

        return [
            'revenue_mtd'             => round((float) $revenueMtd,             2),
            'revenue_ytd'             => round((float) $revenueYtd,             2),
            'gross_margin_pct_mtd'    => $plMtd['current']['gross_margin_pct'],
            'gross_margin_pct_ytd'    => $plYtd['current']['gross_margin_pct'],
            'net_income_mtd'          => $plMtd['current']['net_income'],
            'net_income_ytd'          => $plYtd['current']['net_income'],
            'outstanding_payables'    => round((float) $outstandingPayables,    2),
            'outstanding_receivables' => round((float) $outstandingReceivables, 2),
            'overdue_payables'        => round((float) $overduePayables,        2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ALERTS — operational warnings for the dashboard
    // ──────────────────────────────────────────────────────────────
    private function alerts(int $companyId, ?int $farmId, string $today): array
    {
        $scope = fn($table) => DB::table($table)
            ->where('company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId));

        $thirtyDaysOut = Carbon::parse($today)->addDays(30)->toDateString();
        $sevenDaysOut  = Carbon::parse($today)->addDays(7)->toDateString();

        // Animals due for slaughter: active animals in lots with target_slaughter_date set
        $slaughterDue = DB::table('animals as a')
            ->join('lots as l', 'l.id', '=', 'a.lot_id')
            ->where('a.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('a.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNotNull('l.target_slaughter_date')
            ->whereBetween('l.target_slaughter_date', [$today, $thirtyDaysOut])
            ->count();

        // Vaccinations due in next 7 days
        $vaccinationsDue = $scope('vaccination_records')
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today, $sevenDaysOut])
            ->count();

        // Animals in quarantine
        $inQuarantine = $scope('animals')
            ->where('status', 'quarantine')
            ->count();

        // ── FIX BUG 4 ─────────────────────────────────────────────
        // Animals with active withdrawal period.
        // health_records has `withdrawal_clear_date` (date), NOT `withdrawal_days`.
        // Filter: withdrawal_clear_date is set and is today or in the future,
        //         meaning the withdrawal window has not yet passed.
        $withdrawalPending = DB::table('health_records as hr')
            ->join('animals as a', 'a.id', '=', 'hr.animal_id')
            ->where('hr.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('hr.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNotNull('hr.withdrawal_clear_date')                     // BUG FIX: was withdrawal_days > 0
            ->whereDate('hr.withdrawal_clear_date', '>=', $today)          // BUG FIX: was DATE_ADD(date, INTERVAL withdrawal_days DAY)
            ->whereNull('a.deleted_at')
            ->count();

        // Overdue AP invoices
        $overdueInvoices = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->where('status', 'overdue')
            ->count();

        return [
            'slaughter_due_30d'     => $slaughterDue,
            'vaccinations_due_7d'   => $vaccinationsDue,
            'animals_in_quarantine' => $inQuarantine,
            'withdrawal_pending'    => $withdrawalPending,
            'overdue_ap_invoices'   => $overdueInvoices,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // CHART DATA — time-series arrays for frontend charts
    // ──────────────────────────────────────────────────────────────
    private function chartData(
        int    $companyId,
        ?int   $farmId,
        string $ytdStart,
        string $today
    ): array {
        // Monthly revenue vs COGS for the current year
        $monthlyPL = $this->monthlyPLSeries($companyId, $ytdStart, $today);

        // Head count by lot (for pie/bar chart)
        $headByLot = DB::table('animals as a')
            ->join('lots as l', 'l.id', '=', 'a.lot_id')
            ->where('a.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('a.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNull('a.deleted_at')
            ->groupBy('l.id', 'l.name')
            ->select('l.id', 'l.name', DB::raw('COUNT(a.id) as head_count'))
            ->get();

        return [
            'monthly_revenue_cogs' => $monthlyPL,
            'head_count_by_lot'    => $headByLot,
        ];
    }

    private function monthlyPLSeries(
        int    $companyId,
        string $ytdStart,
        string $today
    ): array {
        $months = [];
        $cursor = Carbon::parse($ytdStart)->startOfMonth();
        $end    = Carbon::parse($today);

        while ($cursor->lte($end)) {
            $from = $cursor->copy()->startOfMonth()->toDateString();
            $to   = $cursor->copy()->endOfMonth()->toDateString();

            // Cap to today for the current month
            if ($cursor->isSameMonth($end)) {
                $to = $today;
            }

            $pl = $this->financial->profitAndLoss($companyId, $from, $to);

            $months[] = [
                'month'            => $cursor->format('Y-m'),
                'label'            => $cursor->format('M Y'),
                'revenue'          => $pl['current']['total_revenue'],
                'cogs'             => $pl['current']['total_cogs'],
                'gross_profit'     => $pl['current']['gross_profit'],
                'gross_margin_pct' => $pl['current']['gross_margin_pct'],
                'net_income'       => $pl['current']['net_income'],
            ];

            $cursor->addMonth();
        }

        return $months;
    }
}