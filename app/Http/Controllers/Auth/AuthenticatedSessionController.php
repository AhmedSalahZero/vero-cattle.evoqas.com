<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Company;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private CompanySubscriptionService $sub,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status'           => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * FIX: CompanySubscriptionService was defined and had usage docs
     * but was never injected or called here. Added subscription check
     * between authenticate() and session regeneration so expired-
     * company users are rejected cleanly with a translated message
     * rather than landing on the dashboard and getting a different error.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        // ── Subscription guard (non-super-admin only) ─────────────
        // Check BEFORE session regenerate so we can safely log out
        // without leaving an orphaned session behind.
        if ($user && ! $user->is_super_admin) {
            $company = Company::find($user->company_id);

            if ($user->is_active === false || ($company && !$company->is_active)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => ['هذا الحساب غير مفعّل / This account is deactivated.'],
                ]);
            }

            if ($this->sub->isExpired($company)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => [__('auth.subscription_expired')],
                ]);
            }
        }

        $request->session()->regenerate();

        // Super-admin lands on company list; everyone else on dashboard
        if ($user->is_super_admin) {
            return redirect()->intended(route('companies.index'));
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}