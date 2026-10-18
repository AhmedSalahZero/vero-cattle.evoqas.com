<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default on every Inertia response.
     *
     * Authorization model:
     *
     *   We use Spatie Permission's "permission" layer as the single source
     *   of truth for what a user can do. Roles are kept only as display
     *   labels (Farm Manager, Accountant, …) and as a convenient way to
     *   bundle permissions in the seeder, but every check anywhere in the
     *   app — backend or frontend — is permission-based, not role-based.
     *
     *   The frontend uses the usePermissions() composable, which reads
     *   auth.user.permissions and exposes a can(name) helper. To make
     *   that helper authoritative, we MUST share every permission the
     *   user effectively has, including:
     *
     *     • permissions assigned directly to the user
     *       (model_has_permissions)
     *     • permissions inherited from roles assigned to the user
     *       (role_has_permissions ↔ model_has_roles)
     *
     *   Spatie's $user->getAllPermissions() returns exactly that union,
     *   so we plug it in here. Without this, a user holding the
     *   "Farm Manager" role would have an empty permissions array on
     *   the frontend (because the role's permissions live on the role,
     *   not directly on the user) and every can(...) call in Vue would
     *   return false → "every page I create disappears".
     *
     *   For super admins we short-circuit to an empty array because
     *   usePermissions().can() already returns true for them via the
     *   is_super_admin flag — and Gate::before() does the same on the
     *   backend (see AppServiceProvider). This keeps the prop payload
     *   small for super admins.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Eager-load roles + permissions in one shot so that:
        //   • $user->getAllPermissions() does not fire N queries
        //   • $user->roles->first()?->name still works for the role label
        if ($user) {
            $user->loadMissing(['roles', 'permissions']);
        }

        // Compute the effective permission name list for this user.
        // For super admins we skip the calculation — Gate::before grants
        // everything anyway and the frontend trusts is_super_admin.
        $permissions = [];
        if ($user && ! $user->is_super_admin) {
            $permissions = $user->getAllPermissions()
                ->pluck('name')
                ->values()
                ->all();
        }

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,

                    // Display label only — never used for access control.
                    'role'           => $user->roles->first()?->name,

                    'is_super_admin' => (bool) $user->is_super_admin,
                    'is_active'      => (bool) $user->is_active,
                    'company_id'     => $user->company_id,

                    // Required by AuthenticatedLayout for RTL/LTR.
                    'language'       => $user->language ?? 'en',

                    'theme'          => $user->theme ?? 'dark',

                    // Bilingual job title columns (no single job_title column).
                    'job_title_en'   => $user->job_title_en,
                    'job_title_ar'   => $user->job_title_ar,

                    // The single source of truth for frontend authorization.
                    // Consumed by usePermissions().can(name) in every Vue page.
                    'permissions'    => $permissions,
                ] : null,
            ],

           'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],

            'selected_company' => $request->session()->get('selected_company_id')
                ? \App\Models\Company::find(
                    $request->session()->get('selected_company_id'),
                    ['id', 'name_en', 'name_ar']
                )
                : null,
        ];
    }
}
