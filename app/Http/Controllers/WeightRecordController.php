<?php

namespace App\Http\Controllers;

use App\Models\WeightRecord;
use App\Models\Animal;
use App\Models\Lot;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Weight Record Controller
//
//  index() now returns SESSION-GROUPED data, not flat records.
//  A session = same (lot_id, date, session_ref or date only).
//  Each session has: date, lot, recorder, animal_count, avg_weight,
//  avg_adg, and an animals[] array for the expandable sub-table.
//
//  Sold / slaughtered animal guard:
//    update() and destroy() block with 422 if animal.status
//    is 'sold' or 'slaughtered'.
//
//  Days on Feed rule:
//    - Record with a NEXT record → days = gap to next record
//    - LAST record → days = gap to TODAY
// ══════════════════════════════════════════════════════════════════

class WeightRecordController extends Controller
{
    // ── Index — session-grouped weight records ────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view weight'),
            403
        );

        // ── Build base query ──────────────────────────────────────
        $query = WeightRecord::with([
            'animal:id,tag,breed,sex,status',
            'lot:id,name',
            'recorder:id,name',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('lot_id'))    $query->where('lot_id', $request->lot_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('date', '<=', $request->date_to);

        // Fetch all matching records ordered so we can group them
        $allRecords = $query->orderBy('date', 'desc')
                            ->orderBy('lot_id')
                            ->orderBy('id')
                            ->get();

        // ── Group into sessions ───────────────────────────────────
        // Session key = date + lot_id + recorder_id
        // (same lot weighed twice on same day by different recorders
        //  are separate sessions)
        $sessions = [];
        foreach ($allRecords as $r) {
            $key = $r->date->toDateString() . '_' . ($r->lot_id ?? 'null') . '_' . ($r->recorded_by ?? 'null');
            if (!isset($sessions[$key])) {
                $sessions[$key] = [
                    'key'          => $key,
                    'date'         => $r->date->toDateString(),
                    'lot_id'       => $r->lot_id,
                    'lot_name'     => $r->lot?->name ?? '—',
                    'recorder'     => $r->recorder?->name ?? '—',
                    'session_ref'  => $r->session_ref,
                    'animals'      => [],
                ];
            }
            $sessions[$key]['animals'][] = $this->formatRecord($r);
        }

        // ── Compute session-level aggregates ──────────────────────
        $sessionList = array_values(array_map(function ($s) {
            $animals    = $s['animals'];
            $count      = count($animals);
            $totalWt    = array_sum(array_column($animals, 'weight_kg'));
            $adgRows    = array_filter($animals, fn($a) => $a['adg_kg'] !== null && $a['adg_kg'] > 0);
            $adgSum     = array_sum(array_column(array_values($adgRows), 'adg_kg'));

            return array_merge($s, [
                'animal_count' => $count,
                'avg_weight'   => $count ? round($totalWt / $count, 2) : null,
                'avg_adg'      => count($adgRows) ? round($adgSum / count($adgRows), 3) : null,
            ]);
        }, $sessions));

        // ── KPI summary across ALL sessions shown ─────────────────
        $allAnimals  = $allRecords;
        $totalCount  = $allAnimals->count();
        $avgWeight   = $totalCount ? round($allAnimals->avg('weight_kg'), 2) : null;
        $adgAnimals  = $allAnimals->filter(fn($r) => $r->adg_kg > 0);
        $avgAdg      = $adgAnimals->count() ? round($adgAnimals->avg('adg_kg'), 3) : null;
        $lastDate    = $allAnimals->max(fn($r) => $r->date->toDateString());

        return Inertia::render('Weight/Index', [
            'sessions' => $sessionList,
            'lots'     => $this->availableLots($authUser),
            'filters'  => $request->only(['lot_id', 'date_from', 'date_to']),
            'kpis'     => [
                'total_records' => $totalCount,
                'avg_weight'    => $avgWeight,
                'avg_adg'       => $avgAdg,
                'last_date'     => $lastDate,
            ],
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

        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['animal_id']);

        // Block creating weight for sold / slaughtered animals
        $animal = Animal::findOrFail($validated['animal_id']);
        abort_if(
            in_array($animal->status, ['sold', 'slaughtered']),
            422,
            $request->wantsJson()
                ? 'Cannot add weight records for sold or slaughtered animals.'
                : __('messages.animal_inactive_weight')
        );

        // Resolve farm_id server-side from lot
        $farmId = null;
        if (!empty($validated['lot_id'])) {
            $lot    = Lot::findOrFail($validated['lot_id']);
            $farmId = $lot->farm_id;
            $this->authorizeFarm($authUser, $farmId);
        }

        $companyId = $authUser->is_super_admin
            ? (int) $animal->company_id
            : (int) $authUser->company_id;

        WeightRecord::create([
            ...$validated,
            'farm_id'     => $farmId ?? $validated['farm_id'] ?? null,
            'company_id'  => $companyId,
            'recorded_by' => $authUser->id,
        ]);

        $this->recalculateAnimalWeightStats($validated['animal_id'], $companyId);

        return back()->with('success', __('messages.weight_recorded'));
    }

    // ── Batch store ───────────────────────────────────────────────
    public function batchStore(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create weight'),
            403
        );

        $request->validate([
            'lot_id'               => 'required|exists:lots,id',
            'date'                 => 'required|date',
            'session_ref'          => 'nullable|string|max:255',
            'scale_reference'      => 'nullable|string|max:255',
            'readings'             => 'required|array|min:1',
            'readings.*.animal_id' => 'required|exists:animals,id',
            'readings.*.weight_kg' => 'required|numeric|min:0',
        ]);

        $lot = Lot::findOrFail($request->lot_id);
        $this->authorizeFarm($authUser, $lot->farm_id);

        $companyId = $authUser->is_super_admin
            ? $lot->farm->company_id
            : $authUser->company_id;

        $animalIds = [];

        foreach ($request->readings as $reading) {
            // Skip sold/slaughtered animals silently in batch
            $animal = Animal::find($reading['animal_id']);
            if (!$animal || in_array($animal->status, ['sold', 'slaughtered'])) {
                continue;
            }

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
            $animalIds[] = $reading['animal_id'];
        }

        foreach (array_unique($animalIds) as $animalId) {
            $this->recalculateAnimalWeightStats($animalId, $companyId);
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

        // Block editing weight for sold / slaughtered animals
        $animal = Animal::findOrFail($weightRecord->animal_id);
        abort_if(
            in_array($animal->status, ['sold', 'slaughtered']),
            422,
            'Cannot modify weight records for sold or slaughtered animals.'
        );

        $validated = $request->validate([
            'date'            => 'required|date',
            'weight_kg'       => 'required|numeric|min:0|max:2000',
            'lot_id'          => 'nullable|exists:lots,id',
            'scale_reference' => 'nullable|string|max:255',
            'session_ref'     => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
        ]);

        $weightRecord->update($validated);

        $this->recalculateAnimalWeightStats($weightRecord->animal_id, $weightRecord->company_id);

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

        // Block deleting weight for sold / slaughtered animals
        $animal = Animal::findOrFail($weightRecord->animal_id);
        abort_if(
            in_array($animal->status, ['sold', 'slaughtered']),
            422,
            'Cannot delete weight records for sold or slaughtered animals.'
        );

        $animalId  = $weightRecord->animal_id;
        $companyId = $weightRecord->company_id;

        $weightRecord->delete();

        $this->recalculateAnimalWeightStats($animalId, $companyId);

        return back()->with('success', __('messages.weight_deleted'));
    }

    // ── JSON endpoint — active animals for a lot ──────────────────
    public function lotAnimals(Lot $lot): \Illuminate\Http\JsonResponse
    {
        $authUser = Auth::user();
        $this->authorizeFarm($authUser, $lot->farm_id);

        // Only active animals — sold/slaughtered are excluded
        $animals = Animal::where('lot_id', $lot->id)
            ->where('status', 'active')
            ->orderBy('tag')
            ->get(['id', 'tag', 'breed', 'sex']);

        return response()->json(['animals' => $animals]);
    }

    // ── Private helpers ───────────────────────────────────────────

    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'animal_id'       => 'required|exists:animals,id',
            'lot_id'          => 'nullable|exists:lots,id',
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
                'id'     => $record->animal->id,
                'tag'    => $record->animal->tag,
                'breed'  => $record->animal->breed,
                'sex'    => $record->animal->sex,
                'status' => $record->animal->status,
            ] : null,
            'lot_id'          => $record->lot_id,
            'lot'             => $record->lot ? [
                'id'   => $record->lot->id,
                'name' => $record->lot->name,
            ] : null,
            'date'            => $record->date->toDateString(),
            'weight_kg'       => (float) $record->weight_kg,
            'adg_kg'          => $record->adg_kg !== null ? (float) $record->adg_kg : null,
            'total_gain_kg'   => $record->total_gain_kg !== null ? (float) $record->total_gain_kg : null,
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
        abort_unless((int)$animal->company_id === (int)$authUser->company_id, 403);
    }

    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless((int)$farm->company_id === (int)$authUser->company_id, 403);
    }

    /**
     * Recalculate adg_kg, total_gain_kg, days_on_feed for every weight
     * record belonging to an animal, ordered by date ascending.
     *
     * Days on Feed rule:
     *   - Record that has a NEXT record:
     *       days = days from THIS date → NEXT record's date
     *       gain = next weight − this weight
     *   - LAST (or only) record:
     *       days = days from this date → TODAY
     *       gain = 0
     */
    private function recalculateAnimalWeightStats(int $animalId, int $companyId): void
    {
        $records = WeightRecord::where('animal_id', $animalId)
            ->where('company_id', $companyId)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($records->isEmpty()) return;

        $today = now()->startOfDay();
        $count = $records->count();

        foreach ($records as $index => $record) {
            $isLast = ($index === $count - 1);

            if ($isLast) {
                $days = (int) \Carbon\Carbon::parse($record->date)->diffInDays($today);
                $record->update([
                    'total_gain_kg' => 0,
                    'days_on_feed'  => $days,
                    'adg_kg'        => 0,
                ]);
            } else {
                $next = $records[$index + 1];
                $gain = round((float)$next->weight_kg - (float)$record->weight_kg, 2);
                $days = (int) \Carbon\Carbon::parse($record->date)
                    ->diffInDays(\Carbon\Carbon::parse($next->date));
                $adg  = ($days > 0) ? round($gain / $days, 3) : 0;

                $record->update([
                    'total_gain_kg' => $gain,
                    'days_on_feed'  => $days,
                    'adg_kg'        => $adg,
                ]);
            }
        }
    }
}