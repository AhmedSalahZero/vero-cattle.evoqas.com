<?php

namespace App\Http\Controllers;

use App\Models\CalvingRecord;
use App\Models\BreedingRecord;
use App\Models\Animal;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Calving Controller
//  Records calving events. When a calf is born on farm, a new
//  animal record is created with source = 'born_on_farm'.
//  The breeding record pregnancy_status is updated to 'calved'.
// ══════════════════════════════════════════════════════════════════

class CalvingController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view calvings'),
            403
        );

        $query = CalvingRecord::with([
            'dam:id,tag,breed',
            'calf:id,tag,sex',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))   $query->where('farm_id', $request->farm_id);
        if ($request->filled('condition')) $query->where('condition', $request->condition);
        if ($request->filled('date_from')) $query->whereDate('calving_date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('calving_date', '<=', $request->date_to);

        $records = $query->orderBy('calving_date', 'desc')->paginate(30)->withQueryString();

        return Inertia::render('Calvings/Index', [
            'records'  => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'    => $this->availableFarms($authUser),
            'filters'  => $request->only(['farm_id', 'condition', 'date_from', 'date_to']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create calvings'),
            403
        );

        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['dam_id']);

        $companyId = $authUser->is_super_admin
            ? Animal::find($validated['dam_id'])->company_id
            : $authUser->company_id;

        $record = CalvingRecord::create([...$validated, 'company_id' => $companyId]);

        // Update breeding record status to calved
        if (!empty($validated['breeding_record_id'])) {
            BreedingRecord::where('id', $validated['breeding_record_id'])
                ->update(['pregnancy_status' => 'calved']);
        }

        return back()->with('success', __('messages.calving_recorded'));
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, CalvingRecord $calvingRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit calvings'),
            403
        );

        $this->authorizeAnimal($authUser, $calvingRecord->dam_id);

        $validated = $this->validateRecord($request, $calvingRecord->id);
        $calvingRecord->update($validated);

        return back()->with('success', __('messages.calving_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, CalvingRecord $calvingRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete calvings'),
            403
        );

        $this->authorizeAnimal($authUser, $calvingRecord->dam_id);
        $calvingRecord->delete();

        return back()->with('success', __('messages.calving_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'dam_id'              => 'required|exists:animals,id',
            'calf_id'             => 'nullable|exists:animals,id',
            'breeding_record_id'  => 'nullable|exists:breeding_records,id',
            'farm_id'             => 'required|exists:farms,id',
            'calving_date'        => 'required|date',
            'calving_time'        => 'nullable|date_format:H:i',
            'birth_weight_kg'     => 'nullable|numeric|min:0|max:200',
            'condition'           => ['required', Rule::in(['healthy', 'weak', 'stillborn'])],
            'difficulty_score'    => 'nullable|integer|min:1|max:5',
            'colostrum_fed'       => 'boolean',
            'notes'               => 'nullable|string',
        ]);
    }

    private function formatRecord(CalvingRecord $record): array
    {
        return [
            'id'               => $record->id,
            'dam_id'           => $record->dam_id,
            'dam'              => $record->dam ? [
                'id'  => $record->dam->id,
                'tag' => $record->dam->tag,
            ] : null,
            'calf_id'          => $record->calf_id,
            'calf'             => $record->calf ? [
                'id'  => $record->calf->id,
                'tag' => $record->calf->tag,
                'sex' => $record->calf->sex,
            ] : null,
            'farm_id'          => $record->farm_id,
            'calving_date'     => $record->calving_date->toDateString(),
            'calving_time'     => $record->calving_time,
            'birth_weight_kg'  => $record->birth_weight_kg,
            'condition'        => $record->condition,
            'difficulty_score' => $record->difficulty_score,
            'colostrum_fed'    => $record->colostrum_fed,
            'notes'            => $record->notes,
            'created_at'       => $record->created_at?->format('d M Y'),
        ];
    }

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function authorizeAnimal($authUser, int $animalId): void
    {
        if ($authUser->is_super_admin) return;
        $animal = Animal::findOrFail($animalId);
        abort_unless($animal->company_id === $authUser->company_id, 403);
    }
}
