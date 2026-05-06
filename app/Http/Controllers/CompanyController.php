<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Company Controller
//
//  Changes in this version:
//    • validateCompany() → max_users field added (nullable integer)
//    • index()           → passes users_count so the quota bar works
//    • formatCompany()   → new helper used by index + show
//
//  max_users business rules (enforced here AND in UserController):
//    null  → unlimited — no cap
//    1–999 → hard ceiling that super admin set for this company
//    The company admin (Farm Owner) cannot change this value.
//    Only is_super_admin === true may write to max_users.
// ══════════════════════════════════════════════════════════════════

class CompanyController extends Controller
{
    // ── Index — list all companies (super admin only) ─────────────
    public function index(Request $request)
    {
        abort_unless($request->user()->is_super_admin, 403);

        $companies = Company::withCount(['farms', 'users'])
            ->orderBy('name_en')
            ->get();

        return Inertia::render('Companies/Index', [
            // Pass formatted array so Vue never needs raw Eloquent internals
            'companies' => $companies->map(fn ($c) => $this->formatCompany($c)),
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create(Request $request)
    {
        abort_unless($request->user()->is_super_admin, 403);

        return Inertia::render('Companies/CreateEdit', [
            'company' => null,
            'mode'    => 'create',
        ]);
    }

    // ── Store new company ─────────────────────────────────────────
    public function store(Request $request)
    {
        abort_unless($request->user()->is_super_admin, 403);

        $validated = $this->validateCompany($request);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')
                ->store('logos/companies', 'public');
        }

        $company = Company::create($validated);

        // Seed the standard chart of accounts for this company
        (new ChartOfAccountsSeeder)->seedForCompany($company->id);

        return redirect()->route('companies.index')
            ->with('success', __('messages.company_created'));
    }

    // ── Show detail ───────────────────────────────────────────────
    public function show(Request $request, Company $company)
    {
        $authUser = $request->user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->company_id === $company->id,
            403
        );

        $company->loadCount(['farms', 'users']);

        return Inertia::render('Companies/Show', [
            'company' => $this->formatCompany($company),
        ]);
    }

    // ── Edit form ─────────────────────────────────────────────────
    public function edit(Request $request, Company $company)
    {
        $authUser = $request->user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->company_id === $company->id,
            403
        );

        // Load counts so the edit form can show quota status
        $company->loadCount('users');

        return Inertia::render('Companies/CreateEdit', [
            'company' => $this->formatCompany($company),
            'mode'    => 'edit',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Company $company)
    {
        $authUser = $request->user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->company_id === $company->id,
            403
        );

        $validated = $this->validateCompany($request, $company->id);

        // ── max_users is super-admin-only — strip it for non-admins ──
        if (! $authUser->is_super_admin) {
            unset($validated['max_users']);
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo if it exists
            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }
            $validated['logo'] = $request->file('logo')
                ->store('logos/companies', 'public');
        }

        $company->update($validated);

        return redirect()->route('companies.index')
            ->with('success', __('messages.company_updated'));
    }

    // ── Delete ────────────────────────────────────────────────────
    public function destroy(Request $request, Company $company)
    {
        abort_unless($request->user()->is_super_admin, 403);

        // Prevent deletion if company still has users assigned
        // (farms and all other data cascade-delete automatically)
        if ($company->users()->exists()) {
            return back()->withErrors([
                'company' => __('messages.company_has_users'),
            ]);
        }

        if ($company->logo) {
            Storage::disk('public')->delete($company->logo);
        }

        // forceDelete() permanently removes the row from the DB,
        // which triggers the ON DELETE CASCADE on all child tables.
        // Using soft delete ($company->delete()) would only set deleted_at
        // and the cascade would never fire.
        $company->forceDelete();

        return redirect()->route('companies.index')
            ->with('success', __('messages.company_deleted'));
    }

    // ── Toggle active status ──────────────────────────────────────
    public function toggleActive(Request $request, Company $company)
    {
        abort_unless($request->user()->is_super_admin, 403);

        $company->update(['is_active' => ! $company->is_active]);

        return back()->with(
            'success',
            $company->fresh()->is_active
                ? __('messages.company_activated')
                : __('messages.company_deactivated')
        );
    }

    // ── Update accounting mode ────────────────────────────────────
    public function updateAccountingMode(Request $request, Company $company)
    {
        $authUser = $request->user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->company_id === $company->id,
            403
        );

        $request->validate([
            'accounting_mode' => ['required', Rule::in(['standalone', 'odoo'])],
        ]);

        $company->update(['accounting_mode' => $request->accounting_mode]);

        return back()->with('success', __('messages.accounting_mode_updated'));
    }

    // ── Private: format a company for the frontend ────────────────
    //  Always use this so Vue pages receive a consistent shape.
    private function formatCompany(Company $company): array
    {
        return [
            'id'                      => $company->id,
            'name_en'                 => $company->name_en,
            'name_ar'                 => $company->name_ar,
            'logo'                    => $company->logo,
            'email'                   => $company->email,
            'phone'                   => $company->phone,
            'website'                 => $company->website,
            'address_en'              => $company->address_en,
            'address_ar'              => $company->address_ar,
            'country'                 => $company->country,
            'tax_number'              => $company->tax_number,
            'commercial_register'     => $company->commercial_register,
            'currency'                => $company->currency,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'accounting_mode'         => $company->accounting_mode,
            'is_active'               => (bool) $company->is_active,

            // ── Subscription fields ───────────────────────────────
            'subscription_starts_at'  => $company->subscription_starts_at?->format('Y-m-d'),
            'subscription_ends_at'    => $company->subscription_ends_at?->format('Y-m-d'),

            // ── User quota (NEW) ──────────────────────────────────
            // null = unlimited; integer = cap set by super admin
            'max_users'               => $company->max_users,
            // Loaded by withCount('users') or loadCount('users')
            'users_count'             => $company->users_count ?? 0,

            // ── Counts loaded via withCount ───────────────────────
            'farms_count'             => $company->farms_count ?? 0,

            'created_at'              => $company->created_at?->format('d M Y'),
        ];
    }

    // ── Private: shared validation ────────────────────────────────
    private function validateCompany(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name_en'                  => 'required|string|max:255',
            'name_ar'                  => 'required|string|max:255',
            'logo'                     => 'nullable|image|max:2048',
            'address_en'               => 'nullable|string|max:500',
            'address_ar'               => 'nullable|string|max:500',
            'country'                  => 'nullable|string|max:100',
            'phone'                    => 'nullable|string|max:50',
            'email'                    => 'nullable|email|max:255',
            'website'                  => 'nullable|url|max:255',
            'tax_number'               => 'nullable|string|max:100',
            'commercial_register'      => 'nullable|string|max:100',
            'currency'                 => 'nullable|string|size:3',
            'fiscal_year_start_month'  => 'nullable|integer|min:1|max:12',
            'accounting_mode'          => ['nullable', Rule::in(['standalone', 'odoo'])],
            'is_active'                => 'boolean',

            // ── Subscription dates ────────────────────────────────
            'subscription_starts_at'   => 'nullable|date',
            'subscription_ends_at'     => [
                'nullable',
                'date',
                'after_or_equal:subscription_starts_at',
            ],

            // ── User quota (NEW) ──────────────────────────────────
            // null  = unlimited (no cap)
            // 1–999 = maximum users this company admin may create
            'max_users' => 'nullable|integer|min:1|max:999',
        ]);
    }
}