<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\Animal;
use App\Models\Company;
use App\Models\Farm;
use App\Models\FarmCostAllocation;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Lot;
use App\Services\FarmCostAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FarmCostAllocationService Tests
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//  📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//
//  Test groups:
//    1.  computeAnimalDays()          — animal-day counting formula
//    2.  computeAnimalDays() edge cases — month boundary clipping
//    3.  partitionShares()            — WIP vs COGS split (via postMonthEndAccrual)
//    4.  rounding residual absorption — always lands in WIP, not COGS
//    5.  previewRates()               — provisional rate preview
//    6.  laborOverheadCostForAnimal() — COGS read, draft months return 0
//    7.  laborOverheadCostForAnimal() — multi-month accumulation
//    8.  laborOverheadCostForAnimal() — multi-farm (lot transfer)
//    9.  postMonthEndAccrual() guards — idempotency + status + fiscal year
//    10. postMonthEndAccrual() journal — balanced entry (JournalBalanceValidation #1)
//    11. settleActual() guards        — wrong status + already settled
//    12. settleActual() variance      — favorable / adverse / zero
//    13. bcmath precision             — no floating-point drift (#39)
//    14. zero-animal-days guard       — no animals on farm this month
//
//  Every group runs against the real test database (RefreshDatabase).
//  The old version mocked the DB facade, which cannot work together with
//  RefreshDatabase and hid a real SQL bug in computeAnimalDays() — so the
//  tests now insert real rows through the factories instead.
//
//  FILE PLACEMENT: tests/Feature/Services/FarmCostAllocationServiceTest.php
// ══════════════════════════════════════════════════════════════════

class FarmCostAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FarmCostAllocationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FarmCostAllocationService();
    }

    private function userId(): int
    {
        return \App\Models\User::factory()->create()->id;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════
    // Fixture helpers
    // ══════════════════════════════════════════════════════════════════

    /** @return array{company: Company, farm: Farm, lot: Lot} */
    private function world(): array
    {
        $company = Company::factory()->create();
        $farm    = Farm::factory()->create(['company_id' => $company->id]);
        $lot     = Lot::factory()->create(['company_id' => $company->id, 'farm_id' => $farm->id]);

        return compact('company', 'farm', 'lot');
    }

    /** Create an animal and a lot-history row for it (exitedAt = null → still in the lot). */
    private function animalInLot(array $w, string $enteredAt, ?string $exitedAt = null, ?Lot $lot = null, array $animal = []): Animal
    {
        $lot ??= $w['lot'];

        $a = Animal::factory()->create(array_merge([
            'company_id' => $lot->company_id,
            'farm_id'    => $lot->farm_id,
            'lot_id'     => $lot->id,
        ], $animal));

        DB::table('animal_lot_history')->insert([
            'animal_id'  => $a->id,
            'lot_id'     => $lot->id,
            'farm_id'    => $lot->farm_id,
            'company_id' => $lot->company_id,
            'entered_at' => $enteredAt,
            'exited_at'  => $exitedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $a;
    }

    private function openFiscalYear(int $companyId): FiscalYear
    {
        return FiscalYear::factory()->create([
            'company_id' => $companyId,
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'open',
        ]);
    }

    private function account(int $companyId, string $code, string $type): Account
    {
        return Account::factory()->create([
            'company_id' => $companyId,
            'code'       => $code,
            'type'       => $type,
        ]);
    }

    private function allocation(array $w, array $attrs = []): FarmCostAllocation
    {
        $fy = FiscalYear::where('company_id', $w['company']->id)->first()
            ?? $this->openFiscalYear($w['company']->id);

        return FarmCostAllocation::create(array_merge([
            'company_id'      => $w['company']->id,
            'farm_id'         => $w['farm']->id,
            'fiscal_year_id'  => $fy->id,
            'year'            => 2025,
            'month'           => 1,
            'month_start'     => '2025-01-01',
            'month_end'       => '2025-01-31',
            'status'          => 'draft',
            'labor_estimated' => 1000.00,
        ], $attrs));
    }

    private function journalEntry(int $companyId, string $type, float $amount): JournalEntry
    {
        return JournalEntry::create([
            'company_id'   => $companyId,
            'date'         => '2025-01-31',
            'period'       => 1,
            'description'  => 'Test entry',
            'status'       => 'posted',
            'entry_type'   => $type,
            'total_debit'  => $amount,
            'total_credit' => $amount,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 1 — computeAnimalDays() basic formula
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §6
    //
    //   days = MIN(exited_at, month_end) − MAX(entered_at, month_start) + 1
    //   exited_at = NULL → substitute month_end (animal still alive)
    //
    // Animal A: whole of January (31 days)
    // Animal B: entered 15 Jan, still alive (17 days)   → total 48
    // ══════════════════════════════════════════════════════════════════

    public function test_computeAnimalDays_returns_correct_days_for_full_month_presence(): void
    {
        $w = $this->world();
        $a = $this->animalInLot($w, '2025-01-01');
        $b = $this->animalInLot($w, '2025-01-15');

        $result = $this->svc->computeAnimalDays($w['farm']->id, $w['company']->id, '2025-01-01', '2025-01-31');

        $this->assertSame(31, $result['animal_days'][$a->id]);
        $this->assertSame(17, $result['animal_days'][$b->id]);
        $this->assertSame(48, $result['total_animal_days']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 2 — computeAnimalDays() edge cases
    // ══════════════════════════════════════════════════════════════════

    public function test_computeAnimalDays_animal_entering_and_exiting_same_day_counts_one(): void
    {
        $w = $this->world();
        $a = $this->animalInLot($w, '2025-01-10', '2025-01-10');

        $result = $this->svc->computeAnimalDays($w['farm']->id, $w['company']->id, '2025-01-01', '2025-01-31');

        $this->assertSame(1, $result['animal_days'][$a->id]);
        $this->assertSame(1, $result['total_animal_days']);
    }

    public function test_computeAnimalDays_returns_empty_when_no_animals(): void
    {
        $w = $this->world();

        $result = $this->svc->computeAnimalDays($w['farm']->id, $w['company']->id, '2025-01-01', '2025-01-31');

        $this->assertEmpty($result['animal_days']);
        $this->assertSame(0, $result['total_animal_days']);
    }

    public function test_computeAnimalDays_ignores_animals_outside_the_month(): void
    {
        $w = $this->world();
        $this->animalInLot($w, '2024-11-01', '2024-12-31'); // left before January
        $this->animalInLot($w, '2025-02-01');               // arrived after January
        $inside = $this->animalInLot($w, '2024-12-20', '2025-01-05'); // clipped to 5 days

        $result = $this->svc->computeAnimalDays($w['farm']->id, $w['company']->id, '2025-01-01', '2025-01-31');

        $this->assertSame([$inside->id => 5], $result['animal_days']);
        $this->assertSame(5, $result['total_animal_days']);
    }

    public function test_computeAnimalDays_only_counts_the_requested_farm(): void
    {
        $w      = $this->world();
        $otherF = Farm::factory()->create(['company_id' => $w['company']->id]);
        $otherL = Lot::factory()->create(['company_id' => $w['company']->id, 'farm_id' => $otherF->id]);

        $mine = $this->animalInLot($w, '2025-01-01');
        $this->animalInLot($w, '2025-01-01', null, $otherL);

        $result = $this->svc->computeAnimalDays($w['farm']->id, $w['company']->id, '2025-01-01', '2025-01-31');

        $this->assertSame([$mine->id => 31], $result['animal_days']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 3 — partitionShares() — WIP vs COGS split
    //
    // 3 animals × 30 days, EGP 3,000; animal 3 has exited.
    //   rate = 3000/90 = 33.33333333 /day → each share 1000.00
    //   Expected: WIP = 2000.00, COGS = 1000.00
    // (private helper → reached with reflection)
    // ══════════════════════════════════════════════════════════════════

    public function test_partitionShares_allocates_correctly_between_wip_and_cogs(): void
    {
        $reflection = new \ReflectionMethod(FarmCostAllocationService::class, 'partitionShares');
        $reflection->setAccessible(true);

        $totalCost  = '3000.00';
        $ratePerDay = bcdiv($totalCost, '90', 8); // 33.33333333

        [$wip, $cogs] = $reflection->invoke(
            $this->svc, $ratePerDay, [1 => 30, 2 => 30, 3 => 30], [3 => true], $totalCost
        );

        $this->assertEquals('2000.00', $wip,  'WIP share should be 2000.00');
        $this->assertEquals('1000.00', $cogs, 'COGS share should be 1000.00');
        $this->assertEquals('0.00', bcsub(bcadd($wip, $cogs, 2), $totalCost, 2));
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 4 — Rounding residual must land in WIP, never COGS
    //
    // EGP 100 over 3 animals × 10 days: each share 33.33 → 99.99 in total;
    // the missing 0.01 is absorbed by WIP. All alive → COGS = 0.00.
    // ══════════════════════════════════════════════════════════════════

    public function test_partitionShares_residual_absorbed_into_wip(): void
    {
        $reflection = new \ReflectionMethod(FarmCostAllocationService::class, 'partitionShares');
        $reflection->setAccessible(true);

        $totalCost  = '100.00';
        $ratePerDay = bcdiv($totalCost, '30', 8);

        [$wip, $cogs] = $reflection->invoke(
            $this->svc, $ratePerDay, [1 => 10, 2 => 10, 3 => 10], [], $totalCost
        );

        $this->assertEquals('100.00', $wip,  'WIP must absorb the rounding residual');
        $this->assertEquals('0.00',   $cogs, 'No exited animals — COGS must be 0.00');
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 5 — previewRates()
    // ══════════════════════════════════════════════════════════════════

    public function test_previewRates_returns_correct_rate_per_animal_day(): void
    {
        $svc = Mockery::mock(FarmCostAllocationService::class)->makePartial();
        $svc->shouldReceive('computeAnimalDays')
            ->once()
            ->with(1, 1, '2025-01-01', '2025-01-31')
            ->andReturn([
                'animal_days'       => [10 => 31, 11 => 31],
                'total_animal_days' => 62,
            ]);

        $result = $svc->previewRates(1, 1, '2025-01-01', '2025-01-31', '6200.00');

        // daily 6200/31 = 200 · per animal-day 6200/62 = 100 · avg days 31 · projected 3100
        $this->assertSame(62,        $result['total_animal_days']);
        $this->assertSame(2,         $result['active_animal_count']);
        $this->assertEquals(200.00,  $result['daily_rate']);
        $this->assertEquals(100.0,   $result['rate_per_animal_day']);
        $this->assertEquals(3100.00, $result['projected_per_animal_month']);
    }

    public function test_previewRates_returns_zeros_when_no_animal_activity(): void
    {
        $svc = Mockery::mock(FarmCostAllocationService::class)->makePartial();
        $svc->shouldReceive('computeAnimalDays')
            ->once()
            ->andReturn(['animal_days' => [], 'total_animal_days' => 0]);

        $result = $svc->previewRates(1, 1, '2025-01-01', '2025-01-31', '5000.00');

        $this->assertSame(0, $result['total_animal_days']);
        $this->assertSame(0, $result['rate_per_animal_day']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 6 — laborOverheadCostForAnimal() — drafts never count
    // ══════════════════════════════════════════════════════════════════

    public function test_laborOverheadCostForAnimal_returns_zeros_for_no_memberships(): void
    {
        $w = $this->world();

        $result = $this->svc->laborOverheadCostForAnimal(999999, $w['company']->id);

        $this->assertEquals(0.0, $result['labor']);
        $this->assertEquals(0.0, $result['overhead']);
    }

    public function test_laborOverheadCostForAnimal_ignores_draft_status_allocations(): void
    {
        $w      = $this->world();
        $animal = $this->animalInLot($w, '2025-01-01', '2025-01-31');

        // A DRAFT allocation exists for the animal's month — it must not be charged.
        $this->allocation($w, [
            'status'            => 'draft',
            'labor_estimated'   => 3000.00,
            'total_animal_days' => 31,
        ]);

        $result = $this->svc->laborOverheadCostForAnimal($animal->id, $w['company']->id);

        $this->assertEquals(0.0, $result['labor']);
        $this->assertEquals(0.0, $result['overhead']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 7 — laborOverheadCostForAnimal() — multi-month accumulation
    //
    //   January:  3000 over 60 animal-days, animal present 31 days → 1550.00
    //   February: 2800 over 56 animal-days, animal present 28 days → 1400.00
    //   Total labor = 2950.00
    // ══════════════════════════════════════════════════════════════════

    public function test_laborOverheadCostForAnimal_accumulates_across_multiple_months(): void
    {
        $w      = $this->world();
        $animal = $this->animalInLot($w, '2025-01-01', '2025-02-28');

        $this->allocation($w, [
            'year' => 2025, 'month' => 1, 'month_start' => '2025-01-01', 'month_end' => '2025-01-31',
            'status' => 'posted', 'labor_estimated' => 3000.00, 'total_animal_days' => 60,
        ]);
        $this->allocation($w, [
            'year' => 2025, 'month' => 2, 'month_start' => '2025-02-01', 'month_end' => '2025-02-28',
            'status' => 'posted', 'labor_estimated' => 2800.00, 'total_animal_days' => 56,
        ]);

        $result = $this->svc->laborOverheadCostForAnimal($animal->id, $w['company']->id);

        $this->assertEqualsWithDelta(2950.00, $result['labor'], 0.01, 'Labor must accumulate across months');
        $this->assertEquals(0.0, $result['overhead'], 'No overhead lines seeded — must be 0.00');
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 8 — laborOverheadCostForAnimal() — lot transfer across farms
    //
    //   Farm A: 1 Jan–15 Jan (15 days) of 1500 over 30 animal-days → 750.00
    //   Farm B: 16 Jan–open  (16 days) of 1600 over 32 animal-days → 800.00
    //   Total = 1550.00
    // ══════════════════════════════════════════════════════════════════

    public function test_laborOverheadCostForAnimal_handles_lot_transfer_across_farms(): void
    {
        $company = Company::factory()->create();
        $farmA   = Farm::factory()->create(['company_id' => $company->id]);
        $farmB   = Farm::factory()->create(['company_id' => $company->id]);
        $lotA    = Lot::factory()->create(['company_id' => $company->id, 'farm_id' => $farmA->id]);
        $lotB    = Lot::factory()->create(['company_id' => $company->id, 'farm_id' => $farmB->id]);

        $animal = $this->animalInLot(['company' => $company], '2025-01-01', '2025-01-15', $lotA);
        DB::table('animal_lot_history')->insert([
            'animal_id'  => $animal->id,
            'lot_id'     => $lotB->id,
            'farm_id'    => $farmB->id,
            'company_id' => $company->id,
            'entered_at' => '2025-01-16',
            'exited_at'  => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->allocation(['company' => $company, 'farm' => $farmA], [
            'status' => 'posted', 'labor_estimated' => 1500.00, 'total_animal_days' => 30,
        ]);
        $this->allocation(['company' => $company, 'farm' => $farmB], [
            'status' => 'posted', 'labor_estimated' => 1600.00, 'total_animal_days' => 32,
        ]);

        $result = $this->svc->laborOverheadCostForAnimal($animal->id, $company->id);

        $this->assertEqualsWithDelta(1550.00, $result['labor'], 0.01, 'Multi-farm labor share must sum correctly');
        $this->assertEquals(0.0, $result['overhead']);
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 9 — postMonthEndAccrual() guards
    // ══════════════════════════════════════════════════════════════════

    public function test_postMonthEndAccrual_throws_if_already_posted(): void
    {
        $w  = $this->world();
        $je = $this->journalEntry($w['company']->id, 'labor_overhead_accrual', 1000);

        $allocation = $this->allocation($w, [
            'status'                 => 'posted',
            'labor_journal_entry_id' => $je->id,   // idempotency trigger
            'total_animal_days'      => 30,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Already posted/');

        $this->svc->postMonthEndAccrual($allocation, $this->userId());
    }

    public function test_postMonthEndAccrual_throws_if_status_not_draft(): void
    {
        $w          = $this->world();
        $allocation = $this->allocation($w, ['status' => 'settled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot post/');

        $this->svc->postMonthEndAccrual($allocation, $this->userId());
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 10 — postMonthEndAccrual() journal entry is balanced
    //
    // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
    // ══════════════════════════════════════════════════════════════════

    public function test_postMonthEndAccrual_creates_balanced_journal_entry(): void
    {
        $w = $this->world();
        $this->openFiscalYear($w['company']->id);
        $this->account($w['company']->id, '1310', 'asset');
        $this->account($w['company']->id, '5100', 'cogs');
        $this->account($w['company']->id, '2260', 'liability');
        $this->animalInLot($w, '2025-01-01');

        $allocation = $this->allocation($w, ['labor_estimated' => 3100.00]);

        $je = $this->svc->postMonthEndAccrual($allocation, $this->userId());

        $this->assertNotNull($je->id, 'Journal entry must be persisted');
        $this->assertEqualsWithDelta((float) $je->total_debit, (float) $je->total_credit, 0.005,
            'Journal entry must be balanced (|Dr - Cr| ≤ 0.005) — JournalBalanceValidation #1'
        );
        $this->assertEqualsWithDelta(3100.00, (float) $je->total_debit, 0.005);

        $fresh = FarmCostAllocation::find($allocation->id);
        $this->assertSame('posted', $fresh->status);
        $this->assertSame($je->id, $fresh->labor_journal_entry_id);
        $this->assertSame(31, $fresh->total_animal_days);
    }

    public function test_postMonthEndAccrual_sends_exited_animals_share_to_cogs(): void
    {
        $w = $this->world();
        $this->openFiscalYear($w['company']->id);
        $wip  = $this->account($w['company']->id, '1310', 'asset');
        $cogs = $this->account($w['company']->id, '5100', 'cogs');
        $this->account($w['company']->id, '2260', 'liability');

        $this->animalInLot($w, '2025-01-01');                                   // alive: 31 days
        $this->animalInLot($w, '2025-01-01', '2025-01-31', null, [              // sold in January: 31 days
            'status' => 'sold', 'status_date' => '2025-01-31',
        ]);

        $allocation = $this->allocation($w, ['labor_estimated' => 6200.00]);
        $je = $this->svc->postMonthEndAccrual($allocation, $this->userId());

        $dr = fn (int $accountId) => (float) DB::table('journal_lines')
            ->where('entry_id', $je->id)->where('account_id', $accountId)->sum('debit');

        $this->assertEqualsWithDelta(3100.00, $dr($wip->id),  0.005, 'Alive animal → WIP');
        $this->assertEqualsWithDelta(3100.00, $dr($cogs->id), 0.005, 'Sold animal → COGS');
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 11 — settleActual() guards
    // ══════════════════════════════════════════════════════════════════

    public function test_settleActual_throws_if_allocation_not_posted(): void
    {
        $w          = $this->world();
        $allocation = $this->allocation($w, ['status' => 'draft']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot settle/');

        $this->svc->settleActual($allocation, '2000.00', [], 1, $this->userId());
    }

    public function test_settleActual_throws_if_already_settled(): void
    {
        $w          = $this->world();
        $je         = $this->journalEntry($w['company']->id, 'labor_overhead_settlement', 2000);
        $allocation = $this->allocation($w, [
            'status'                 => 'posted',
            'labor_settlement_je_id' => $je->id,   // already settled
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Already settled/');

        $this->svc->settleActual($allocation, '2000.00', [], 1, $this->userId());
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 12 — settleActual() variance calculations
    //
    // 📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §10, §22
    // ══════════════════════════════════════════════════════════════════

    /** @return array{allocation: FarmCostAllocation, paymentAcct: Account} */
    private function postedAllocationReadyToSettle(): array
    {
        $w = $this->world();
        $this->openFiscalYear($w['company']->id);
        $this->account($w['company']->id, '2260', 'liability');
        $this->account($w['company']->id, '6310', 'expense');
        $payment = $this->account($w['company']->id, '1410', 'asset');

        $accrual = $this->journalEntry($w['company']->id, 'labor_overhead_accrual', 3000);

        $allocation = $this->allocation($w, [
            'status'                 => 'posted',
            'labor_estimated'        => 3000.00,
            'labor_journal_entry_id' => $accrual->id,
        ]);

        return ['allocation' => $allocation, 'paymentAcct' => $payment];
    }

    public function test_settleActual_favorable_variance_creates_balanced_je(): void
    {
        // Actual (2800) < Estimated (3000) → favorable: Cr Variance by 200
        $base = $this->postedAllocationReadyToSettle();

        $je = $this->svc->settleActual($base['allocation'], '2800.00', [], $base['paymentAcct']->id, $this->userId());

        $this->assertEqualsWithDelta((float) $je->total_debit, (float) $je->total_credit, 0.005,
            'Settlement JE must be balanced — JournalBalanceValidation #1'
        );

        $fresh = FarmCostAllocation::find($base['allocation']->id);
        $this->assertSame('settled', $fresh->status);
        $this->assertEqualsWithDelta(3000.00, (float) $fresh->labor_estimated, 0.01);
        $this->assertEqualsWithDelta(2800.00, (float) $fresh->labor_actual,    0.01);
        $this->assertEqualsWithDelta(-200.00, (float) $fresh->labor_variance,  0.01,
            'Favorable variance must be negative (actual < estimated)'
        );
    }

    public function test_settleActual_adverse_variance_creates_balanced_je(): void
    {
        // Actual (3400) > Estimated (3000) → adverse: Dr Variance by 400
        $base = $this->postedAllocationReadyToSettle();

        $je = $this->svc->settleActual($base['allocation'], '3400.00', [], $base['paymentAcct']->id, $this->userId());

        $this->assertEqualsWithDelta((float) $je->total_debit, (float) $je->total_credit, 0.005,
            'Settlement JE must be balanced even with adverse variance'
        );

        $fresh = FarmCostAllocation::find($base['allocation']->id);
        $this->assertEqualsWithDelta(400.00, (float) $fresh->labor_variance, 0.01,
            'Adverse variance must be positive (actual > estimated)'
        );
    }

    public function test_settleActual_zero_variance_creates_balanced_je(): void
    {
        $base = $this->postedAllocationReadyToSettle();

        $je = $this->svc->settleActual($base['allocation'], '3000.00', [], $base['paymentAcct']->id, $this->userId());

        $this->assertEqualsWithDelta((float) $je->total_debit, (float) $je->total_credit, 0.005);

        $fresh = FarmCostAllocation::find($base['allocation']->id);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->labor_variance, 0.01,
            'Zero variance when actual equals estimated'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 13 — bcmath precision (#38)
    //
    // 10 animals × 30 days = 300 animal-days, EGP 10,000.
    // Rate = 33.333… (repeating). Each share must round to 1000.00 and the
    // total must be exactly 10000.00 — no drift, nothing lost.
    // ══════════════════════════════════════════════════════════════════

    public function test_partitionShares_no_float_drift_with_repeating_decimal_rate(): void
    {
        $reflection = new \ReflectionMethod(FarmCostAllocationService::class, 'partitionShares');
        $reflection->setAccessible(true);

        $totalCost  = '10000.00';
        $ratePerDay = bcdiv($totalCost, '300', 8);

        $animalDays = [];
        for ($i = 1; $i <= 10; $i++) {
            $animalDays[$i] = 30;
        }

        [$wip, $cogs] = $reflection->invoke($this->svc, $ratePerDay, $animalDays, [], $totalCost);

        $this->assertEquals('10000.00', $wip, 'bcmath must produce the exact total — no drift (#38)');
        $this->assertEquals('0.00', $cogs, 'No exited animals — COGS must be 0.00');
        $this->assertEquals($totalCost, bcadd($wip, $cogs, 2), 'WIP + COGS must always equal total cost');
    }

    // ══════════════════════════════════════════════════════════════════
    // GROUP 14 — Zero animal-days guard
    // ══════════════════════════════════════════════════════════════════

    public function test_postMonthEndAccrual_throws_when_no_animal_activity_on_farm(): void
    {
        $w = $this->world();
        $this->openFiscalYear($w['company']->id);   // so the fiscal-year guard doesn't fire first

        $allocation = $this->allocation($w, ['labor_estimated' => 5000.00]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No animal activity/');

        $this->svc->postMonthEndAccrual($allocation, $this->userId());
    }
}
