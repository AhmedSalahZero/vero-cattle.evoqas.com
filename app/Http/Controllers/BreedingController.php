<?php

namespace App\Http\Controllers;

use App\Models\BreedingRecord;
use App\Models\CalvingRecord;
use App\Models\Animal;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Breeding Controller
//  Records breeding events. Expected calving date is calculated
//  by the BreedingService (+283 days). Controller stores only.
// ══════════════════════════════════════════════════════════════════

class BreedingController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view breeding'),
            403
        );

        $query = BreedingRecord::with([
            'dam:id,tag,breed',
            'sire:id,tag,breed',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))           $query->where('farm_id', $request->farm_id);
        if ($request->filled('pregnancy_status'))  $query->where('pregnancy_status', $request->pregnancy_status);

        // Calving due soon — within 30 days
        if ($request->boolean('calving_due_soon')) {
            $query->whereDate('expected_calving_date', '<=', now()->addDays(30))
                  ->whereDate('expected_calving_date', '>=', now())
                  ->where('pregnancy_status', 'confirmed');
        }

        $records = $query->orderBy('breeding_date', 'desc')->paginate(30)->withQueryString();

        return Inertia::render('Breeding/Index', [
            'records'  => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'    => $this->availableFarms($authUser),
            'filters'  => $request->only(['farm_id', 'pregnancy_status', 'calving_due_soon']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create breeding'),
            403
        );

        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['dam_id']);

        $companyId = $authUser->is_super_admin
            ? Animal::find($validated['dam_id'])->company_id
            : $authUser->company_id;

        $validated = $this->checkParents($validated);

        // Expected calving date = breeding date + 283 days
        // Full methodology in BreedingService::calculateExpectedCalvingDate
        $validated['expected_calving_date'] = \Carbon\Carbon::parse($validated['breeding_date'])
            ->addDays(283)
            ->toDateString();

        BreedingRecord::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', __('messages.breeding_recorded'));
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, BreedingRecord $breedingRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit breeding'),
            403
        );

        $this->authorizeAnimal($authUser, $breedingRecord->dam_id);

        $validated = $this->validateRecord($request, $breedingRecord->id);
        $validated = $this->checkParents($validated);

        if (isset($validated['breeding_date'])) {
            $validated['expected_calving_date'] = \Carbon\Carbon::parse($validated['breeding_date'])
                ->addDays(283)
                ->toDateString();
        }

        $breedingRecord->update($validated);

        return back()->with('success', __('messages.breeding_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, BreedingRecord $breedingRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete breeding'),
            403
        );

        $this->authorizeAnimal($authUser, $breedingRecord->dam_id);
        $breedingRecord->delete();

        return back()->with('success', __('messages.breeding_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────
    /**
     * The dam must be a female and the sire a male, both of the dam's company.
     * The farm is taken from the dam, never from the form.
     */
    private function checkParents(array $validated): array
    {
        $dam = Animal::findOrFail($validated['dam_id']);

        if ($dam->sex !== 'female') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'dam_id' => __('messages.dam_must_be_female'),
            ]);
        }

        if (!empty($validated['sire_id'])) {
            $sire = Animal::findOrFail($validated['sire_id']);

            if ($sire->sex !== 'male' || (int) $sire->company_id !== (int) $dam->company_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sire_id' => __('messages.sire_must_be_male'),
                ]);
            }
        }

        $validated['farm_id'] = $dam->farm_id;

        return $validated;
    }

    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'dam_id'            => 'required|exists:animals,id',
            'sire_id'           => 'nullable|exists:animals,id',
            'farm_id'           => 'required|exists:farms,id',
            'method'            => ['required', Rule::in(['natural', 'ai'])],
            'breeding_date'     => 'required|date',
            'pregnancy_status'  => ['nullable', Rule::in(['open', 'confirmed', 'lost', 'calved'])],
            'confirmation_date' => 'nullable|date|after:breeding_date',
            'notes'             => 'nullable|string',
        ]);
    }

    private function formatRecord(BreedingRecord $record): array
    {
        return [
            'id'                    => $record->id,
            'dam_id'                => $record->dam_id,
            'dam'                   => $record->dam ? [
                'id'  => $record->dam->id,
                'tag' => $record->dam->tag,
            ] : null,
            'sire_id'               => $record->sire_id,
            'sire'                  => $record->sire ? [
                'id'  => $record->sire->id,
                'tag' => $record->sire->tag,
            ] : null,
            'farm_id'               => $record->farm_id,
            'method'                => $record->method,
            'breeding_date'         => $record->breeding_date->toDateString(),
            'expected_calving_date' => $record->expected_calving_date?->toDateString(),
            'pregnancy_status'      => $record->pregnancy_status,
            'confirmation_date'     => $record->confirmation_date?->toDateString(),
            'notes'                 => $record->notes,
            'days_until_calving'    => $record->expected_calving_date
                ? max(0, (int) now()->diffInDays($record->expected_calving_date, false))
                : null,
            'created_at'            => $record->created_at?->format('d M Y'),
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
        abort_unless((int) $animal->company_id === (int) $authUser->company_id, 403);
    }
}
