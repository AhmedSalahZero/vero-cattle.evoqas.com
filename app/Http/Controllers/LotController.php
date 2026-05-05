<?php

namespace App\Http\Controllers;

use App\Models\Lot;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Lot Controller
//  Lots are the core cost-tracking unit. Every animal belongs to a
//  lot. All feed, health, and financial costs are aggregated at the
//  lot level for profitability analysis.
// ══════════════════════════════════════════════════════════════════

class LotController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view lots'),
            403
        );

        $query = Lot::with('farm:id,name_en,name_ar,company_id')
            ->withCount('animals');

        if (!$authUser->is_super_admin) {
            $query->whereHas('farm', fn($q) =>
                $q->where('company_id', $authUser->company_id)
            );
        }

        if ($request->filled('farm_id')) {
            $query->where('farm_id', $request->farm_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $lots = $query->orderBy('start_date', 'desc')->get();

        $farms = $this->availableFarms($authUser);

        return Inertia::render('Lots/Index', [
            'lots'     => $lots->map(fn($l) => $this->formatLot($l)),
            'farms'    => $farms,
            'filters'  => $request->only(['farm_id', 'status']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    // Points to Lots/CreateEdit.vue — single file handles both modes.
    // The Vue page detects create mode via: isEdit = !!props.lot (false here).
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create lots'),
            403
        );

        return Inertia::render('Lots/CreateEdit', [
            'farms'    => $this->availableFarms($authUser),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create lots'),
            403
        );

        // Converts '' → null for nullable fields before validation.
        // Required because Inertia always sends empty string for
        // unfilled inputs, and Laravel's nullable rule requires null.
        $this->sanitizeNullableFields($request);

        $validated = $this->validateLot($request);

        // company_id is not submitted by the form — it is derived from
        // the selected farm to ensure tenant integrity.
        $farm = Farm::findOrFail($validated['farm_id']);
        $this->authorizeFarm($authUser, $farm->id);

        $validated['company_id'] = $farm->company_id;

        Lot::create($validated);

        return redirect()->route('lots.index')
            ->with('success', __('messages.lot_created'));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, Lot $lot)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view lots'),
            403
        );

        $this->authorizeFarm($authUser, $lot->farm_id);

        $lot->load([
            'farm:id,name_en,name_ar',
            'animals:id,lot_id,tag,breed,sex,status,purchase_price,purchase_weight_kg',
        ]);

        return Inertia::render('Lots/Show', [
            'lot'      => $this->formatLot($lot),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────
    // Points to Lots/CreateEdit.vue — single file handles both modes.
    // The Vue page detects edit mode via: isEdit = !!props.lot (true here).
    public function edit(Request $request, Lot $lot)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit lots'),
            403
        );

        $this->authorizeFarm($authUser, $lot->farm_id);

        return Inertia::render('Lots/CreateEdit', [
            'lot'      => $this->formatLot($lot),
            'farms'    => $this->availableFarms($authUser),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Lot $lot)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit lots'),
            403
        );

        $this->authorizeFarm($authUser, $lot->farm_id);

        // Converts '' → null for nullable fields before validation.
        $this->sanitizeNullableFields($request);

        $validated = $this->validateLot($request, $lot->id);

        // If the farm changed, re-derive company_id from the new farm.
        if ((int) $validated['farm_id'] !== (int) $lot->farm_id) {
            $farm = Farm::findOrFail($validated['farm_id']);
            $this->authorizeFarm($authUser, $farm->id);
            $validated['company_id'] = $farm->company_id;
        }

        $lot->update($validated);

        return redirect()->route('lots.index')
            ->with('success', __('messages.lot_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, Lot $lot)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete lots'),
            403
        );

        $this->authorizeFarm($authUser, $lot->farm_id);

        if ($lot->animals()->exists()) {
            return back()->withErrors(['lot' => __('messages.lot_has_animals')]);
        }

        $lot->delete();

        return redirect()->route('lots.index')
            ->with('success', __('messages.lot_deleted'));
    }

    // ── Archive ───────────────────────────────────────────────────
    public function archive(Request $request, Lot $lot)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit lots'),
            403
        );

        $this->authorizeFarm($authUser, $lot->farm_id);

        $lot->update([
            'status'      => 'archived',
            'closed_date' => now()->toDateString(),
        ]);

        return back()->with('success', __('messages.lot_archived'));
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * sanitizeNullableFields
     * ──────────────────────
     * Inertia always sends an empty string ("") for inputs the user
     * leaves blank. Laravel's `nullable` validation rule requires an
     * actual null — not "". This method converts "" → null for every
     * nullable field before validate() is called.
     *
     * Must be called BEFORE validateLot() in both store() and update().
     */


  /**
 * validateLot
 * ───────────
 * Central validation rules for both store() and update().
 * $ignoreId — the current lot's ID passed during update() so the
 * unique lot_number rule does not reject the record's own existing value.
 * Note: company_id is intentionally excluded — it is always
 * derived server-side from the selected farm, never from user input.
 */
    private function validateLot(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'farm_id'               => 'required|exists:farms,id',
            'name'                  => 'required|string|max:255',
            'lot_number' => [
                                'nullable',
                                'string',
                                'max:255',
                                Rule::unique('lots', 'lot_number')
                                    ->where('farm_id', $request->farm_id)
                                    ->ignore($ignoreId),
                            ],
            'start_date'            => 'required|date',
            'target_slaughter_date' => 'nullable|date|after:start_date',
            'pen'                   => 'nullable|string|max:255',
            'status'                => ['nullable', Rule::in(['active', 'completed', 'archived'])],
            'notes_en'              => 'nullable|string',
            'notes_ar'              => 'nullable|string',
        ]);
    }

    /**
     * formatLot
     * ─────────
     * Shapes a Lot model into the exact array the Vue pages expect.
     * Always returns both notes_en and notes_ar so the bilingual
     * form can populate both fields regardless of active locale.
     */
    private function formatLot(Lot $lot): array
    {
        return [
            'id'                    => $lot->id,
            'farm_id'               => $lot->farm_id,
            'farm'                  => $lot->farm ? [
                'id'      => $lot->farm->id,
                'name_en' => $lot->farm->name_en,
                'name_ar' => $lot->farm->name_ar,
            ] : null,
            'name'                  => $lot->name,
            'lot_number'            => $lot->lot_number,
            'start_date'            => $lot->start_date?->toDateString(),
            'target_slaughter_date' => $lot->target_slaughter_date?->toDateString(),
            'closed_date'           => $lot->closed_date?->toDateString(),
            'pen'                   => $lot->pen,
            'status'                => $lot->status,
            'notes_en'              => $lot->notes_en,
            'notes_ar'              => $lot->notes_ar,
            'animals_count'         => $lot->animals_count ?? 0,
            'animals'               => $lot->relationLoaded('animals')
                ? $lot->animals->map(fn($a) => [
                    'id'     => $a->id,
                    'tag'    => $a->tag,
                    'breed'  => $a->breed,
                    'sex'    => $a->sex,
                    'status' => $a->status,
                ])
                : [],
            'created_at'            => $lot->created_at?->format('d M Y'),
        ];
    }

    /**
     * availableFarms
     * ──────────────
     * Super-admin sees all active farms across all companies.
     * Regular users see only farms belonging to their own company.
     */
    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    /**
     * authorizeFarm
     * ─────────────
     * Ensures the authenticated user belongs to the same company
     * as the farm being accessed. Super-admins bypass this check.
     */
    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;

        $farm = Farm::findOrFail($farmId);
        abort_unless($farm->company_id === $authUser->company_id, 403);
    }
}