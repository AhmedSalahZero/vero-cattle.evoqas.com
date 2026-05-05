<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — User Controller
//
//  Session XX — Permission System Overhaul
//
//  CHANGE: Replaced fixed-role permission bundles with manual
//  per-user permission assignment.
//
//  How it works:
//    • Super Admin  → can create Company Admins + any user in any company
//    • Company Admin → can create/edit users within their own company
//    • When creating/editing a user: a permission matrix is shown
//      (all modules × 4 actions). The creator checks exactly which
//      permissions the new user gets.
//    • Role labels are KEPT on the user record for display/filtering
//      purposes — but access control is done via direct permissions
//      (model_has_permissions), NOT via role bundles.
//    • $user->syncPermissions($permissions) replaces $user->assignRole()
//      as the single source of truth for what a user can do.
//    • Templates (presets) are purely a frontend convenience: clicking
//      a template pre-fills the checkboxes. The actual stored value
//      is always the individual permission list.
//
//  Preserved from original:
//    FIX 1 — formatUser() name_en null crash
//    FIX 2 — company_name flat key
//    FIX 3 — Inertia render targets corrected
//    FIX 4 — quota prop
// ══════════════════════════════════════════════════════════════════

class UserController extends Controller
{
    // ── Module list — mirrors RolesAndPermissionsSeeder ───────────
    private array $modules = [
        'companies',
        'farms',
        'users',
        'animals',
        'lots',
        'health',
        'vaccinations',
        'breeding',
        'calvings',
        'feed',
        'rations',
        'weight',
        'procurement',
        'slaughter',
        'sales',
        'inventory',
        'accounts',
        'journal_entries',
        'ap_invoices',
        'ar_invoices',
        'payments',
        'bank_statements',
        'fiscal_years',
        'fx_rates',
        'budgets',
        'reports',
        'settings',
        'tasks',
        'customers',
        'suppliers',
        'fixed_assets',
    ];

    // ── Permission preset templates ───────────────────────────────
    // These are ONLY used by the frontend to pre-fill checkboxes.
    // They are never stored as role bundles.
    private function permissionTemplates(): array
    {
        return [
            'Farm Manager' => [
                'farms'           => ['view', 'create', 'edit', 'delete'],
                'animals'         => ['view', 'create', 'edit', 'delete'],
                'lots'            => ['view', 'create', 'edit', 'delete'],
                'health'          => ['view', 'create', 'edit', 'delete'],
                'vaccinations'    => ['view', 'create', 'edit', 'delete'],
                'breeding'        => ['view', 'create', 'edit', 'delete'],
                'calvings'        => ['view', 'create', 'edit', 'delete'],
                'feed'            => ['view', 'create', 'edit', 'delete'],
                'rations'         => ['view', 'create', 'edit', 'delete'],
                'weight'          => ['view', 'create', 'edit', 'delete'],
                'procurement'     => ['view', 'create', 'edit', 'delete'],
                'slaughter'       => ['view', 'create', 'edit', 'delete'],
                'sales'           => ['view', 'create', 'edit', 'delete'],
                'inventory'       => ['view', 'create', 'edit', 'delete'],
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'suppliers'       => ['view', 'create', 'edit', 'delete'],
                'fixed_assets'    => ['view', 'create', 'edit', 'delete'],
                'accounts'        => ['view', 'create', 'edit', 'delete'],
                'journal_entries' => ['view', 'create', 'edit', 'delete'],
                'ap_invoices'     => ['view', 'create', 'edit', 'delete'],
                'ar_invoices'     => ['view', 'create', 'edit', 'delete'],
                'payments'        => ['view', 'create', 'edit', 'delete'],
                'budgets'         => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view', 'create', 'edit', 'delete'],
                'tasks'           => ['view', 'create', 'edit', 'delete'],
            ],
            'Veterinarian' => [
                'animals'      => ['view'],
                'lots'         => ['view'],
                'health'       => ['view', 'create', 'edit', 'delete'],
                'vaccinations' => ['view', 'create', 'edit', 'delete'],
                'breeding'     => ['view'],
                'calvings'     => ['view'],
                'inventory'    => ['view'],
                'reports'      => ['view'],
                'tasks'        => ['view', 'create', 'edit', 'delete'],
            ],
            'Feed Manager' => [
                'animals'   => ['view'],
                'lots'      => ['view'],
                'feed'      => ['view', 'create', 'edit', 'delete'],
                'rations'   => ['view', 'create', 'edit', 'delete'],
                'inventory' => ['view', 'create', 'edit', 'delete'],
                'weight'    => ['view'],
                'reports'   => ['view'],
                'tasks'     => ['view', 'create', 'edit', 'delete'],
            ],
            'Accountant' => [
                'accounts'        => ['view', 'create', 'edit', 'delete'],
                'journal_entries' => ['view', 'create', 'edit', 'delete'],
                'ap_invoices'     => ['view', 'create', 'edit', 'delete'],
                'ar_invoices'     => ['view', 'create', 'edit', 'delete'],
                'payments'        => ['view', 'create', 'edit', 'delete'],
                'bank_statements' => ['view', 'create', 'edit', 'delete'],
                'fiscal_years'    => ['view', 'create', 'edit', 'delete'],
                'budgets'         => ['view', 'create', 'edit', 'delete'],
                'reports'         => ['view'],
                'tasks'           => ['view', 'create', 'edit', 'delete'],
                'animals'         => ['view'],
                'lots'            => ['view'],
                'procurement'     => ['view'],
                'sales'           => ['view'],
                'slaughter'       => ['view'],
                'inventory'       => ['view'],
                'customers'       => ['view', 'create', 'edit', 'delete'],
                'suppliers'       => ['view', 'create', 'edit', 'delete'],
                'fixed_assets'    => ['view', 'create', 'edit', 'delete'],
                'fx_rates'        => ['view', 'create', 'edit', 'delete'],
            ],
            'Data Entry' => [
                'animals'      => ['view', 'create', 'edit'],
                'lots'         => ['view'],
                'health'       => ['view', 'create', 'edit'],
                'vaccinations' => ['view', 'create', 'edit'],
                'breeding'     => ['view', 'create', 'edit'],
                'calvings'     => ['view', 'create', 'edit'],
                'feed'         => ['view', 'create', 'edit'],
                'weight'       => ['view', 'create', 'edit'],
                'procurement'  => ['view', 'create', 'edit'],
                'slaughter'    => ['view', 'create', 'edit'],
                'sales'        => ['view', 'create', 'edit'],
                'inventory'    => ['view', 'create', 'edit'],
                'customers'    => ['view', 'create', 'edit'],
                'suppliers'    => ['view', 'create', 'edit'],
                'fixed_assets' => ['view', 'create', 'edit'],
                'fx_rates'     => ['view', 'create', 'edit', 'delete'],
                'tasks'        => ['view', 'create', 'edit', 'delete'],
            ],
        ];
    }

    // ── Expand template map → flat permission name array ──────────
    private function expandTemplate(array $map): array
    {
        $permissions = [];
        foreach ($map as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$action} {$module}";
            }
        }
        return $permissions;
    }

    // ── Build the permission matrix prop for Vue ──────────────────
    // Returns: [ ['module' => 'animals', 'view' => true, 'create' => false, ...], ... ]
    private function buildPermissionMatrix(User $user = null): array
    {
        $userPerms = $user
            ? $user->getAllPermissions()->pluck('name')->flip()
            : collect();

        return array_map(function ($module) use ($userPerms) {
            return [
                'module' => $module,
                'view'   => $userPerms->has("view {$module}"),
                'create' => $userPerms->has("create {$module}"),
                'edit'   => $userPerms->has("edit {$module}"),
                'delete' => $userPerms->has("delete {$module}"),
            ];
        }, $this->modules);
    }

    // ── Build templates for Vue (flat permission name arrays) ─────
    private function buildTemplatesForVue(): array
    {
        $result = [];
        foreach ($this->permissionTemplates() as $name => $map) {
            $result[$name] = $this->expandTemplate($map);
        }
        return $result;
    }

    // ── Index — list users ────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('view users'),
            403
        );

        if ($authUser->is_super_admin) {
            $users = User::with(['company:id,name_en,name_ar', 'roles', 'farms:id,name_en,name_ar'])
                ->where('is_super_admin', false)
                ->orderBy('company_id')
                ->orderBy('name')
                ->get();
        } else {
            $users = User::with(['company:id,name_en,name_ar', 'roles', 'farms:id,name_en,name_ar'])
                ->where('company_id', $authUser->company_id)
                ->where('is_super_admin', false)
                ->orderBy('name')
                ->get();
        }

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        $quota = $this->buildQuota($authUser);

        return Inertia::render('Users/Index', [
            'users'       => $users->map(fn($u) => $this->formatUser($u)),
            'companies'   => $companies,
            'myCompanyId' => $authUser->company_id,
            'quota'       => $quota,
            'filters'     => $request->only(['search', 'role', 'status', 'company_id']),
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('create users'),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        $farms = $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'company_id', 'name_en', 'name_ar']);

        $quota = $this->buildQuota($authUser);

        return Inertia::render('Users/CreateEdit', [
            'user'            => null,
            'mode'            => 'create',
            'companies'       => $companies,
            'farms'           => $farms,
            'myCompanyId'     => $authUser->company_id,
            'quota'           => $quota,
            'permissionMatrix'=> $this->buildPermissionMatrix(),
            'templates'       => $this->buildTemplatesForVue(),
            'allModules'      => $this->modules,
        ]);
    }

    // ── Store new user ────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('create users'),
            403
        );

        $validated = $this->validateUser($request);

        // Company admin can only add to their own company
        if (!$authUser->is_super_admin) {
            abort_unless(
                (int) $validated['company_id'] === $authUser->company_id,
                403
            );

            // Quota enforcement
            $company = Company::find($authUser->company_id);
            if ($company && $company->max_users) {
                $currentCount = User::where('company_id', $authUser->company_id)
                                    ->where('is_super_admin', false)
                                    ->count();
                if ($currentCount >= $company->max_users) {
                    return back()->withErrors([
                        'quota' => __('messages.user_quota_reached'),
                    ]);
                }
            }
        }

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars/users', 'public');
        }

        $user = User::create([
            'company_id'    => $validated['company_id'],
            'name'          => $validated['name_en'],
            'name_en'       => $validated['name_en'],
            'name_ar'       => $validated['name_ar']    ?? null,
            'email'         => $validated['email'],
            'password'      => Hash::make($validated['password']),
            'phone'         => $validated['phone']        ?? null,
            'job_title_en'  => $validated['job_title_en'] ?? null,
            'job_title_ar'  => $validated['job_title_ar'] ?? null,
            'language'      => $validated['language']     ?? 'en',
            'theme'         => $validated['theme']        ?? 'dark',
            'is_active'     => $validated['is_active']    ?? true,
            'avatar'        => $avatarPath,
        ]);

        // ── Assign display role label (for cards/filtering) ───────
        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        // ── Assign individual permissions (the real access control) ─
        $permissions = $this->resolvePermissionsFromRequest($request);
        $user->syncPermissions($permissions);

        // Assign farms
        if (!empty($validated['farm_ids'])) {
            $farmData = [];
            foreach ($validated['farm_ids'] as $index => $farmId) {
                $farmData[$farmId] = ['is_primary' => $index === 0];
            }
            $user->farms()->sync($farmData);
        }

        return redirect()->route('users.index')
            ->with('success', __('messages.user_created'));
    }

    // ── Edit form ─────────────────────────────────────────────────
    public function edit(Request $request, User $user)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('edit users'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless($user->company_id === $authUser->company_id, 403);
        }

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        $farms = $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'company_id', 'name_en', 'name_ar']);

        return Inertia::render('Users/CreateEdit', [
            'user'            => $this->formatUser($user->load(['farms', 'roles'])),
            'mode'            => 'edit',
            'companies'       => $companies,
            'farms'           => $farms,
            'myCompanyId'     => $authUser->company_id,
            'quota'           => null,
            'permissionMatrix'=> $this->buildPermissionMatrix($user),
            'templates'       => $this->buildTemplatesForVue(),
            'allModules'      => $this->modules,
        ]);
    }

    // ── Update existing user ──────────────────────────────────────
    public function update(Request $request, User $user)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('edit users'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless($user->company_id === $authUser->company_id, 403);
        }

        $validated = $this->validateUser($request, $user->id);

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars/users', 'public');
        }

        $user->fill([
            'name'          => $validated['name_en'],
            'name_en'       => $validated['name_en'],
            'name_ar'       => $validated['name_ar']    ?? null,
            'email'         => $validated['email'],
            'phone'         => $validated['phone']        ?? null,
            'job_title_en'  => $validated['job_title_en'] ?? null,
            'job_title_ar'  => $validated['job_title_ar'] ?? null,
            'language'      => $validated['language']     ?? 'en',
            'theme'         => $validated['theme']        ?? 'dark',
            'is_active'     => $validated['is_active']    ?? true,
        ]);

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        // ── Update display role label ─────────────────────────────
        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        // ── Update individual permissions ─────────────────────────
        $permissions = $this->resolvePermissionsFromRequest($request);
        $user->syncPermissions($permissions);

        // Update farms
        $farmData = [];
        foreach ($validated['farm_ids'] ?? [] as $index => $farmId) {
            $farmData[$farmId] = ['is_primary' => $index === 0];
        }
        $user->farms()->sync($farmData);

        return redirect()->route('users.index')
            ->with('success', __('messages.user_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(User $user)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('delete users'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless($user->company_id === $authUser->company_id, 403);
        }

        abort_if($user->id === $authUser->id, 403, 'Cannot delete your own account.');

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', __('messages.user_deleted'));
    }

    // ── Toggle active ─────────────────────────────────────────────
    public function toggleActive(User $user)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasRole('Company Admin') ||
            $authUser->hasPermissionTo('edit users'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless($user->company_id === $authUser->company_id, 403);
        }

        abort_if($user->id === $authUser->id, 403, 'You cannot deactivate your own account.');

        $user->update(['is_active' => !$user->is_active]);

        return back()->with(
            'success',
            $user->fresh()->is_active
                ? __('messages.user_activated')
                : __('messages.user_deactivated')
        );
    }

    // ── Private: resolve permissions array from request ───────────
    // Accepts: permissions[] — array of "action module" strings
    // e.g. ["view animals", "create animals", "view farms"]
    // Guards: strips permissions that don't exist in DB to prevent injection
    private function resolvePermissionsFromRequest(Request $request): array
    {
        $submitted = $request->input('permissions', []);

        if (empty($submitted) || !is_array($submitted)) {
            return [];
        }

        // Only allow permissions that actually exist in DB
        $valid = Permission::whereIn('name', $submitted)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->toArray();

        return $valid;
    }

    // ── Private: validation ───────────────────────────────────────
    private function validateUser(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'company_id'    => 'required|exists:companies,id',
            'name_en'       => 'required|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'email'         => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($ignoreId),
            ],
            'password'      => $ignoreId
                ? 'nullable|string|min:8|confirmed'
                : 'required|string|min:8|confirmed',
            'role'          => [
                'nullable',
                Rule::in([
                    'Company Admin',
                    'Farm Manager',
                    'Veterinarian',
                    'Feed Manager',
                    'Data Entry',
                    'Accountant',
                ]),
            ],
            'farm_ids'      => 'nullable|array',
            'farm_ids.*'    => 'exists:farms,id',
            'phone'         => 'nullable|string|max:50',
            'job_title_en'  => 'nullable|string|max:255',
            'job_title_ar'  => 'nullable|string|max:255',
            'language'      => ['nullable', Rule::in(['en', 'ar'])],
            'theme'         => ['nullable', Rule::in(['light', 'dark'])],
            'is_active'     => 'boolean',
            'avatar'        => 'nullable|image|max:2048',
            // permissions[] is handled separately via resolvePermissionsFromRequest()
        ]);
    }

    // ── Private: quota builder ────────────────────────────────────
    private function buildQuota(User $authUser): ?array
    {
        if ($authUser->is_super_admin) return null;

        $company = Company::find($authUser->company_id);
        if (!$company || !$company->max_users) return null;

        $used = User::where('company_id', $authUser->company_id)
                    ->where('is_super_admin', false)
                    ->count();
        return [
            'used'      => $used,
            'max'       => $company->max_users,
            'remaining' => max(0, $company->max_users - $used),
        ];
    }

    // ── Private: format user for frontend ────────────────────────
    private function formatUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name    ?? $user->name_en ?? '',
            'name_en'        => $user->name_en ?? $user->name    ?? '',
            'name_ar'        => $user->name_ar,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'job_title_en'   => $user->job_title_en,
            'job_title_ar'   => $user->job_title_ar,
            'language'       => $user->language,
            'theme'          => $user->theme,
            'is_active'      => (bool) $user->is_active,
            'is_super_admin' => (bool) $user->is_super_admin,
            'avatar'         => $user->avatar,
            'last_login_at'  => $user->last_login_at?->diffForHumans(),
            'company_id'     => $user->company_id,
            'company_name'   => $user->company?->name_en ?? '',
            'company'        => $user->company
                ? ['id' => $user->company->id, 'name_en' => $user->company->name_en, 'name_ar' => $user->company->name_ar]
                : null,
            'role'           => $user->roles->first()?->name ?? '',
            'farm_ids'       => $user->farms?->pluck('id')->toArray() ?? [],
            // Permission count summary for card display
            'permission_count' => $user->getAllPermissions()->count(),
        ];
    }
}