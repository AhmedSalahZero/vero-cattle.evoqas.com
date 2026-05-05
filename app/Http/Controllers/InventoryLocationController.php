<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Farm;
use App\Services\InventoryLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Location Controller
//
//  Manages physical storage locations (warehouses, store rooms…)
//  at company level (farm_id = NULL) or within a specific farm.
//
//  Access: Company Admin + Accountant roles only.
//
//  When a new location is created → InventoryLocationService
//  auto-creates zero-stock rows for every existing master item.
// ══════════════════════════════════════════════════════════════════

class InventoryLocationController extends Controller
{
    public function __construct(private InventoryLocationService $service) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view inventory'),
            403
        );

        $query = InventoryLocation::with('farm:id,name_en,name_ar')
            ->withCount('stockItems');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id')) {
            $query->where('farm_id', $request->farm_id);
        }

        $locations = $query->orderByRaw('farm_id IS NULL DESC')
            ->orderBy('name_en')
            ->get();

        return Inertia::render('Inventory/Locations/Index', [
            'locations' => $locations->map(fn($l) => $this->formatLocation($l)),
            'farms'     => $this->availableFarms($authUser),
            'filters'   => $request->only(['farm_id']),
            'canManage' => $authUser->is_super_admin
                || $authUser->hasRole('Company Admin')
                || $authUser->hasRole('Accountant'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create()
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);

        return Inertia::render('Inventory/Locations/CreateEdit', [
            'mode'     => 'create',
            'location' => null,
            'farms'    => $this->availableFarms($authUser),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);
        $this->sanitizeNullableFields($request);
        $validated = $this->validateLocation($request);

        // Bilingual cross-field guard §5.2
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $companyId = $authUser->is_super_admin
            ? (isset($validated['farm_id'])
                ? Farm::findOrFail($validated['farm_id'])->company_id
                : $request->integer('company_id'))
            : $authUser->company_id;

        $location = InventoryLocation::create([
            ...$validated,
            'company_id' => $companyId,
        ]);

        // Fan-out: create zero-stock rows for all existing master items
        $this->service->syncNewLocation($location);

        return redirect()->route('inventory.locations.index')
            ->with('success', __('messages.location_created'));
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(InventoryLocation $inventoryLocation)
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);
        $this->authorizeLocation($authUser, $inventoryLocation);

        return Inertia::render('Inventory/Locations/CreateEdit', [
            'mode'     => 'edit',
            'location' => $this->formatLocation($inventoryLocation),
            'farms'    => $this->availableFarms($authUser),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, InventoryLocation $inventoryLocation)
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);
        $this->authorizeLocation($authUser, $inventoryLocation);
        $this->sanitizeNullableFields($request);
        $validated = $this->validateLocation($request);

        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $inventoryLocation->update($validated);

        return redirect()->route('inventory.locations.index')
            ->with('success', __('messages.location_updated'));
    }

    // ── Toggle Active ─────────────────────────────────────────────
    public function toggleActive(InventoryLocation $inventoryLocation)
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);
        $this->authorizeLocation($authUser, $inventoryLocation);

        $inventoryLocation->update(['is_active' => !$inventoryLocation->is_active]);

        return back()->with('success', __('messages.location_status_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(InventoryLocation $inventoryLocation)
    {
        $authUser = Auth::user();

        $this->authorizeManage($authUser);
        $this->authorizeLocation($authUser, $inventoryLocation);

        // Block if any stock rows have qty > 0
        $hasStock = $inventoryLocation->stockItems()
            ->where('qty_on_hand', '>', 0)
            ->exists();

        abort_if(
            $hasStock,
            422,
            __('messages.location_has_stock')
        );

        $inventoryLocation->delete();

        return back()->with('success', __('messages.location_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateLocation(Request $request): array
    {
        return $request->validate([
            'farm_id'   => 'nullable|exists:farms,id',
            'name_en'   => 'nullable|string|max:255',
            'name_ar'   => 'nullable|string|max:255',
            'type'      => 'required|in:warehouse,store_room,cold_storage,cabinet,shed,other',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string',
        ]);
    }

    private function formatLocation(InventoryLocation $l): array
    {
        return [
            'id'               => $l->id,
            'company_id'       => $l->company_id,
            'farm_id'          => $l->farm_id,
            'farm'             => $l->farm ? [
                'id'      => $l->farm->id,
                'name_en' => $l->farm->name_en,
                'name_ar' => $l->farm->name_ar,
            ] : null,
            'is_central'       => $l->farm_id === null,
            'name_en'          => $l->name_en,
            'name_ar'          => $l->name_ar,
            'type'             => $l->type,
            'is_active'        => $l->is_active,
            'notes'            => $l->notes,
            'stock_items_count'=> $l->stock_items_count ?? 0,
            'display_label'    => $l->display_label,
            'display_label_ar' => $l->display_label_ar,
            'created_at'       => $l->created_at?->format('d M Y'),
        ];
    }

    private function availableFarms($authUser)
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id','company_id','name_en','name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id','company_id','name_en','name_ar']);
    }

    private function authorizeManage($authUser): void
    {
        abort_unless(
            $authUser->is_super_admin
            || $authUser->hasRole('Company Admin')
            || $authUser->hasRole('Accountant')
            || $authUser->hasPermissionTo('create inventory'),
            403
        );
    }

    private function authorizeLocation($authUser, InventoryLocation $location): void
    {
        if ($authUser->is_super_admin) return;
        abort_unless($location->company_id === $authUser->company_id, 403);
    }


}