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
//
//  BUG 2 — Unnecessary raw math on a pre-calculated column.
//    Was: ->sum(DB::raw('il.qty * il.unit_cost'))
//    Fix: ->sum('it.total')
//
//  BUG 3 — `treatment_cost` column does not exist on health_records.
//    Was: ->sum('treatment_cost')
//    Fix: ->sum('cost')
//
//  BUG 4 — `withdrawal_days` column does not exist on health_records.
//    Was: ->whereRaw('DATE_ADD(hr.date, INTERVAL hr.withdrawal_days DAY) >= ?')
//    Fix: ->whereNotNull('hr.withdrawal_clear_date')
//         ->whereDate('hr.withdrawal_clear_date', '>=', $today)
//
//  RENAME (Session 65d):
//    target_slaughter_date → target_sale_date
//    Alert key: slaughter_due_30d → lots_due_for_sale_30d
//    Business meaning: VERO is a buy-fatten-sell-live operation.
//    The date on a lot is the planned LIVE SALE date, not a slaughter
//    target. This alert warns the farm manager that a batch is
//    approaching its planned sale date within the next 30 days.
//
//  KPIs exposed:
//    Operational: active head count, avg ADG, feed cost MTD,
//                 vet cost MTD, vaccination cost MTD, mortality MTD
//    Financial:   revenue MTD/YTD, gross margin % MTD/YTD,
//                 outstanding payables, outstanding receivables,
//                 overdue payables
//    Alerts:      lots approaching planned sale date ≤30 days,
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
        // On the farm = active + reserved (in a draft sale) + quarantine.
        $activeHead = $scope('animals')
            ->whereIn('status', ['active', 'reserved', 'quarantine'])
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
            ->whereIn('a.status', ['active', 'reserved', 'quarantine'])
            ->whereNotNull('wr.adg_kg')
            ->whereRaw('wr.id IN (
                SELECT MAX(id) FROM weight_records
                WHERE company_id = ? GROUP BY animal_id
            )', [$companyId])
            ->avg('wr.adg_kg');

        // Feed cost MTD — from inventory_transactions type = 'consumption'
        // Column is `date` (not `transaction_date`) per schema.
        // No join needed — consumption transactions are feed draws by definition.
        $feedCostMtd = DB::table('inventory_transactions as it')
            ->where('it.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('it.farm_id', $farmId))
            ->where('it.type', 'consumption')
            ->whereBetween('it.date', [$mtdStart, $today])
            ->sum('it.total');

        // Vet cost MTD — from health_records
        $vetCostMtd = $scope('health_records')
            ->whereDate('date', '>=', $mtdStart)
            ->sum('cost');

        // Vaccination cost MTD — from vaccination_records
        $vaccinationCostMtd = $scope('vaccination_records')
            ->whereDate('date', '>=', $mtdStart)
            ->sum('cost');

        // Mortality MTD — animals that died (status = 'dead') this month
        $mortalityMtd = $scope('animals')
            ->where('status', 'dead')
            ->whereDate('updated_at', '>=', $mtdStart)
            ->count();

        return [
            // Key names are the ones Dashboard/Index.vue reads.
            'active_head_count'    => $activeHead,
            'active_lots'          => $activeLots,
            'avg_adg_kg_day'       => $avgAdg ? round((float) $avgAdg, 3) : null,
            'feed_cost_mtd'        => round((float) $feedCostMtd,        2),
            'vet_cost_mtd'         => round((float) $vetCostMtd,         2),
            'vaccination_cost_mtd' => round((float) $vaccinationCostMtd, 2),
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
        // Revenue MTD / YTD from confirmed sales
        // Column is `date` (not `sale_date`) per schema — sales_records.date
        $revenueMtd = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $mtdStart)
            ->sum('total_amount');

        $revenueYtd = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $ytdStart)
            ->sum('total_amount');

        // P&L for gross margin
        $plMtd = $this->financial->profitAndLoss($companyId, $mtdStart, $today);
        $plYtd = $this->financial->profitAndLoss($companyId, $ytdStart, $today);

        // Outstanding payables
        $outstandingPayables = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->sum('balance');

        // Outstanding receivables
        $outstandingReceivables = DB::table('ar_invoices')
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->sum('balance');

        // Overdue payables
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

        // Lots approaching planned live sale date within 30 days.
        // VERO is a buy-fatten-sell-live operation. target_sale_date on a lot
        // is the planned date the fattened batch will be ready for live sale —
        // this alert gives farm managers 30-day advance warning to arrange
        // buyer contacts, transport, and weighing logistics.
        $lotsDueForSale = DB::table('animals as a')
            ->join('lots as l', 'l.id', '=', 'a.lot_id')
            ->where('a.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('a.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNotNull('l.target_sale_date')
            ->whereBetween('l.target_sale_date', [$today, $thirtyDaysOut])
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

        // Animals with active medicine withdrawal period.
        // health_records has `withdrawal_clear_date` (date), NOT `withdrawal_days`.
        $withdrawalPending = DB::table('health_records as hr')
            ->join('animals as a', 'a.id', '=', 'hr.animal_id')
            ->where('hr.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('hr.farm_id', $farmId))
            ->where('a.status', 'active')
            ->whereNotNull('hr.withdrawal_clear_date')
            ->whereDate('hr.withdrawal_clear_date', '>=', $today)
            ->whereNull('a.deleted_at')
            ->count();

        // Overdue AP invoices
        $overdueInvoices = DB::table('ap_invoices')
            ->where('company_id', $companyId)
            ->where('status', 'overdue')
            ->count();

        return [
            'lots_due_for_sale_30d' => $lotsDueForSale,   // renamed from slaughter_due_30d
            'vaccinations_due_7d'   => $vaccinationsDue,
            'animals_in_quarantine' => $inQuarantine,
            'withdrawal_pending'    => $withdrawalPending,
            'overdue_ap_invoices'   => $overdueInvoices,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // CHART DATA
    // ──────────────────────────────────────────────────────────────
    private function chartData(
        int    $companyId,
        ?int   $farmId,
        string $ytdStart,
        string $today
    ): array {
        // Monthly revenue vs COGS — year to date, from the ledger (same source as the P&L,
        // so the chart always agrees with the P&L report).
        $ledger = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereNull('je.deleted_at')
            ->whereNull('jl.deleted_at')
            ->whereBetween('je.date', [$ytdStart, $today])
            ->whereIn('a.type', ['revenue', 'cogs'])
            ->selectRaw('DATE_FORMAT(je.date, "%Y-%m") as month, a.type,
                         SUM(jl.debit) as d, SUM(jl.credit) as c')
            ->groupBy('month', 'a.type')
            ->get();

        $monthlyRevenueCogs = [];
        for ($m = Carbon::parse($ytdStart)->startOfMonth();
             $m->lte(Carbon::parse($today));
             $m->addMonth()) {
            $key = $m->format('Y-m');
            $rev = '0';
            $cogs = '0';
            foreach ($ledger->where('month', $key) as $r) {
                if ($r->type === 'revenue') { $rev  = bcadd($rev,  bcsub((string) $r->c, (string) $r->d, 4), 4); }
                else                        { $cogs = bcadd($cogs, bcsub((string) $r->d, (string) $r->c, 4), 4); }
            }
            $monthlyRevenueCogs[] = [
                'month'   => $key,
                'label'   => $m->format('M Y'),
                'revenue' => round((float) $rev,  2),
                'cogs'    => round((float) $cogs, 2),
            ];
        }

        // Active head by lot (animals on the farm, per active lot)
        $headCountByLot = DB::table('animals as an')
            ->join('lots as l', 'l.id', '=', 'an.lot_id')
            ->where('an.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('an.farm_id', $farmId))
            ->where('l.status', 'active')
            ->whereIn('an.status', ['active', 'reserved', 'quarantine'])
            ->whereNull('an.deleted_at')
            ->groupBy('l.id', 'l.name')
            ->orderBy('l.name')
            ->get(['l.id', 'l.name', DB::raw('COUNT(an.id) as head_count')])
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'head_count' => (int) $r->head_count])
            ->toArray();

        // Monthly revenue from confirmed sales (kept for API consumers)
        // Column is `date` (not `sale_date`) per schema
        $revenueByMonth = DB::table('sales_records')
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $ytdStart)
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as month, SUM(total_amount) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'total' => round((float) $r->total, 2)])
            ->toArray();

        // Head count by breed
        $headByBreed = DB::table('animals')
            ->where('company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId))
            ->whereIn('status', ['active', 'reserved', 'quarantine'])
            ->whereNull('deleted_at')
            ->selectRaw('breed, COUNT(*) as count')
            ->groupBy('breed')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['breed' => $r->breed, 'count' => $r->count])
            ->toArray();

        // Feed cost vs vet cost — last 6 months
        // Column is `date` (not `transaction_date`) per schema.
        $feedVsVet = DB::table('inventory_transactions as it')
            ->where('it.company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('it.farm_id', $farmId))
            ->where('it.type', 'consumption')
            ->whereDate('it.date', '>=', Carbon::parse($today)->subMonths(6)->toDateString())
            ->selectRaw('DATE_FORMAT(it.date, "%Y-%m") as month, SUM(it.total) as feed_cost')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $vetByMonth = DB::table('health_records')
            ->where('company_id', $companyId)
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', Carbon::parse($today)->subMonths(6)->toDateString())
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as month, SUM(cost) as vet_cost')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $allMonths = collect($feedVsVet->keys())->merge($vetByMonth->keys())->unique()->sort()->values();
        $costTrend = $allMonths->map(fn($m) => [
            'month'     => $m,
            'feed_cost' => round((float) ($feedVsVet[$m]->feed_cost ?? 0), 2),
            'vet_cost'  => round((float) ($vetByMonth[$m]->vet_cost  ?? 0), 2),
        ])->values()->toArray();

        return [
            'monthly_revenue_cogs' => $monthlyRevenueCogs,   // read by Dashboard/Index.vue
            'head_count_by_lot'    => $headCountByLot,       // read by Dashboard/Index.vue
            'revenue_by_month' => $revenueByMonth,
            'head_by_breed'    => $headByBreed,
            'cost_trend'       => $costTrend,
        ];
    }
}