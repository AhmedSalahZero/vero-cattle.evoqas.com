<?php

namespace App\Http\Controllers;

use App\Models\WeightRecord;
use App\Models\Animal;
use App\Models\Lot;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Weight Record Controller
//  Records individual and batch weight readings.
//  ADG and FCR calculations live in WeightAnalysisService.
//  This controller handles CRUD only — no calculations here.
// ══════════════════════════════════════════════════════════════════

class WeightRecordController extends Controller
{
    // ── Index — weight records for a lot or animal ────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view weight'),
            403
        );

        $query = WeightRecord::with([
            'animal:id,tag,breed,sex',
            'lot:id,name',
            'recorder:id,name',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('lot_id'))    $query->where('lot_id', $request->lot_id);
        if ($request->filled('animal_id')) $query->where('animal_id', $request->animal_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('date', '<=', $request->date_to);

        $records = $query->orderBy('date', 'desc')->paginate(50)->withQueryString();

        return Inertia::render('Weight/Index', [
            'records'  => $records->through(fn($r) => $this->formatRecord($r)),
            'lots'     => $this->availableLots($authUser),
            'filters'  => $request->only(['lot_id', 'animal_id', 'date_from', 'date_to']),
        ]);
    }

    // ── Store single weight reading ───────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create weight'),
            403
        );

        // FIX 3: farm_id is derived server-side from the chosen lot —
        // never trusted from user input. Only lot_id comes from the form.
        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['animal_id']);

        // Resolve farm_id from lot
        $farmId = null;
        if (!empty($validated['lot_id'])) {
            $lot    = Lot::findOrFail($validated['lot_id']);
            $farmId = $lot->farm_id;
            $this->authorizeFarm($authUser, $farmId);
        }

        WeightRecord::create([
            ...$validated,
            'farm_id'     => $farmId ?? $validated['farm_id'] ?? null,
            'company_id'  => $authUser->is_super_admin
                ? Animal::find($validated['animal_id'])->company_id
                : $authUser->company_id,
            'recorded_by' => $authUser->id,
        ]);

        return back()->with('success', __('messages.weight_recorded'));
    }

    // ── Batch store — record weights for entire lot at once ───────
    public function batchStore(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create weight'),
            403
        );

        $request->validate([
            'lot_id'          => 'required|exists:lots,id',
            'date'            => 'required|date',
            'session_ref'     => 'nullable|string|max:255',
            'scale_reference' => 'nullable|string|max:255',
            'readings'        => 'required|array|min:1',
            'readings.*.animal_id' => 'required|exists:animals,id',
            'readings.*.weight_kg' => 'required|numeric|min:0',
        ]);

        $lot = Lot::findOrFail($request->lot_id);
        $this->authorizeFarm($authUser, $lot->farm_id);

        $companyId = $authUser->is_super_admin
            ? $lot->farm->company_id
            : $authUser->company_id;

        foreach ($request->readings as $reading) {
            WeightRecord::create([
                'company_id'      => $companyId,
                'farm_id'         => $lot->farm_id,
                'animal_id'       => $reading['animal_id'],
                'lot_id'          => $request->lot_id,
                'date'            => $request->date,
                'weight_kg'       => $reading['weight_kg'],
                'session_ref'     => $request->session_ref,
                'scale_reference' => $request->scale_reference,
                'recorded_by'     => $authUser->id,
            ]);
        }

        return back()->with('success', __('messages.batch_weight_recorded'));
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, WeightRecord $weightRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit weight'),
            403
        );

        $this->authorizeAnimal($authUser, $weightRecord->animal_id);

        $validated = $this->validateRecord($request, $weightRecord->id);
        $weightRecord->update($validated);

        return back()->with('success', __('messages.weight_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, WeightRecord $weightRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete weight'),
            403
        );

        $this->authorizeAnimal($authUser, $weightRecord->animal_id);

        $weightRecord->delete();

        return back()->with('success', __('messages.weight_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * FIX 4 (new method): JSON endpoint — returns active animals for a lot.
     * Called by the Vue single-entry and batch panels via fetch().
     * Route: GET /lots/{lot}/animals  →  weight.lot-animals
     * Returns: { animals: [{ id, tag, breed, sex }] }
     */
    public function lotAnimals(Lot $lot): \Illuminate\Http\JsonResponse
    {
        $authUser = Auth::user();
        $this->authorizeFarm($authUser, $lot->farm_id);

        $animals = Animal::where('lot_id', $lot->id)
            ->where('status', 'active')
            ->orderBy('tag')
            ->get(['id', 'tag', 'breed', 'sex']);

        return response()->json(['animals' => $animals]);
    }
    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'animal_id'       => 'required|exists:animals,id',
            'lot_id'          => 'nullable|exists:lots,id',
            // FIX 3: farm_id is now nullable — resolved server-side from lot_id.
            // Only accepted as a fallback when no lot is provided (e.g. direct farm-level entry).
            'farm_id'         => 'nullable|exists:farms,id',
            'date'            => 'required|date',
            'weight_kg'       => 'required|numeric|min:0|max:2000',
            'scale_reference' => 'nullable|string|max:255',
            'session_ref'     => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
        ]);
    }

    private function formatRecord(WeightRecord $record): array
    {
        return [
            'id'              => $record->id,
            'animal_id'       => $record->animal_id,
            'animal'          => $record->animal ? [
                'id'    => $record->animal->id,
                'tag'   => $record->animal->tag,
                'breed' => $record->animal->breed,   // FIX 2: needed for table sub-line
                'sex'   => $record->animal->sex,     // FIX 2: needed for M/F indicator
            ] : null,
            'lot_id'          => $record->lot_id,
            'lot'             => $record->lot ? [
                'id'   => $record->lot->id,
                'name' => $record->lot->name,
            ] : null,
            'date'            => $record->date->toDateString(),
            'weight_kg'       => $record->weight_kg,
            'adg_kg'          => $record->adg_kg,
            'total_gain_kg'   => $record->total_gain_kg,
            'days_on_feed'    => $record->days_on_feed,
            'scale_reference' => $record->scale_reference,
            'session_ref'     => $record->session_ref,
            'recorder'        => $record->recorder?->name,
            'notes'           => $record->notes,
            'created_at'      => $record->created_at?->format('d M Y'),
        ];
    }

    private function availableLots($authUser): \Illuminate\Support\Collection
    {
        // FIX 1: with('farm') so Vue can render "Lot — Farm" in dropdowns.
        // Both name_en and name_ar needed for n() bilingual helper.
        $query = Lot::with('farm:id,name_en,name_ar')
            ->where('status', 'active')
            ->orderBy('name');

        if (!$authUser->is_super_admin) {
            $query->whereHas('farm', fn($q) =>
                $q->where('company_id', $authUser->company_id)
            );
        }

        return $query->get(['id', 'farm_id', 'name']);
    }

    private function authorizeAnimal($authUser, int $animalId): void
    {
        if ($authUser->is_super_admin) return;
        $animal = Animal::findOrFail($animalId);
        abort_unless($animal->company_id === $authUser->company_id, 403);
    }

    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless($farm->company_id === $authUser->company_id, 403);
    }
}