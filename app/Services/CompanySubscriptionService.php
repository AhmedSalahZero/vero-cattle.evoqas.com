<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — CompanySubscriptionService
//
//  Provides the isExpired() helper that AuthenticatedSessionController
//  calls immediately after authenticate() succeeds.
//
//  Logic:
//   • NULL ends_at  → subscription is unrestricted (never expires)
//   • ends_at < today → expired → block login
//   • ends_at >= today → valid → allow login
//
//  Usage in AuthenticatedSessionController::store():
//
//    public function store(LoginRequest $request, CompanySubscriptionService $sub): RedirectResponse
//    {
//        $request->authenticate();
//        $user = $request->user();
//
//        if ($user && !$user->is_super_admin) {
//            $company = Company::find($user->company_id);
//            if ($sub->isExpired($company)) {
//                Auth::guard('web')->logout();
//                $request->session()->invalidate();
//                $request->session()->regenerateToken();
//                throw ValidationException::withMessages([
//                    'email' => [__('auth.subscription_expired')],
//                ]);
//            }
//        }
//
//        $request->session()->regenerate();
//        return $user->is_super_admin
//            ? redirect()->intended(route('companies.index'))
//            : redirect()->intended(route('dashboard'));
//    }
// ══════════════════════════════════════════════════════════════════

class CompanySubscriptionService
{
    /**
     * Returns true when the company's subscription has expired.
     * Returns false if no expiry date is set (unrestricted).
     */
    public function isExpired(?Company $company): bool
    {
        if (! $company) {
            return false; // No company → don't block (super-admin scenario)
        }

        if (! $company->subscription_ends_at) {
            return false; // No end date set → unrestricted
        }

        // Expired when today is strictly after the end date
        return Carbon::today()->isAfter(
            Carbon::parse($company->subscription_ends_at)->endOfDay()
        );
    }

    /**
     * Returns the number of days remaining in the subscription.
     * Returns null if no expiry is set.
     * Returns 0 if already expired.
     */
    public function daysRemaining(?Company $company): ?int
    {
        if (! $company || ! $company->subscription_ends_at) {
            return null;
        }

        $remaining = Carbon::today()->diffInDays(
            Carbon::parse($company->subscription_ends_at),
            false // signed diff — negative = expired
        );

        return max(0, (int) $remaining);
    }

    /**
     * Returns true when the subscription expires within $days days.
     * Useful for showing "subscription expiring soon" warnings.
     */
    public function expiresSoon(?Company $company, int $days = 14): bool
    {
        $remaining = $this->daysRemaining($company);

        if ($remaining === null) {
            return false; // Unrestricted
        }

        return $remaining <= $days && $remaining > 0;
    }
}
