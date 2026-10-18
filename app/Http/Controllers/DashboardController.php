<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Dashboard Controller (Phase 4)
//
//  BUG FIX (2026-04):
//    The original index() had:
//      abort_unless($user->is_super_admin || $user->hasPermissionTo('view reports'), 403);
//
//    This caused a 403 for every user whose role does NOT have the
//    'view reports' permission (e.g. Data Entry).  The dashboard is
//    the landing page for ALL authenticated users — it must never be
//    gated behind a specific module permission.
//
//    Fix applied:
//    • Removed the abort_unless from index() entirely.
//    • Dashboard shows role-appropriate data; DashboardService is
//      responsible for scoping what each role can see.
//    • The JSON refresh endpoints (kpis, chartData) keep the guard
//      because they are only called by components that are already
//      conditionally rendered after checking the permission in Vue.
//
//  Routes:
//    GET  /dashboard            → main dashboard (Inertia page) — ALL roles
//    GET  /dashboard/kpis       → JSON live refresh — 'view reports' only
//    GET  /dashboard/chart-data → JSON chart updates — 'view reports' only
// ══════════════════════════════════════════════════════════════════

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboard,
    ) {}

    // ── Main Dashboard Page ───────────────────────────────────────
    // Accessible by ALL authenticated users.
    // Role-appropriate data scoping happens inside DashboardService.
    public function index(Request $request)
    {
        $user      = Auth::user();
        $farmId    = $request->filled('farm_id') ? (int) $request->farm_id : null;

        // A Super Admin has no company of their own: until one is entered from the
        // Companies page there is nothing to show, so send them there instead of an error page.
        if ($user->is_super_admin && (int) $request->session()->get('selected_company_id', 0) <= 0) {
            return redirect()->route('companies.index')
                ->with('warning', 'Please enter a company first (click Enter on a company card).');
        }

        $companyId = $this->resolveCompany($request);

        // Full KPI payload — served as initial Inertia props
        // DashboardService scopes data by company + optional farm
        $payload = $this->dashboard->getData($companyId, $farmId);

        // Available farms for filter dropdown
        $farms = DB::table('farms')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->select('id', 'name_en', 'name_ar')
            ->get();

        // Pass the user's role so Vue can conditionally render
        // financial widgets only for users with 'view reports'.
        $role = $user->is_super_admin
            ? 'super_admin'
            : $user->roles->first()?->name;

        $canViewReports = $user->is_super_admin
            || $user->hasPermissionTo('view reports');

        return Inertia::render('Dashboard/Index', [
            'dashboard'        => $payload,
            'farms'            => $farms,
            'active_farm'      => $farmId,
            'auth_role'        => $role,
            // Vue uses this flag to show/hide the financial KPI section
            'can_view_reports' => $canViewReports,
        ]);
    }

    // ── Live KPI Refresh (JSON) ───────────────────────────────────
    // Only called from Vue components that are already hidden for
    // roles without 'view reports', so the guard here is correct.
    // GET /dashboard/kpis?farm_id=
    public function kpis(Request $request)
    {
        $user = Auth::user();
        abort_unless(
            $user->is_super_admin || $user->hasPermissionTo('view reports'),
            403,
            __('auth.forbidden')
        );

        $companyId = $this->resolveCompany($request);
        $farmId    = $request->filled('farm_id') ? (int) $request->farm_id : null;

        $payload = $this->dashboard->getData($companyId, $farmId);

        return response()->json([
            'operational'  => $payload['operational'],
            'financial'    => $payload['financial'],
            'alerts'       => $payload['alerts'],
            'generated_at' => $payload['generated_at'],
        ]);
    }

    // ── Chart Data (JSON) ─────────────────────────────────────────
    // Same as kpis — only rendered for 'view reports' users.
    // GET /dashboard/chart-data?farm_id=
    public function chartData(Request $request)
    {
        $user = Auth::user();
        abort_unless(
            $user->is_super_admin || $user->hasPermissionTo('view reports'),
            403,
            __('auth.forbidden')
        );

        $companyId = $this->resolveCompany($request);
        $farmId    = $request->filled('farm_id') ? (int) $request->farm_id : null;

        $payload = $this->dashboard->getData($companyId, $farmId);

        return response()->json([
            'charts'       => $payload['charts'],
            'generated_at' => $payload['generated_at'],
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    private function resolveCompany(Request $request): int
    {
        $user = Auth::user();

        if ($user->is_super_admin) {
            // §6.5 — session-based company resolution for Super Admin
            $id = (int) $request->session()->get('selected_company_id', 0);
            abort_unless($id > 0, 422, 'Please enter a company first from the Companies page.');
            return $id;
        }

        return (int) $user->company_id;
    }
}