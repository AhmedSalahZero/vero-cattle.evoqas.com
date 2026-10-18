<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\Animal;
use App\Models\Lot;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Farm Controller
//
//  FIX (this revision):
//    create() — was rendering 'Farms/Create'  → now 'Farms/CreateEdit' + mode:'create'
//    edit()   — was rendering 'Farms/Edit'    → now 'Farms/CreateEdit' + mode:'edit'
//    Both pages are served by the single Farms/CreateEdit.vue file
//    (mirrors the UserController pattern).
// ══════════════════════════════════════════════════════════════════

class FarmController extends Controller
{
    // ── Index — list farms ────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

         abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view farms'),
            403
        );

        if ($authUser->is_super_admin) {
            $farms = Farm::with('company:id,name_en,name_ar')
                ->withCount('users')
                ->orderBy('company_id')
                ->orderBy('name_en')
                ->get();
        } else {
            $farms = Farm::with('company:id,name_en,name_ar')
                ->withCount('users')
                ->where('company_id', $authUser->company_id)
                ->orderBy('name_en')
                ->get();
        }

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        return Inertia::render('Farms/Index', [
            'farms'       => $farms->map(fn($f) => $this->formatFarm($f)),
            'companies'   => $companies,
            'myCompanyId' => $authUser->company_id,
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

         abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create farms'),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        // FIX: was 'Farms/Create' — now 'Farms/CreateEdit' with mode: 'create'
        return Inertia::render('Farms/CreateEdit', [
            'farm'        => null,
            'mode'        => 'create',
            'companies'   => $companies,
            'myCompanyId' => $authUser->company_id,
        ]);
    }

    // ── Store new farm ────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create farms'),
            403
        );

        // Sanitize '' -> null before validation (see sanitizeNullableFields)
        $this->sanitizeNullableFields($request);

        $validated = $this->validateFarm($request);

        // Farm Owner can only create farms under their own company
        if (!$authUser->is_super_admin) {
            abort_unless(
                (int) $validated['company_id'] === $authUser->company_id,
                403
            );
        }

        Farm::create($validated);

        return redirect()->route('farms.index')
            ->with('success', __('messages.farm_created'));
    }

    // ── Show single farm ──────────────────────────────────────────
    public function show(Request $request, Farm $farm)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view farms'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
        }

        $farm->load([
            'company:id,name_en,name_ar',
            'users:id,name,name_en,name_ar,email,job_title_en,job_title_ar',
        ]);

        return Inertia::render('Farms/Show', [
            'farm'     => $this->formatFarm($farm),
        ]);
    }

    // ── Edit form ─────────────────────────────────────────────────
    public function edit(Request $request, Farm $farm)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit farms'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
        }

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        // FIX: was 'Farms/Edit' — now 'Farms/CreateEdit' with mode: 'edit'
        return Inertia::render('Farms/CreateEdit', [
            'farm'        => $this->formatFarm($farm),
            'mode'        => 'edit',
            'companies'   => $companies,
            'myCompanyId' => $authUser->company_id,
        ]);
    }

    // ── Update farm ───────────────────────────────────────────────
    public function update(Request $request, Farm $farm)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit farms'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
        }

        $this->sanitizeNullableFields($request);
        $validated = $this->validateFarm($request, $farm->id);

        $farm->update($validated);

        return redirect()->route('farms.index')
            ->with('success', __('messages.farm_updated'));
    }

    // ── Delete farm ───────────────────────────────────────────────
    public function destroy(Request $request, Farm $farm)
    {
        $authUser = Auth::user();

       abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete farms'),
            403
        );


        if (!$authUser->is_super_admin) {
            abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
        }

        // Prevent deletion if farm has animals or lots
        $hasAnimals = $farm->animals()->exists();
        $hasLots    = $farm->lots()->exists();

        if ($hasAnimals || $hasLots) {
            return back()->withErrors([
                'farm' => __('messages.farm_has_records'),
            ]);
        }

        // Detach all users from this farm before deleting
        $farm->users()->detach();

        $farm->delete();

        return redirect()->route('farms.index')
            ->with('success', __('messages.farm_deleted'));
    }

    // ── Toggle active status ──────────────────────────────────────
    public function toggleActive(Request $request, Farm $farm)
    {
        $authUser = Auth::user();

       abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit farms'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
        }

        $farm->update(['is_active' => !$farm->is_active]);

        return back()->with(
            'success',
            $farm->fresh()->is_active
                ? __('messages.farm_activated')
                : __('messages.farm_deactivated')
        );
    }

    // ── Private: sanitize empty strings to null before validation ──
    //
    // Inertia/Vue sends '' for every unfilled input. Laravel nullable
    // requires actual null, not ''. Without this, numeric fields (latitude,
    // area_acres etc.) silently fail validation and the form re-renders
    // with no visible error and no redirect.
    //
    // Bilingual: the hidden counterpart (name_ar in EN mode, name_en in AR
    // mode) always arrives as '' — sanitizing to null lets nullable pass.
 
    // ── Private: validation ───────────────────────────────────────
    //
    // Bilingual rule:
    //   EN mode: name_en filled, name_ar arrives as null  -> OK
    //   AR mode: name_ar filled, name_en arrives as null  -> OK
    //   Guard: at least one name must exist (cross-field check below).
    //
    // sanitizeNullableFields() MUST be called before this method.
    private function validateFarm(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'name_en'        => 'nullable|string|max:255',
            'name_ar'        => 'nullable|string|max:255',
            'address_en'     => 'nullable|string|max:255',
            'address_ar'     => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:255',
            'region'         => 'nullable|string|max:255',
            'country'        => 'nullable|string|max:2',
            'latitude'       => 'nullable|numeric|between:-90,90',
            'longitude'      => 'nullable|numeric|between:-180,180',
            'phone'          => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:255',
            'area_acres'     => 'nullable|numeric|min:0',
            'capacity_head'  => 'nullable|integer|min:0',
            'notes_en'       => 'nullable|string',
            'notes_ar'       => 'nullable|string',
            'is_active'      => 'sometimes|boolean',
        ]);

        // Cross-field guard: farm must have at least one name
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'farm name']),
            ]);
        }

        return $validated;
    }

    // ── Private: format farm for frontend ─────────────────────────
    private function formatFarm(Farm $farm): array
    {
        return [
            'id'             => $farm->id,
            'company_id'     => $farm->company_id,
            'company'        => $farm->company
                ? [
                    'id'      => $farm->company->id,
                    'name_en' => $farm->company->name_en,
                    'name_ar' => $farm->company->name_ar,
                ]
                : null,
            'name_en'        => $farm->name_en,
            'name_ar'        => $farm->name_ar,
            'address_en'     => $farm->address_en,
            'address_ar'     => $farm->address_ar,
            'city'           => $farm->city,
            'region'         => $farm->region,
            'country'        => $farm->country,
            'latitude'       => $farm->latitude,
            'longitude'      => $farm->longitude,
            'phone'          => $farm->phone,
            'email'          => $farm->email,
            'area_acres'     => $farm->area_acres,
            'capacity_head'  => $farm->capacity_head,
            'notes_en'       => $farm->notes_en,
            'notes_ar'       => $farm->notes_ar,
            'is_active'      => $farm->is_active,
            'users_count'    => $farm->users_count ?? 0,
            'users'          => $farm->relationLoaded('users')
                ? $farm->users->map(fn($u) => [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'name_en'      => $u->name_en,
                    'name_ar'      => $u->name_ar,
                    'email'        => $u->email,
                    'job_title_en' => $u->job_title_en,
                    'job_title_ar' => $u->job_title_ar,
                    'is_primary'   => $u->pivot->is_primary ?? false,
                ])
                : [],
            'created_at'     => $farm->created_at?->format('d M Y'),
            'updated_at'     => $farm->updated_at?->format('d M Y'),
        ];
    }
}