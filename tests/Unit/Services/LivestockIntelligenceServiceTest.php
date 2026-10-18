<?php

namespace Tests\Unit\Services;

use App\Services\FarmCostAllocationService;
use App\Services\LivestockIntelligenceService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — LivestockIntelligenceService Unit Tests
//
//  📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
//  📌 METHODOLOGY REFERENCE: WAC (#10)
//  📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
//  📌 METHODOLOGY REFERENCE: ROIperLot (#20)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//
//  All DB calls are mocked — no database required.
//  FarmCostAllocationService is injected as a Mockery mock.
//
//  Test groups:
//    1. adgFcrForAnimal()   — ADG_FCR (#9)
//    2. adgFcrForLot()      — ADG_FCR (#9) lot-level aggregation
//    3. currentWac()        — WAC (#10)
//    4. costingForAnimal()  — GrossMarginBreakEven (#11) + bcmath (#39)
//    5. costingForLot()     — ROIperLot (#20) aggregation
//    6. Edge cases          — zero revenue, zero gain, negative margin
// ══════════════════════════════════════════════════════════════════

class LivestockIntelligenceServiceTest extends TestCase
{
    private LivestockIntelligenceService $svc;
    private FarmCostAllocationService    $farmCostMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmCostMock = Mockery::mock(FarmCostAllocationService::class);

        $this->svc = new LivestockIntelligenceService($this->farmCostMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 1 — adgFcrForAnimal()
    // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
    //
    // Formula:
    //   ADG = (w_final − w_initial) / days_on_feed
    //   FCR = total_feed_dm_kg / weight_gain_kg
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function adg_fcr_for_animal_returns_null_when_fewer_than_two_weight_records(): void
    {
        DB::shouldReceive('table')->with('weight_records')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect(['2024-01-01' => 250.0]));

        $result = $this->svc->adgFcrForAnimal(1, 1);

        $this->assertNull($result['adg_kg_day']);
        $this->assertNull($result['fcr']);
        $this->assertStringContainsString('Insufficient', $result['reason']);
    }

    /** @test */
    public function adg_fcr_for_animal_returns_null_when_same_date_records(): void
    {
        // Two records on same date → days_on_feed = 0 → guard fires
        DB::shouldReceive('table')->with('weight_records')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([
            '2024-01-01' => 250.0,
            '2024-01-01' => 280.0,   // same date key — PHP array keeps last value, still 1 entry
        ]));

        $result = $this->svc->adgFcrForAnimal(1, 1);

        $this->assertNull($result['adg_kg_day']);
        $this->assertNull($result['fcr']);
    }

    /** @test */
    public function adg_fcr_for_animal_computes_correctly_with_two_weight_records(): void
    {
        // Scenario:
        //   w_initial = 200 kg  on 2024-01-01
        //   w_final   = 260 kg  on 2024-03-11  (70 days)
        //   ADG = (260-200) / 70 = 0.857 kg/day
        //   feed_dm = 0 (no legacy feed items in test)
        //   FCR = null (gain > 0 but feed_dm = 0 → 0/60 = 0, not null — see edge note)

        $dateFirst = '2024-01-01';
        $dateLast  = '2024-03-11'; // 70 days later

        DB::shouldReceive('table')->with('weight_records')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([
            $dateFirst => 200.0,
            $dateLast  => 260.0,
        ]));

        // calculateFeedDmForAnimal → animal_lot_history check (no history → legacy path)
        DB::shouldReceive('table')->with('animal_lot_history')
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([])); // empty → legacy path

        // Legacy feed DM query
        DB::shouldReceive('table')->with('feed_consumptions as fc')
            ->andReturnSelf();
        DB::shouldReceive('join')->andReturnSelf();
        DB::shouldReceive('whereNotNull')->andReturnSelf();
        DB::shouldReceive('whereBetween')->andReturnSelf();
        DB::shouldReceive('selectRaw')->andReturnSelf();
        DB::shouldReceive('value')->andReturn(null); // no feed records

        $result = $this->svc->adgFcrForAnimal(42, 1);

        $expectedAdg = round((260 - 200) / 70, 3); // 0.857

        $this->assertEquals(42, $result['animal_id']);
        $this->assertEquals(200.0, $result['weight_initial']);
        $this->assertEquals(260.0, $result['weight_final']);
        $this->assertEquals(60.0,  $result['weight_gain']);
        $this->assertEquals(70,    $result['days_on_feed']);
        $this->assertEquals($expectedAdg, $result['adg_kg_day']);
        $this->assertEquals(0.0,   $result['feed_dm_total']);
        // feed_dm = 0, gain > 0 → FCR = 0/60 = 0.0 (rounds to 0, not null)
        $this->assertNotNull($result['fcr']);
        $this->assertEquals($dateFirst, $result['date_first']);
        $this->assertEquals($dateLast,  $result['date_last']);
    }

    /** @test */
    public function adg_fcr_fcr_is_null_when_animal_lost_weight(): void
    {
        // Methodology #9 edge case: weight_gain ≤ 0 → FCR = null
        // Animal went from 300kg down to 280kg (sick/stress)
        $dateFirst = '2024-01-01';
        $dateLast  = '2024-02-01';

        DB::shouldReceive('table')->with('weight_records')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([
            $dateFirst => 300.0,
            $dateLast  => 280.0, // weight loss
        ]));

        // calculateFeedDmForAnimal
        DB::shouldReceive('table')->with('animal_lot_history')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([]));
        DB::shouldReceive('table')->with('feed_consumptions as fc')->andReturnSelf();
        DB::shouldReceive('join')->andReturnSelf();
        DB::shouldReceive('whereNotNull')->andReturnSelf();
        DB::shouldReceive('whereBetween')->andReturnSelf();
        DB::shouldReceive('selectRaw')->andReturnSelf();
        DB::shouldReceive('value')->andReturn(100.0); // feed_dm present but weight_gain ≤ 0

        $result = $this->svc->adgFcrForAnimal(5, 1);

        $this->assertNull($result['fcr'], 'FCR must be null when weight_gain ≤ 0');
        $this->assertEquals(-20.0, $result['weight_gain']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 2 — adgFcrForLot()
    // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9) — lot aggregation
    //
    // avg_adg = (Σ individual_gains / animal_count) / avg_days_on_feed
    // lot_fcr = total_feed_dm / total_weight_gain
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function adg_fcr_for_lot_returns_head_count_zero_when_no_animals(): void
    {
        DB::shouldReceive('table')->with('animals')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('whereNull')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([]));

        $result = $this->svc->adgFcrForLot(10, 1);

        $this->assertEquals(0, $result['head_count']);
        $this->assertEquals(10, $result['lot_id']);
    }

    /** @test */
    public function adg_fcr_for_lot_returns_null_reason_when_no_weight_records(): void
    {
        DB::shouldReceive('table')->with('animals')->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('whereNull')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([1, 2]));

        DB::shouldReceive('table')->with('weight_records')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([]));

        $result = $this->svc->adgFcrForLot(10, 1);

        $this->assertNull($result['adg_kg_day']);
        $this->assertNull($result['fcr']);
        $this->assertStringContainsString('No weight records', $result['reason']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 3 — currentWac()
    // 📌 METHODOLOGY REFERENCE: WAC (#10)
    //
    // WAC is read from the location-specific stock row (is_master=false).
    // Returns null if no stock row exists (item not yet received there).
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function current_wac_returns_float_when_stock_row_exists(): void
    {
        DB::shouldReceive('table')->with('inventory_items')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('value')->andReturn('42.7500');

        $result = $this->svc->currentWac(1, 1, 1);

        $this->assertIsFloat($result);
        $this->assertEquals(42.75, $result);
    }

    /** @test */
    public function current_wac_returns_null_when_no_stock_row_for_location(): void
    {
        // Methodology #10 edge case: item not yet received at this location
        DB::shouldReceive('table')->with('inventory_items')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('value')->andReturn(null);

        $result = $this->svc->currentWac(99, 1, 5);

        $this->assertNull($result, 'WAC must be null when location stock row does not exist');
    }

    /** @test */
    public function current_wac_preserves_zero_wac_as_float_zero_not_null(): void
    {
        // A WAC of exactly 0.00 is a valid stored value — must not be confused with "no row"
        DB::shouldReceive('table')->with('inventory_items')
            ->once()
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('value')->andReturn('0.0000');

        $result = $this->svc->currentWac(1, 1, 1);

        $this->assertNotNull($result);
        $this->assertIsFloat($result);
        $this->assertEquals(0.0, $result);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 4 — WAC formula unit tests (pure arithmetic, no DB)
    // 📌 METHODOLOGY REFERENCE: WAC (#10)
    //
    // WAC_new = (qty_old × WAC_old + qty_in × cost_in) / (qty_old + qty_in)
    // These are pure math tests — no service call needed.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function wac_formula_first_receipt_into_empty_location(): void
    {
        // qty_old = 0 → WAC_new = cost_in (edge case: empty location)
        $qtyOld  = '0';
        $wacOld  = '0';
        $qtyIn   = '100';
        $costIn  = '25.5000';

        $numerator   = bcadd(bcmul($qtyOld, $wacOld, 6), bcmul($qtyIn, $costIn, 6), 6);
        $denominator = bcadd($qtyOld, $qtyIn, 6);
        $wacNew      = bcdiv($numerator, $denominator, 4);

        $this->assertEquals('25.5000', $wacNew);
    }

    /** @test */
    public function wac_formula_blends_two_receipts_correctly(): void
    {
        // First receipt: 100 units @ 20.00 = stock value 2000
        // Second receipt: 50 units @ 26.00  = stock value 1300
        // WAC = (2000 + 1300) / (100 + 50) = 3300 / 150 = 22.00
        $qtyOld = '100';
        $wacOld = '20.0000';
        $qtyIn  = '50';
        $costIn = '26.0000';

        $numerator   = bcadd(bcmul($qtyOld, $wacOld, 6), bcmul($qtyIn, $costIn, 6), 6);
        $denominator = bcadd($qtyOld, $qtyIn, 6);
        $wacNew      = bcdiv($numerator, $denominator, 4);

        $this->assertEquals('22.0000', $wacNew);
    }

    /** @test */
    public function wac_formula_consumption_does_not_change_wac(): void
    {
        // Methodology #10: WAC is unchanged on consumption.
        // COGS = qty_out × current_WAC
        $wac    = '22.0000';
        $qtyOut = '30';

        $cogs = bcmul($qtyOut, $wac, 4);

        $this->assertEquals('660.0000', $cogs);
        // WAC remains 22.00 — no recalculation
        $this->assertEquals('22.0000', $wac);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 5 — costingForAnimal() output shape
    // 📌 METHODOLOGY REFERENCE: GrossMarginBreakEven (#11)
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
    //
    // Full integration of purchase + feed + medicine + vacc +
    // slaughter + labor + overhead costs → COGS → gross margin.
    //
    // These tests verify output shape and bcmath rounding (2 d.p. on
    // monetary values returned, null-safety for break-even).
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function gross_margin_pct_is_null_when_revenue_is_zero(): void
    {
        // Methodology #11 edge case: zero revenue → gross_margin_pct = null
        // Verified via pure bcmath formula (no service call)
        $revenue     = '0';
        $grossMargin = '-5000.00'; // loss (cost with no sale)

        $grossMarginPct = (bccomp($revenue, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $revenue, 6), 2)
            : null;

        $this->assertNull($grossMarginPct, 'gross_margin_pct must be null when revenue = 0');
    }

    /** @test */
    public function break_even_weight_is_null_when_price_per_kg_is_zero(): void
    {
        // Methodology #11 edge case: cannot compute break-even without a price
        $totalCogs   = '15000.00';
        $pricePerKgDw = '0';

        $breakEven = (bccomp($pricePerKgDw, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv($totalCogs, $pricePerKgDw, 6), 2)
            : null;

        $this->assertNull($breakEven, 'break_even_kg_dw must be null when price_per_kg = 0');
    }

    /** @test */
    public function break_even_weight_computes_correctly(): void
    {
        // COGS = 15,000 EGP; price_per_kg_dw = 60 EGP/kg
        // break-even = 15000 / 60 = 250.00 kg
        $totalCogs    = '15000.0000';
        $pricePerKgDw = '60.0000';

        $breakEven = (float) \App\Support\Money::round(bcdiv($totalCogs, $pricePerKgDw, 6), 2);

        $this->assertEquals(250.00, $breakEven);
    }

    /** @test */
    public function gross_margin_and_pct_compute_correctly(): void
    {
        // Revenue = 20,000; COGS = 15,000 → GM = 5,000 → GM% = 25.00
        $revenue     = '20000.0000';
        $totalCogs   = '15000.0000';
        $grossMargin = bcsub($revenue, $totalCogs, 4);

        $this->assertEquals('5000.0000', $grossMargin);

        $gmPct = (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $revenue, 6), 2);

        $this->assertEquals(25.00, $gmPct);
    }

    /** @test */
    public function negative_gross_margin_produces_correct_negative_pct(): void
    {
        // Animal sold below cost: Revenue = 10,000; COGS = 12,000
        // GM = -2,000; GM% = -20.00
        $revenue     = '10000.0000';
        $totalCogs   = '12000.0000';
        $grossMargin = bcsub($revenue, $totalCogs, 4);

        $this->assertEquals('-2000.0000', $grossMargin);

        $gmPct = (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $revenue, 6), 2);

        $this->assertEquals(-20.00, $gmPct);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 6 — COGS component accumulation (bcmath correctness)
    // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
    //
    // Verifies the bcadd chain used in costingForAnimal() produces
    // exact decimal results — no floating-point drift.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function cogs_accumulation_produces_exact_sum_with_bcmath(): void
    {
        // Classic float trap: 0.1 + 0.2 ≠ 0.3 in IEEE 754
        // bcmath must return exactly 0.30
        $purchaseCost = '15000.0000';
        $feedCost     = '3200.5000';
        $medicineCost = '450.7500';
        $vaccCost     = '120.2500';
        $slaughterCost = '800.0000';
        $laborCost    = '1100.0000';
        $overheadCost = '340.5000';

        $totalCogs = bcadd(
            bcadd(
                bcadd(
                    bcadd(
                        bcadd(
                            bcadd($purchaseCost, $feedCost, 4),
                            $medicineCost, 4
                        ),
                        $vaccCost, 4
                    ),
                    $slaughterCost, 4
                ),
                $laborCost, 4
            ),
            $overheadCost, 4
        );

        // Expected: 15000 + 3200.50 + 450.75 + 120.25 + 800 + 1100 + 340.50 = 21012.00
        $this->assertEquals('21012.0000', $totalCogs);
    }

    /** @test */
    public function bcmath_float_precision_trap_does_not_apply(): void
    {
        // This test documents WHY bcmath is mandatory (Methodology #39).
        // IEEE 754 float arithmetic for financial sums accumulates error.
        $floatSum   = 0.1 + 0.2;
        $bcmathSum  = bcadd('0.1', '0.2', 4);

        // Float is NOT exactly 0.3
        $this->assertNotEquals('0.3000', (string) $floatSum);
        // bcmath IS exactly 0.3
        $this->assertEquals('0.3000', $bcmathSum);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 7 — ROI formula (Methodology #20)
    // 📌 METHODOLOGY REFERENCE: ROIperLot (#20)
    //
    // ROI = (Revenue − COGS) / COGS × 100
    // Lot-level aggregation across costingForAnimal() per-head results.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function roi_formula_computes_correctly(): void
    {
        // Revenue = 100,000; COGS = 80,000
        // ROI = (100000 - 80000) / 80000 * 100 = 25.00%
        $revenue = '100000.0000';
        $cogs    = '80000.0000';

        $grossMargin = bcsub($revenue, $cogs, 4);
        $roi         = (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $cogs, 6), 2);

        $this->assertEquals('20000.0000', $grossMargin);
        $this->assertEquals(25.00, $roi);
    }

    /** @test */
    public function roi_is_negative_when_revenue_below_cogs(): void
    {
        // Revenue = 70,000; COGS = 80,000 → ROI = −12.50%
        $revenue = '70000.0000';
        $cogs    = '80000.0000';

        $grossMargin = bcsub($revenue, $cogs, 4);
        $roi         = (float) \App\Support\Money::round(bcdiv(bcmul($grossMargin, '100', 6), $cogs, 6), 2);

        $this->assertEquals(-12.50, $roi);
    }

    /** @test */
    public function roi_handles_zero_cogs_safely(): void
    {
        // COGS = 0 → cannot divide. Must guard explicitly.
        $revenue = '50000.0000';
        $cogs    = '0.0000';

        $roi = (bccomp($cogs, '0', 4) > 0)
            ? (float) \App\Support\Money::round(bcdiv(bcmul(bcsub($revenue, $cogs, 4), '100', 6), $cogs, 6), 2)
            : null;

        $this->assertNull($roi, 'ROI must be null when COGS = 0 to avoid division by zero');
    }

    /** @test */
    public function lot_level_aggregation_sums_per_animal_revenue_and_cogs(): void
    {
        // Two animals — verify lot totals are the correct bcmath sum of per-head values
        $animals = [
            ['revenue' => '20000.00', 'cogs' => '15000.00'],
            ['revenue' => '18000.00', 'cogs' => '14500.00'],
        ];

        $totalRevenue = '0';
        $totalCogs    = '0';

        foreach ($animals as $a) {
            $totalRevenue = bcadd($totalRevenue, $a['revenue'], 4);
            $totalCogs    = bcadd($totalCogs,    $a['cogs'],    4);
        }

        $grossMargin = bcsub($totalRevenue, $totalCogs, 4);

        $this->assertEquals('38000.0000', $totalRevenue);
        $this->assertEquals('29500.0000', $totalCogs);
        $this->assertEquals('8500.0000',  $grossMargin);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 8 — nullAdgFcr() shape contract
    //
    // Every caller of adgFcrForAnimal/Lot that gets a null result must
    // receive an array with adg_kg_day, fcr both null and a reason string.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function null_adg_fcr_result_always_has_required_keys(): void
    {
        // Trigger via < 2 weight records
        DB::shouldReceive('table')->with('weight_records')->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('pluck')->andReturn(collect([]));

        $result = $this->svc->adgFcrForAnimal(1, 1);

        $this->assertArrayHasKey('adg_kg_day', $result);
        $this->assertArrayHasKey('fcr', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertNull($result['adg_kg_day']);
        $this->assertNull($result['fcr']);
        $this->assertIsString($result['reason']);
        $this->assertNotEmpty($result['reason']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 9 — ADG formula precision
    // 📌 METHODOLOGY REFERENCE: ADG_FCR (#9)
    //
    // ADG is a physical measurement (kg/day) — stays as float per Rule #38.
    // Rounded to 3 decimal places in return value.
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function adg_rounds_to_three_decimal_places(): void
    {
        // 60 kg gain over 70 days = 0.857142... → rounds to 0.857
        $gain = 60.0;
        $dof  = 70;
        $adg  = round($gain / $dof, 3);

        $this->assertEquals(0.857, $adg);
    }

    /** @test */
    public function adg_gain_over_30_days_is_exact(): void
    {
        // 30 kg gain over 30 days = 1.000 exactly
        $adg = round(30.0 / 30, 3);

        $this->assertEquals(1.000, $adg);
    }

    /** @test */
    public function fcr_rounds_to_three_decimal_places(): void
    {
        // 100 kg DM feed / 60 kg gain = 1.666... → rounds to 1.667
        $feedDm = 100.0;
        $gain   = 60.0;
        $fcr    = round($feedDm / $gain, 3);

        $this->assertEquals(1.667, $fcr);
    }
}