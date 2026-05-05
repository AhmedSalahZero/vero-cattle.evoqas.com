<?php

namespace App\Http\Controllers;

use App\Models\HealthRecord;
use App\Models\Animal;
use App\Models\Farm;
use App\Models\Lot;
use App\Models\InventoryItem;
use App\Services\HealthRecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Health Record Controller
//
//  Treatments, vet visits, surgeries recorded against individual
//  animals. Medicine is linked to inventory — stock is decremented
//  on create and restored on delete via HealthRecordService.
//
//  📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
//
//  Rule §9.1: create() and edit() both render Health/CreateEdit
//  with a mode prop — never separate Create and Edit Vue files.
// ══════════════════════════════════════════════════════════════════

class HealthRecordController extends Controller
{
    public function __construct(private HealthRecordService $service) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view health'),
            403
        );

        $query = HealthRecord::with([
            'animal:id,tag,breed',
            'lot:id,name',
            'inventoryItem:id,name_en,name_ar,unit',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))           $query->where('farm_id', $request->farm_id);
        if ($request->filled('lot_id'))            $query->where('lot_id', $request->lot_id);
        if ($request->filled('animal_id'))         $query->where('animal_id', $request->animal_id);
        if ($request->filled('type'))              $query->where('type', $request->type);
        if ($request->filled('date_from'))         $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))           $query->whereDate('date', '<=', $request->date_to);
        if ($request->boolean('withdrawal_active')) {
            $query->whereDate('withdrawal_clear_date', '>=', now());
        }

        $records = $query->orderBy('date', 'desc')->paginate(50)->withQueryString();

        return Inertia::render('Health/Index', [
            'records'  => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'    => $this->availableFarms($authUser),
            'lots'     => $this->availableLots($authUser),
            'filters'  => $request->only([
                'farm_id','lot_id','animal_id','type',
                'date_from','date_to','withdrawal_active',
            ]),
        ]);
    }

    // ── Create (§9.1 — renders CreateEdit with mode:'create') ─────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create health'),
            403
        );

        return Inertia::render('Health/CreateEdit', [
            'mode'        => 'create',
            'record'      => null,
            'farms'       => $this->availableFarms($authUser),
            'lots'        => $this->availableLots($authUser),
            'animals'     => $this->availableAnimals($authUser),   // flat list with lot_id, filtered client-side
            'medicines'   => $this->availableMedicines($authUser), // inventory items category=medicine
            'vets'        => $this->availableVets($authUser),
            'preAnimalId' => $request->integer('animal_id') ?: null,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create health'),
            403
        );

        $this->sanitizeNullableFields($request);
        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['animal_id']);

        $companyId = $authUser->is_super_admin
            ? Animal::find($validated['animal_id'])->company_id
            : $authUser->company_id;

        // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
        // All stock logic lives in the service — controller stays thin
        $this->service->create($validated, $companyId);

        return redirect()->route('health.index')
            ->with('success', __('messages.health_record_created'));
    }

    // ── Edit (§9.1 — renders CreateEdit with mode:'edit') ─────────
    public function edit(HealthRecord $healthRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit health'),
            403
        );

        $this->authorizeAnimal($authUser, $healthRecord->animal_id);
        $healthRecord->load(['animal:id,tag,breed', 'lot:id,name', 'inventoryItem:id,name_en,name_ar,unit,unit_cost,qty_on_hand']);

        return Inertia::render('Health/CreateEdit', [
            'mode'        => 'edit',
            'record'      => $this->formatRecord($healthRecord),
            'farms'       => $this->availableFarms($authUser),
            'lots'        => $this->availableLots($authUser),
            'animals'     => $this->availableAnimals($authUser),
            'medicines'   => $this->availableMedicines($authUser),
            'vets'        => $this->availableVets($authUser),
            'preAnimalId' => null,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, HealthRecord $healthRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit health'),
            403
        );

        $this->authorizeAnimal($authUser, $healthRecord->animal_id);
        $this->sanitizeNullableFields($request);
        $validated = $this->validateRecord($request, $healthRecord->id);

        // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
        $this->service->update($healthRecord, $validated);

        return redirect()->route('health.index')
            ->with('success', __('messages.health_record_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(HealthRecord $healthRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete health'),
            403
        );

        $this->authorizeAnimal($authUser, $healthRecord->animal_id);

        // 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
        // Service restores stock before deleting
        $this->service->delete($healthRecord);

        return back()->with('success', __('messages.health_record_deleted'));
    }

    // ── Private: validation ───────────────────────────────────────
    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'animal_id'           => 'required|exists:animals,id',
            'farm_id'             => 'required|exists:farms,id',
            'lot_id'              => 'nullable|exists:lots,id',
            'inventory_item_id'   => 'required|exists:inventory_items,id',
            'type'                => ['required', Rule::in(['treatment','vet_visit','surgery','other'])],
            'date'                => 'required|date',
            'condition'           => 'nullable|string|max:255',
            'dose'                => 'required|numeric|min:0.01',
            'dose_unit'           => 'nullable|string|max:50',
            'route'               => 'nullable|string|max:100',
            'cost'                => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|size:3',
            'vet_id'              => 'nullable|exists:users,id',
            'vet_name'            => 'nullable|string|max:255',
            'outcome'             => ['nullable', Rule::in(['recovered','ongoing','died','slaughtered','unknown'])],
            'withdrawal_clear_date' => 'nullable|date|after_or_equal:date',
            'is_quarantine'       => 'boolean',
            'notes'               => 'nullable|string',
        ]);
    }

    // ── Private: formatRecord ──────────────────────────────────────
    private function formatRecord(HealthRecord $r): array
    {
        return [
            'id'                    => $r->id,
            'animal_id'             => $r->animal_id,
            'animal'                => $r->animal ? ['id' => $r->animal->id, 'tag' => $r->animal->tag, 'breed' => $r->animal->breed] : null,
            'lot_id'                => $r->lot_id,
            'lot'                   => $r->lot ? ['id' => $r->lot->id, 'name' => $r->lot->name] : null,
            'farm_id'               => $r->farm_id,
            'inventory_item_id'     => $r->inventory_item_id,
            'inventory_item'        => $r->inventoryItem ? [
                'id'          => $r->inventoryItem->id,
                'name_en'     => $r->inventoryItem->name_en,
                'name_ar'     => $r->inventoryItem->name_ar,
                'unit'        => $r->inventoryItem->unit,
                'unit_cost'   => $r->inventoryItem->unit_cost,
                'qty_on_hand' => $r->inventoryItem->qty_on_hand,
            ] : null,
            'type'                  => $r->type,
            'date'                  => $r->date?->toDateString(),
            'condition'             => $r->condition,
            'medicine_name'         => $r->medicine_name,
            'dose'                  => $r->dose,
            'dose_unit'             => $r->dose_unit,
            'route'                 => $r->route,
            'cost'                  => $r->cost,
            'currency'              => $r->currency ?? 'EGP',
            'vet_id'                => $r->vet_id,
            'vet_name'              => $r->vet_name,
            'outcome'               => $r->outcome,
            'withdrawal_clear_date' => $r->withdrawal_clear_date?->toDateString(),
            'is_quarantine'         => $r->is_quarantine,
            'notes'                 => $r->notes,
            'created_at'            => $r->created_at?->format('d M Y'),
        ];
    }

    // ── Private: available data helpers ───────────────────────────
    private function availableFarms($authUser)
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id','company_id','name_en','name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id','company_id','name_en','name_ar']);
    }

    private function availableLots($authUser)
    {
        return $authUser->is_super_admin
            ? Lot::where('status', 'active')->orderBy('name')->get(['id','farm_id','name'])
            : Lot::whereHas('farm', fn($q) => $q->where('company_id', $authUser->company_id))
                ->where('status', 'active')->orderBy('name')->get(['id','farm_id','name']);
    }

    // Flat list of animals with lot_id — filtered client-side in Vue
    private function availableAnimals($authUser)
    {
        $q = Animal::where('status', 'active')
            ->orderBy('tag');

        if (!$authUser->is_super_admin) {
            $q->where('company_id', $authUser->company_id);
        }

        return $q->get(['id','tag','breed','lot_id','farm_id']);
    }

    // Only inventory items categorised as 'medicine' with stock > 0
    private function availableMedicines($authUser)
    {
        $q = InventoryItem::where('category', 'medicine')
            ->where('is_active', true)
            ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $q->where('company_id', $authUser->company_id);
        }

        return $q->get(['id','farm_id','name_en','name_ar','unit','unit_cost','qty_on_hand']);
    }

    private function availableVets($authUser)
    {
        // Users with Veterinarian role in this company
        return \App\Models\User::role('Veterinarian')
            ->when(!$authUser->is_super_admin, fn($q) => $q->where('company_id', $authUser->company_id))
            ->orderBy('name')
            ->get(['id','name']);
    }

    private function authorizeAnimal($authUser, int $animalId): void
    {
        if ($authUser->is_super_admin) return;
        $animal = Animal::findOrFail($animalId);
        abort_unless($animal->company_id === $authUser->company_id, 403);
    }

 
}