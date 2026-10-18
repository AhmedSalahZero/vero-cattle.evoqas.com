<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\AnimalLotHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Livestock Intelligence Service
//
//  📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
//  📌 METHODOLOGY REFERENCE: WAC (#10)
//  📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//  📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//
//  SESSION 49 CHANGES — Migration 059:
//    — costingForAnimal() rewritten to use animal_lot_history for
//      date-bounded feed cost allocation
//    — feed cost sourced from feed_consumptions.total_cost / head_count
//      joined through lot history, not queried per animal_id
//    — purchase cost from animals.purchase_price + animals.freight_cost
//    — health_records.cost used (not treatment_cost — column does not exist)
//    — lot transfer awareness via animal_lot_history
//
//  SESSION 53 CHANGES — Migration 060:
//    — calculateFeedCostForAnimal() updated for FeedConsumptionAnimalSelection
//    — For sessions WITH pivot rows (feed_consumption_animals):
//        Animal gets share ONLY if explicitly selected in that session
//    — For sessions WITHOUT pivot rows (legacy):
//        Falls back to total_cost / head_count across all lot animals
//    — head_count on feed_consumptions is now server-computed
//      (count of pivot rows), NOT user-entered — comments updated
//    — count($animals) replaced with $animals->count() throughout
//
//  SESSION 60 CHANGES — Migration 066:
//    — costingForAnimal() overhead section REPLACED:
//        RETIRED: flat overhead_per_head_per_day from settings table
//        NEW:     laborOverheadCostForAnimal() via FarmCostAllocationService
//    — COGS breakdown now returns 'labor' and 'overhead' separately
//        (was: single 'overhead' key combining both — now split)
//    — costingForLot() updated: bdLabor + bdOverhead replacing bdOverhead
//    — FarmCostAllocationService injected via constructor
//
//  All methods remain pure-read — no writes occur in this service.
// ══════════════════════════════════════════════════════════════════

class LivestockIntelligenceService
{
    public function __construct(
        private FarmCostAllocationService $farmCostService
    ) {}

    // ──────────────────────────────────────────────────────────────
    // ADG & FCR
    // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
    // ──────────────────────────────────────────────────────────────

    /** Dry-matter % assumed for a feed whose catalogue entry has no value. */
    private const DEFAULT_DM_PCT = '88';

    public function adgFcrForAnimal(int $animalId, int $companyId): array
    {
        $weights = DB::table('weight_records')
            ->where('animal_id', $animalId)
            ->where('company_id', $companyId)
            ->orderBy('date')
            ->pluck('weight_kg', 'date')
            ->toArray();

        if (count($weights) < 2) {
            return $this->nullAdgFcr('Insufficient weight records');
        }

        $dates     = array_keys($weights);
        $wInitial  = (float) reset($weights);
        $wFinal    = (float) end($weights);
        $dateFirst = reset($dates);
        $dateLast  = end($dates);

        $dof = (int) now()->parse($dateFirst)->diffInDays(now()->parse($dateLast));

        if ($dof <= 0) {
            return $this->nullAdgFcr('Days on feed is zero');
        }

        $adg  = ($wFinal - $wInitial) / $dof;
        $gain = $wFinal - $wInitial;

        // Feed DM: try new inventory_items path first, fall back to feed_items
        $feedDm = $this->calculateFeedDmForAnimal($animalId, $companyId, $dateFirst, $dateLast);

        $fcr = ($gain > 0) ? round($feedDm / $gain, 3) : null;

        return [
            'animal_id'      => $animalId,
            'weight_initial' => round($wInitial, 2),
            'weight_final'   => round($wFinal, 2),
            'weight_gain'    => round($gain, 2),
            'days_on_feed'   => $dof,
            'adg_kg_day'     => round($adg, 3),
            'feed_dm_total'  => round($feedDm, 2),
            'fcr'            => $fcr,
            'date_first'     => $dateFirst,
            'date_last'      => $dateLast,
        ];
    }

    public function adgFcrForLot(int $lotId, int $companyId): array
    {
        $animals = DB::table('animals')
            ->where('lot_id', $lotId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($animals->isEmpty()) {
            return ['lot_id' => $lotId, 'head_count' => 0];
        }

        $weights = DB::table('weight_records')
            ->where('lot_id', $lotId)
            ->where('company_id', $companyId)
            ->orderBy('date')
            ->get(['animal_id', 'weight_kg', 'date']);

        if ($weights->isEmpty()) {
            return $this->nullAdgFcr('No weight records for lot');
        }

        $byAnimal  = $weights->groupBy('animal_id');
        $wInitial  = 0;
        $wFinal    = 0;
        $dofSum    = 0;
        $animalCount = $animals->count();
        $measured    = 0;   // animals with at least two weighings

        foreach ($byAnimal as $aid => $recs) {
            if ($recs->count() < 2) continue;
            $first = $recs->first();
            $last  = $recs->last();
            $wInitial += (float) $first->weight_kg;
            $wFinal   += (float) $last->weight_kg;
            $dofSum   += now()->parse($first->date)->diffInDays(now()->parse($last->date));
            $measured++;
        }

        // Average over the animals that were actually weighed twice — animals with a single
        // weighing add no gain, so counting them in the divisor would pull ADG down.
        $avgDof  = $measured > 0 ? $dofSum / $measured : 0;
        $gain    = $wFinal - $wInitial;
        $adg     = ($avgDof > 0 && $measured > 0)
            ? ($gain / $measured) / $avgDof
            : 0;

        // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9) — dry-matter basis
        $feed      = $this->calculateFeedDmForLot($lotId, $companyId);
        $feedDm    = $feed['dm_kg'];
        $totalGain = $wFinal - $wInitial;
        // No recorded feed → FCR is unknown (not 0).
        $fcr       = ($totalGain > 0 && $feedDm > 0) ? round($feedDm / $totalGain, 3) : null;

        return [
            'lot_id'         => $lotId,
            'head_count'     => $animalCount,
            'weight_initial' => round($wInitial, 2),
            'weight_final'   => round($wFinal, 2),
            'weight_gain'    => round($wFinal - $wInitial, 2),
            'days_on_feed'   => (int) $avgDof,
            'adg_kg_day'     => round($adg, 3),
            'feed_dm_total'  => round($feedDm, 2),
            'feed_dm_assumed'=> $feed['assumed'],
            'fcr'            => $fcr,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // WAC — current WAC for an inventory item at a location
    // 📌 METHODOLOGY REFERENCE: WAC (#10)
    // ──────────────────────────────────────────────────────────────

    public function currentWac(int $masterItemId, int $companyId, int $locationId): ?float
    {
        // WAC is stored on the location-specific stock row (is_master=false)
        $wac = DB::table('inventory_items')
            ->where('master_item_id', $masterItemId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->where('is_master', false)
            ->value('unit_cost');

        return $wac !== null ? (float) $wac : null;
    }

    // ──────────────────────────────────────────────────────────────
    // COGS, Gross Margin, Break-Even per animal
    //
    // 📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §10
    // 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §12
    // ──────────────────────────────────────────────────────────────

    public function costingForAnimal(int $animalId, int $companyId): array
    {
        $animal = Animal::where('id', $animalId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // 📌 BcmathMonetaryPrecision (#39) — all accumulation in bcmath strings

        // ── 1. Purchase cost ──────────────────────────────────────
        // Source: animals.purchase_price + animals.freight_cost
        // Set at animal creation time from the PO receipt flow
        $purchaseCost = bcadd(
            (string) ($animal->purchase_price ?? '0'),
            (string) ($animal->freight_cost   ?? '0'),
            4
        );

        // ── 2. Feed cost — date-bounded lot history ───────────────
        // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §10
        // 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
        //
        // For sessions WITH pivot rows in feed_consumption_animals:
        //   Animal gets a share ONLY if it was explicitly selected
        // For sessions WITHOUT pivot rows (legacy records):
        //   Falls back to total_cost / head_count across all lot animals
        //
        // head_count on feed_consumptions is server-computed (Migration 060)
        // — it equals count(animal_ids) at the time of the session,
        //   NOT a user-entered value.
        $feedCost = $this->calculateFeedCostForAnimal($animalId, $companyId, $animal);

        // ── 3. Medicine cost — directly linked to animal ──────────
        // health_records.cost is the correct column (not treatment_cost)
        $medicineCost = (string) (DB::table('health_records')
            ->where('animal_id', $animalId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->sum('cost') ?? '0');

        // ── 4. Vaccination cost — directly linked to animal ───────
        $vaccCost = (string) (DB::table('vaccination_records')
            ->where('animal_id', $animalId)
            ->where('company_id', $companyId)
            ->sum('cost') ?? '0');

        // ── 5. Slaughter cost ─────────────────────────────────────
        $slaughterCost = (string) (DB::table('slaughter_records')
            ->where('animal_id', $animalId)
            ->where('company_id', $companyId)
            ->value('slaughter_cost') ?? '0');

        // ── 6. Labor & Overhead — farm absorption ─────────────────
        // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §12, §21
        //
        // Replaces the retired flat overhead_per_head_per_day settings rate.
        // Reads posted/settled/locked farm_cost_allocations for each month
        // the animal was alive on its farm. Share is proportional to
        // animal-days (days alive / total farm animal-days that month).
        //
        // Returns 0 for months with no posted allocation record — this is
        // correct and expected for animals alive before this module was activated.
        $laborOverhead = $this->farmCostService->laborOverheadCostForAnimal($animalId, $companyId);
        $laborCost     = (string) $laborOverhead['labor'];
        $overheadCost  = (string) $laborOverhead['overhead'];

        // ── Total COGS ────────────────────────────────────────────
        $totalCogs = bcadd(
            bcadd(
                bcadd(
                    bcadd(
                        bcadd(
                            bcadd($purchaseCost, $feedCost,     4),
                            $medicineCost, 4),
                        $vaccCost, 4),
                    $slaughterCost, 4),
                $laborCost, 4),
            $overheadCost, 4
        );

        // ── Revenue from sales lines ──────────────────────────────
        // sales_lines.total is the line total for this animal
        $revenue = (string) (DB::table('sales_lines')
            ->where('animal_id', $animalId)
            ->value('total') ?? '0');

        // ── Gross margin ──────────────────────────────────────────
        $grossMargin    = bcsub($revenue, $totalCogs, 4);
        $grossMarginPct = (bccomp($revenue, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $revenue, 6), 2)
            : null;

        // ── Break-even weight ─────────────────────────────────────
        $pricePerKgDw = (string) (DB::table('sales_lines')
            ->where('animal_id', $animalId)
            ->value('price_per_kg') ?? '0');

        $breakEvenWeight = (bccomp($pricePerKgDw, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv($totalCogs, $pricePerKgDw, 6), 2)
            : null;

        return [
            'animal_id'        => $animalId,
            'tag'              => $animal->tag,
            'costs'            => [
                'purchase'     => (float) \App\Support\Money::round($purchaseCost, 2),
                'feed'         => (float) \App\Support\Money::round($feedCost, 2),
                'medicine'     => (float) \App\Support\Money::round($medicineCost, 2),
                'vaccination'  => (float) \App\Support\Money::round($vaccCost, 2),
                'slaughter'    => (float) \App\Support\Money::round($slaughterCost, 2),
                // 📌 FarmLaborOverheadAbsorption (#48): split into labor + overhead
                // Previously was a single 'overhead' key from the flat settings rate.
                // Both keys preserved for backward compatibility with Vue components.
                'labor'        => (float) \App\Support\Money::round($laborCost, 2),
                'overhead'     => (float) \App\Support\Money::round($overheadCost, 2),
                'total_cogs'   => (float) \App\Support\Money::round($totalCogs, 2),
            ],
            'revenue'          => (float) \App\Support\Money::round($revenue, 2),
            'gross_margin'     => (float) \App\Support\Money::round($grossMargin, 2),
            'gross_margin_pct' => $grossMarginPct,
            'break_even_kg_dw' => $breakEvenWeight,
            'price_per_kg_dw'  => (float) bcadd($pricePerKgDw, '0', 4),
            'adg_fcr'          => $this->adgFcrForAnimal($animalId, $companyId),
        ];
    }

    // ── Lot-level costing ─────────────────────────────────────────
    public function costingForLot(int $lotId, int $companyId): array
    {
        $animals = DB::table('animals')
            ->where('lot_id', $lotId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($animals->isEmpty()) {
            return ['lot_id' => $lotId, 'head_count' => 0];
        }

        $perAnimal = $animals->map(fn($id) => $this->costingForAnimal($id, $companyId));

        $totalRevenue = '0';
        $totalCogs    = '0';
        foreach ($perAnimal as $a) {
            $totalRevenue = bcadd($totalRevenue, (string) $a['revenue'],             4);
            $totalCogs    = bcadd($totalCogs,    (string) $a['costs']['total_cogs'], 4);
        }

        $grossMargin = bcsub($totalRevenue, $totalCogs, 4);
        $gmPct       = (bccomp($totalRevenue, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $totalRevenue, 6), 2)
            : null;

        $avgAdg = $perAnimal->pluck('adg_fcr.adg_kg_day')->filter()->avg();
        $avgFcr = $perAnimal->pluck('adg_fcr.fcr')->filter()->avg();

        $bdPurchase  = '0'; $bdFeed = '0'; $bdMed = '0';
        $bdVacc      = '0'; $bdSlaughter = '0';
        $bdLabor     = '0'; $bdOverhead = '0';   // 📌 #48: split labor + overhead

        foreach ($perAnimal as $a) {
            $bdPurchase  = bcadd($bdPurchase,  (string) $a['costs']['purchase'],    4);
            $bdFeed      = bcadd($bdFeed,      (string) $a['costs']['feed'],        4);
            $bdMed       = bcadd($bdMed,       (string) $a['costs']['medicine'],    4);
            $bdVacc      = bcadd($bdVacc,      (string) $a['costs']['vaccination'], 4);
            $bdSlaughter = bcadd($bdSlaughter, (string) $a['costs']['slaughter'],   4);
            $bdLabor     = bcadd($bdLabor,     (string) $a['costs']['labor'],       4);
            $bdOverhead  = bcadd($bdOverhead,  (string) $a['costs']['overhead'],    4);
        }

        // Use ->count() on Collection — correct and explicit
        $animalCount = $animals->count();

        return [
            'lot_id'           => $lotId,
            'head_count'       => $animalCount,
            'total_revenue'    => (float) \App\Support\Money::round($totalRevenue, 2),
            'total_cogs'       => (float) \App\Support\Money::round($totalCogs, 2),
            'gross_margin'     => (float) \App\Support\Money::round($grossMargin, 2),
            'gross_margin_pct' => $gmPct,
            'avg_adg'          => $avgAdg ? round($avgAdg, 3) : null,
            'avg_fcr'          => $avgFcr ? round($avgFcr, 3) : null,
            'cost_breakdown'   => [
                'purchase'    => (float) \App\Support\Money::round($bdPurchase, 2),
                'feed'        => (float) \App\Support\Money::round($bdFeed, 2),
                'medicine'    => (float) \App\Support\Money::round($bdMed, 2),
                'vaccination' => (float) \App\Support\Money::round($bdVacc, 2),
                'slaughter'   => (float) \App\Support\Money::round($bdSlaughter, 2),
                'labor'       => (float) \App\Support\Money::round($bdLabor, 2),   // 📌 #48 new
                'overhead'    => (float) \App\Support\Money::round($bdOverhead, 2),   // 📌 #48 new
            ],
            'animals'          => $perAnimal->toArray(),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE — Feed cost calculation
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate feed cost for one animal using animal_lot_history.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §10, §16
     * 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
     *
     * For each lot the animal was ever in, queries feed_consumptions
     * date-bounded by the animal's membership period in that lot.
     *
     * Two allocation modes depending on session type:
     *
     * NEW sessions (Migration 060+, have pivot rows in feed_consumption_animals):
     *   Animal gets total_cost / head_count ONLY IF it is in the pivot table
     *   for that session. If not selected → receives 0 from that session.
     *
     * LEGACY sessions (before Migration 060, no pivot rows):
     *   Falls back to total_cost / head_count applied equally to all animals
     *   in the lot during that date range.
     *
     * head_count on feed_consumptions is server-computed as count(animal_ids)
     * since Migration 060 — it is NOT user-entered.
     *
     * Falls back to legacyFeedCostForAnimal() if the animal has no lot history
     * (sold before Migration 059).
     */
    private function calculateFeedCostForAnimal(
        int    $animalId,
        int    $companyId,
        Animal $animal
    ): string {

        // Check if lot history exists for this animal
        $hasHistory = DB::table('animal_lot_history')
            ->where('animal_id', $animalId)
            ->exists();

        if (!$hasHistory) {
            // Legacy fallback: direct query on current lot_id only
            // Used for animals sold before Migration 059
            return $this->legacyFeedCostForAnimal($animalId, $companyId, $animal);
        }

        $feedCost = '0';

        $lotMemberships = DB::table('animal_lot_history')
            ->where('animal_id', $animalId)
            ->orderBy('entered_at')
            ->get();

        foreach ($lotMemberships as $membership) {

            // Date range this animal was in this lot
            $periodStart = $membership->entered_at;
            $periodEnd   = $membership->exited_at ?? now()->toDateString();

            // 📌 FeedConsumptionAnimalSelection — two-path allocation:
            //
            // CASE 1: Session has pivot rows (new-style, Migration 060+)
            //   → Animal gets share only if fca2.animal_id = this animal
            //
            // CASE 2: Session has NO pivot rows (legacy, before Migration 060)
            //   → Animal gets total_cost / head_count (equal share across lot)
            //
            // head_count = server-computed count of selected animals (new)
            //            = user-entered snapshot (legacy, kept for fallback)
            //
            // Only queries feed_consumptions with inventory_item_id set
            // (new-style records). Legacy feed_item records handled by
            // legacyFeedCostForAnimal() via the hasHistory=false path.

            $lotFeedCost = DB::table('feed_consumptions as fc')
                ->where('fc.lot_id', $membership->lot_id)
                ->where('fc.company_id', $companyId)
                ->where('fc.date', '>=', $periodStart)
                ->where('fc.date', '<=', $periodEnd)
                ->whereNotNull('fc.inventory_item_id')
                ->selectRaw('
                    SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1 FROM feed_consumption_animals fca
                                WHERE fca.feed_consumption_id = fc.id
                            )
                            THEN (
                                CASE
                                    WHEN EXISTS (
                                        SELECT 1 FROM feed_consumption_animals fca2
                                        WHERE fca2.feed_consumption_id = fc.id
                                        AND fca2.animal_id = ?
                                    )
                                    THEN fc.total_cost / fc.head_count
                                    ELSE 0
                                END
                            )
                            ELSE fc.total_cost / fc.head_count
                        END
                    ) as share
                ', [$animalId])
                ->value('share');

            if ($lotFeedCost && bccomp((string) $lotFeedCost, '0', 6) > 0) {
                $feedCost = bcadd($feedCost, (string) $lotFeedCost, 6);
            }
        }

        // Round to 4 for COGS accumulation
        // Final round to 2 happens in costingForAnimal() return block
        return bcadd($feedCost, '0', 4);
    }

    /**
     * Legacy feed cost calculation for animals without lot history.
     *
     * Used ONLY for animals that were sold or slaughtered before Migration 059
     * and therefore have no rows in animal_lot_history.
     *
     * Queries directly against the animal's current lot_id — correct only
     * for animals that never transferred lots before the migration.
     *
     * Tries the new inventory_item path first (records after Migration 059
     * but before the animal was registered in lot history), then falls back
     * to the old feed_items join for pre-059 records.
     *
     * @deprecated Only called for pre-Migration-059 animals.
     */
    private function legacyFeedCostForAnimal(
        int    $animalId,
        int    $companyId,
        Animal $animal
    ): string {

        if (!$animal->lot_id) {
            return '0';
        }

        // Try new inventory_item path first (Migration 059+ records, no lot history)
        $cost = DB::table('feed_consumptions')
            ->where('lot_id', $animal->lot_id)
            ->where('company_id', $companyId)
            ->whereNotNull('inventory_item_id')
            ->selectRaw('SUM(total_cost / head_count) as share')
            ->value('share');

        if ($cost && bccomp((string) $cost, '0', 4) > 0) {
            return bcadd((string) $cost, '0', 4);
        }

        // Old legacy path — feed_items catalog (pre-Migration-059 records)
        $legacyCost = DB::table('feed_consumptions as fc')
            ->join('feed_items as fi', 'fi.id', '=', 'fc.feed_item_id')
            ->where('fc.lot_id', $animal->lot_id)
            ->where('fc.company_id', $companyId)
            ->whereNotNull('fc.feed_item_id')
            ->selectRaw('SUM(fc.total_cost / fc.head_count) as share')
            ->value('share');

        return bcadd((string) ($legacyCost ?? '0'), '0', 4);
    }

    /**
     * Calculate feed DM for ADG/FCR — lot level.
     *
     * dm_pct is not yet stored on inventory_items (future enhancement).
     * Falls back to legacy feed_items join for pre-059 records only.
     * Returns 0 for all new-style records until dm_pct is added.
     *
     * TODO: add dm_pct column to inventory_items for feed category,
     *       then update this query to join inventory_items instead.
     */
    private function calculateFeedDmForLot(int $lotId, int $companyId): array
    {
        // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9) — feed dry matter
        //   DM kg = Σ qty_kg × dm% / 100
        //   dm% is taken, in this order, from
        //     1. the feed catalogue entry of the record itself (legacy records)
        //     2. the feed catalogue entry linked to the inventory item (feed_item_id)
        //     3. a default of 88 % when neither carries a dry-matter value
        //        (typical for concentrates and hay) — flagged via 'assumed'.
        $rows = DB::table('feed_consumptions as fc')
            ->leftJoin('feed_items as fi_rec', 'fi_rec.id', '=', 'fc.feed_item_id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'fc.inventory_item_id')
            ->leftJoin('feed_items as fi_inv', 'fi_inv.id', '=', 'ii.feed_item_id')
            ->where('fc.lot_id', $lotId)
            ->where('fc.company_id', $companyId)
            ->get([
                'fc.qty_kg',
                DB::raw('COALESCE(fi_rec.dm_pct, fi_inv.dm_pct) as dm_pct'),
            ]);

        $dm      = '0';
        $assumed = false;
        foreach ($rows as $r) {
            $pct = $r->dm_pct;
            if ($pct === null) {
                $pct     = self::DEFAULT_DM_PCT;
                $assumed = true;
            }
            $dm = bcadd($dm, bcdiv(bcmul((string) $r->qty_kg, (string) $pct, 6), '100', 6), 6);
        }

        return ['dm_kg' => (float) $dm, 'assumed' => $assumed];
    }

    /**
     * Calculate feed DM for ADG/FCR — per animal.
     *
     * Same limitation as calculateFeedDmForLot(): dm_pct not on
     * inventory_items yet. New-style animals return 0 until resolved.
     *
     * TODO: add dm_pct to inventory_items and update this query.
     */
    private function calculateFeedDmForAnimal(
        int    $animalId,
        int    $companyId,
        string $dateFirst,
        string $dateLast
    ): float {

        $memberships = DB::table('animal_lot_history')
            ->where('animal_id', $animalId)
            ->get();

        if ($memberships->isEmpty()) {
            // Legacy: join through feed_items catalog
            return (float) DB::table('feed_consumptions as fc')
                ->join('feed_items as fi', 'fi.id', '=', 'fc.feed_item_id')
                ->where('fc.company_id', $companyId)
                ->whereNotNull('fc.feed_item_id')
                ->whereBetween('fc.date', [$dateFirst, $dateLast])
                ->selectRaw('SUM(fc.qty_kg * fi.dm_pct / 100 / fc.head_count) as dm_total')
                ->value('dm_total') ?? 0;
        }

        // New path — dm_pct not yet on inventory_items
        // Returns 0 until dm_pct column is added to inventory_items
        return 0;
    }

    // ──────────────────────────────────────────────────────────────

    private function nullAdgFcr(string $reason): array
    {
        return [
            'adg_kg_day' => null,
            'fcr'        => null,
            'reason'     => $reason,
        ];
    }
}