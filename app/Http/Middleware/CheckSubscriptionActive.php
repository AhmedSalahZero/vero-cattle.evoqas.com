<?php

namespace App\Http\Middleware;

use App\Services\CompanySubscriptionService;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckSubscriptionActive
{
    public function __construct(private CompanySubscriptionService $sub) {}

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        // Super admins are never blocked
        if (!$user || $user->is_super_admin) {
            return $next($request);
        }

        // A deactivated user, or a deactivated / missing company, is shut out at once —
        // even with a session that was opened before the switch-off.
        $company = Company::find($user->company_id);

        if ($user->is_active === false || ($company && !$company->is_active)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'هذا الحساب غير مفعّل / This account is deactivated.']);
        }

        if ($this->sub->isExpired($company)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => __('auth.subscription_expired')]);
        }

        return $next($request);
    }
}