<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Services\CompanySubscriptionService;
use Carbon\Carbon;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — CompanySubscriptionService Unit Tests
//
//  Pure date logic — no database, no migrations, no RefreshDatabase.
//  Carbon::setTestNow() pins "today" so every assertion is
//  deterministic regardless of when the test suite runs.
//
//  Three methods under test:
//    isExpired()     — blocks login when subscription has lapsed
//    daysRemaining() — countdown shown in the UI warning banner
//    expiresSoon()   — triggers the "expiring soon" warning
//
//  Test groups:
//    1.  isExpired() — null company (super-admin bypass)
//    2.  isExpired() — null subscription_ends_at (unrestricted)
//    3.  isExpired() — ends_at in the future (valid)
//    4.  isExpired() — ends_at is today (still valid — expires at end of day)
//    5.  isExpired() — ends_at was yesterday (expired)
//    6.  isExpired() — ends_at was months ago (long-expired)
//    7.  daysRemaining() — null company returns null
//    8.  daysRemaining() — no ends_at returns null
//    9.  daysRemaining() — future date returns correct day count
//    10. daysRemaining() — ends_at is today returns 0
//    11. daysRemaining() — already expired returns 0, never negative
//    12. expiresSoon() — null company returns false
//    13. expiresSoon() — no ends_at returns false
//    14. expiresSoon() — expires within default window (14 days)
//    15. expiresSoon() — expires outside default window
//    16. expiresSoon() — already expired returns false (0 days left)
//    17. expiresSoon() — custom window respected
// ══════════════════════════════════════════════════════════════════

class CompanySubscriptionServiceTest extends TestCase
{
    private CompanySubscriptionService $svc;

    // Pin "today" to a fixed date for all tests
    private const TODAY = '2025-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CompanySubscriptionService();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // unpin after each test
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 1 — isExpired() null company
    //
    // Super admins have company_id = null — the controller passes
    // null to this service. Must never block them.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_false_for_null_company(): void
    {
        $this->assertFalse($this->svc->isExpired(null));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 2 — isExpired() unrestricted subscription
    //
    // When subscription_ends_at is null the company has no expiry
    // date — this is the "unlimited" plan. Must never be blocked.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_false_when_no_ends_at_is_set(): void
    {
        $company = $this->makeCompany(null);
        $this->assertFalse($this->svc->isExpired($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 3 — isExpired() valid subscription
    //
    // ends_at is in the future → company can log in normally.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_false_when_ends_at_is_tomorrow(): void
    {
        $company = $this->makeCompany('2025-06-16'); // tomorrow
        $this->assertFalse($this->svc->isExpired($company));
    }

    /** @test */
    public function is_expired_returns_false_when_ends_at_is_far_future(): void
    {
        $company = $this->makeCompany('2026-12-31');
        $this->assertFalse($this->svc->isExpired($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 4 — isExpired() expires today
    //
    // ends_at = today → subscription is valid until end of day.
    // The company must NOT be blocked — they paid for today.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_false_when_ends_at_is_today(): void
    {
        $company = $this->makeCompany(self::TODAY); // same as today
        $this->assertFalse($this->svc->isExpired($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 5 — isExpired() expired yesterday
    //
    // ends_at = yesterday → first day the subscription is lapsed.
    // Must block login.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_true_when_ends_at_was_yesterday(): void
    {
        $company = $this->makeCompany('2025-06-14'); // yesterday
        $this->assertTrue($this->svc->isExpired($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 6 — isExpired() long-expired
    //
    // ends_at was months ago — clearly expired.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function is_expired_returns_true_when_ends_at_was_months_ago(): void
    {
        $company = $this->makeCompany('2025-01-01'); // 5.5 months ago
        $this->assertTrue($this->svc->isExpired($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 7 — daysRemaining() null company
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function days_remaining_returns_null_for_null_company(): void
    {
        $this->assertNull($this->svc->daysRemaining(null));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 8 — daysRemaining() unrestricted
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function days_remaining_returns_null_when_no_ends_at_set(): void
    {
        $company = $this->makeCompany(null);
        $this->assertNull($this->svc->daysRemaining($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 9 — daysRemaining() future date
    //
    // Today = 2025-06-15, ends_at = 2025-06-25 → 10 days remaining.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function days_remaining_returns_correct_count_for_future_date(): void
    {
        $company = $this->makeCompany('2025-06-25'); // 10 days away
        $this->assertEquals(10, $this->svc->daysRemaining($company));
    }

    /** @test */
    public function days_remaining_returns_one_when_expires_tomorrow(): void
    {
        $company = $this->makeCompany('2025-06-16'); // 1 day away
        $this->assertEquals(1, $this->svc->daysRemaining($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 10 — daysRemaining() expires today
    //
    // Today is the last day — 0 days remaining but NOT expired yet.
    // The UI should show "0 days left" as a final warning.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function days_remaining_returns_zero_when_ends_at_is_today(): void
    {
        $company = $this->makeCompany(self::TODAY);
        $this->assertEquals(0, $this->svc->daysRemaining($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 11 — daysRemaining() already expired
    //
    // Expired subscriptions must return 0 — never a negative number.
    // The UI must not display "-5 days remaining".
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function days_remaining_returns_zero_when_already_expired(): void
    {
        $company = $this->makeCompany('2025-06-14'); // yesterday
        $this->assertEquals(0, $this->svc->daysRemaining($company));
    }

    /** @test */
    public function days_remaining_never_returns_negative_even_when_long_expired(): void
    {
        $company = $this->makeCompany('2024-01-01'); // 17+ months ago
        $result  = $this->svc->daysRemaining($company);

        $this->assertGreaterThanOrEqual(0, $result,
            'daysRemaining must never return a negative number');
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 12 — expiresSoon() null company
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_returns_false_for_null_company(): void
    {
        $this->assertFalse($this->svc->expiresSoon(null));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 13 — expiresSoon() unrestricted
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_returns_false_when_no_ends_at_set(): void
    {
        $company = $this->makeCompany(null);
        $this->assertFalse($this->svc->expiresSoon($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 14 — expiresSoon() within default window (14 days)
    //
    // Default warning window is 14 days.
    // ends_at = today + 7 days → expiresSoon() must return true.
    // ends_at = today + 1 day  → expiresSoon() must return true.
    // ends_at = today + 14 days → boundary — must return true.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_returns_true_when_expires_in_7_days(): void
    {
        $company = $this->makeCompany('2025-06-22'); // 7 days away
        $this->assertTrue($this->svc->expiresSoon($company));
    }

    /** @test */
    public function expires_soon_returns_true_when_expires_tomorrow(): void
    {
        $company = $this->makeCompany('2025-06-16'); // 1 day away
        $this->assertTrue($this->svc->expiresSoon($company));
    }

    /** @test */
    public function expires_soon_returns_true_at_the_14_day_boundary(): void
    {
        $company = $this->makeCompany('2025-06-29'); // exactly 14 days away
        $this->assertTrue($this->svc->expiresSoon($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 15 — expiresSoon() outside default window
    //
    // ends_at = today + 15 days → just outside the 14-day window.
    // ends_at = today + 30 days → well outside.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_returns_false_when_expires_in_15_days(): void
    {
        $company = $this->makeCompany('2025-06-30'); // 15 days away
        $this->assertFalse($this->svc->expiresSoon($company));
    }

    /** @test */
    public function expires_soon_returns_false_when_expires_far_in_future(): void
    {
        $company = $this->makeCompany('2026-06-15'); // 1 year away
        $this->assertFalse($this->svc->expiresSoon($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 16 — expiresSoon() already expired
    //
    // Once expired, daysRemaining() returns 0.
    // expiresSoon() checks `$remaining > 0` — so 0 must return false.
    // An expired company should not see "expiring soon" — it should
    // see "subscription expired" via isExpired() instead.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_returns_false_when_already_expired(): void
    {
        $company = $this->makeCompany('2025-06-14'); // expired yesterday
        $this->assertFalse($this->svc->expiresSoon($company));
    }

    /** @test */
    public function expires_soon_returns_false_when_expired_today(): void
    {
        // ends_at = today → daysRemaining = 0 → expiresSoon = false
        // (Today is the last valid day, not "expiring soon")
        $company = $this->makeCompany(self::TODAY);
        $this->assertFalse($this->svc->expiresSoon($company));
    }

    // ══════════════════════════════════════════════════════════════
    // GROUP 17 — expiresSoon() custom window
    //
    // The second parameter overrides the 14-day default.
    // Allows callers to use a 30-day window for email reminders,
    // a 7-day window for dashboard banners, etc.
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function expires_soon_respects_custom_window_of_30_days(): void
    {
        $company = $this->makeCompany('2025-07-10'); // 25 days away
        // Within 30-day window → true
        $this->assertTrue($this->svc->expiresSoon($company, 30));
        // Outside 14-day default → false
        $this->assertFalse($this->svc->expiresSoon($company, 14));
    }

    /** @test */
    public function expires_soon_respects_custom_window_of_7_days(): void
    {
        $company = $this->makeCompany('2025-06-20'); // 5 days away
        // Within 7-day window → true
        $this->assertTrue($this->svc->expiresSoon($company, 7));
    }

    /** @test */
    public function expires_soon_boundary_is_inclusive_for_custom_window(): void
    {
        $company = $this->makeCompany('2025-06-22'); // exactly 7 days away
        $this->assertTrue($this->svc->expiresSoon($company, 7),
            'Boundary day must be included in the window (<=, not <)');
    }

    // ══════════════════════════════════════════════════════════════
    // SCAFFOLD HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Build a minimal Company stub with only the field this service
     * actually reads — subscription_ends_at.
     * No DB hit required; we use make() not create().
     */
    private function makeCompany(?string $endsAt): Company
    {
        $company = new Company();
        $company->subscription_ends_at = $endsAt;
        return $company;
    }
}
