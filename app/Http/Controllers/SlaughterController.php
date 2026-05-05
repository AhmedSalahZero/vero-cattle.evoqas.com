<?php

namespace App\Http\Controllers;

use App\Models\SlaughterRecord;
use App\Models\Animal;
use App\Models\Lot;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Slaughter Controller
//  Records slaughter events. Dressing % is stored here.
//  COGS recognition and inventory reduction posted by accounting
//  event listener. Slaughter cost posted to COGS (5500).
//
//  FIX 3 APPLIED — destroy() now rolls animal status back to 'active'
//  when a slaughter record is deleted.
//  📌 METHODOLOGY REFERENCE: SlaughterStatusRollback (#27)
// ══════════════════════════════════════════════════════════════════

class SlaughterController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view slaughter'),
            403
        );

        $query = SlaughterRecord::with([
            'animal:id,tag,breed',
            'lot:id,name',
            'farm:id,name_en,name_ar',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))   $query->where('farm_id', $request->farm_id);
        if ($request->filled('lot_id'))    $query->where('lot_id', $request->lot_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('date', '<=', $request->date_to);

        $records = $query->orderBy('date', 'desc')->paginate(30)->withQueryString();

        return Inertia::render('Slaughter/Index', [
            'records'  => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'    => $this->availableFarms($authUser),
            'lots'     => $this->availableLots($authUser),
            'filters'  => $request->only(['farm_id', 'lot_id', 'date_from', 'date_to']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create slaughter'),
            403
        );

        $validated = $this->validateRecord($request);
        $this->authorizeAnimal($authUser, $validated['animal_id']);

        // 📌 METHODOLOGY REFERENCE: DressingPercentage (#19)
        // Guard: deadweight must be strictly less than liveweight (100% dressing is physically impossible)
        if (!empty($validated['deadweight_kg']) && !empty($validated['liveweight_kg'])) {
            abort_if(
                $validated['deadweight_kg'] >= $validated['liveweight_kg'],
                422,
                __('messages.deadweight_exceeds_liveweight')
            );

            $validated['dressing_pct'] = round(
                ($validated['deadweight_kg'] / $validated['liveweight_kg']) * 100,
                2
            );
        }

        $companyId = $authUser->is_super_admin
            ? Animal::find($validated['animal_id'])->company_id
            : $authUser->company_id;

        DB::transaction(function () use ($validated, $companyId) {
            $record = SlaughterRecord::create([...$validated, 'company_id' => $companyId]);

            // Update animal status to slaughtered
            Animal::where('id', $validated['animal_id'])
                ->update([
                    'status'      => 'slaughtered',
                    'status_date' => $validated['date'],
                ]);

            // ── SLAU-P5-1: Phase 5 hook — COGS recognition + inventory reduction ──────
            // 📌 METHODOLOGY REFERENCE: SaleAndSlaughterStatusLifecycle (#27)
            //
            // When Phase 5 accounting auto-posting is active, dispatch:
            // event(new \App\Events\SlaughterRecorded([
            //     'slaughter_record_id' => $record->id,
            //     'company_id'          => $companyId,
            //     'farm_id'             => $record->farm_id,
            //     'lot_id'              => $record->lot_id,
            //     'animal_id'           => $record->animal_id,
            //     'date'                => $record->date,
            //     'slaughter_cost'      => $record->slaughter_cost,
            //     'liveweight_kg'       => $record->liveweight_kg,
            //     'deadweight_kg'       => $record->deadweight_kg,
            //     'dressing_pct'        => $record->dressing_pct,
            // ]));
            //
            // The accounting driver will auto-post:
            //   T1: Dr COGS — Livestock (5100)       Livestock WIP WAC × head
            //       Cr Livestock WIP (1700)           Livestock WIP WAC × head
            //   T2: Dr Slaughter Cost Expense (5500)  slaughter_cost
            //       Cr Accounts Payable / Cash         slaughter_cost
            //
            // Write-back after event resolves:
            // $record->update(['journal_entry_id' => $journalEntryId]);
        });

        return redirect()->route('slaughter.index')
            ->with('success', __('messages.slaughter_recorded'));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, SlaughterRecord $slaughterRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view slaughter'),
            403
        );

        $this->authorizeAnimal($authUser, $slaughterRecord->animal_id);
        $slaughterRecord->load(['animal:id,tag,breed', 'lot:id,name', 'farm:id,name_en,name_ar']);

        return Inertia::render('Slaughter/Show', [
            'record' => $this->formatRecord($slaughterRecord),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, SlaughterRecord $slaughterRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit slaughter'),
            403
        );

        $this->authorizeAnimal($authUser, $slaughterRecord->animal_id);

        $validated = $this->validateRecord($request, $slaughterRecord->id);

        // 📌 METHODOLOGY REFERENCE: DressingPercentage (#19)
        // SLAU-F1: Guard must also run on update — same rule as store()
        if (!empty($validated['deadweight_kg']) && !empty($validated['liveweight_kg'])) {
            abort_if(
                $validated['deadweight_kg'] >= $validated['liveweight_kg'],
                422,
                __('messages.deadweight_exceeds_liveweight')
            );

            $validated['dressing_pct'] = round(
                ($validated['deadweight_kg'] / $validated['liveweight_kg']) * 100,
                2
            );
        }

        $slaughterRecord->update($validated);

        return back()->with('success', __('messages.slaughter_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, SlaughterRecord $slaughterRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete slaughter'),
            403
        );

        $this->authorizeAnimal($authUser, $slaughterRecord->animal_id);
            // ── Fix #3: Block delete if a Phase 5 journal entry has been posted ──
            // 📌 METHODOLOGY REFERENCE: SaleAndSlaughterStatusLifecycle (#27)
            // Once Phase 5 auto-posting is active, a posted journal entry means
            // COGS or inventory movements have already hit the ledger.
            // The delete must be blocked — a reversal entry must be created first.
            abort_if(
                $slaughterRecord->journal_entry_id &&
                $slaughterRecord->journalEntry?->status === 'posted',
                422,
                __('messages.slaughter_has_posted_entry')
            );

        DB::transaction(function () use ($slaughterRecord) {
            $animalId = $slaughterRecord->animal_id;

            $slaughterRecord->delete();

            // ── FIX 3: Roll back animal status on slaughter delete ──────
            // 📌 METHODOLOGY REFERENCE: SlaughterStatusRollback (#27)
            //
            // When a slaughter record is deleted, the animal should return to
            // 'active' status IF AND ONLY IF:
            //   1. The animal's current status is 'slaughtered'
            //   2. There are no other slaughter records remaining for this animal
            //      (an animal could theoretically have multiple records from edits)
            //
            // We never roll back 'sold', 'dead', or any other terminal status —
            // only reverse the specific slaughter that was just deleted.
            $remainingSlaughterCount = SlaughterRecord::where('animal_id', $animalId)
                ->withTrashed(false)    // only non-deleted records
                ->count();

            if ($remainingSlaughterCount === 0) {
                Animal::where('id', $animalId)
                    ->where('status', 'slaughtered')
                    ->update([
                        'status'      => 'active',
                        'status_date' => null,
                    ]);
            }
        });

        return back()->with('success', __('messages.slaughter_deleted'));
    }

    // ── Animals JSON endpoint ─────────────────────────────────────
    public function getAnimals(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view slaughter'),
            403
        );

        $farmId = (int) $request->farm_id;
        $lotId  = $request->lot_id ? (int) $request->lot_id : null;

        abort_unless($farmId > 0, 422);

        // Only 'active' animals can be slaughtered (not 'reserved' — those are pending sales)
        $query = Animal::where('farm_id', $farmId)
            ->where('status', 'active')
            ->orderBy('tag');

        if ($lotId) {
            $query->where('lot_id', $lotId);
        }

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return response()->json(
            $query->get(['id', 'tag', 'breed', 'sex', 'lot_id'])
        );
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateRecord(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'animal_id'      => 'required|exists:animals,id',
            'farm_id'        => 'required|exists:farms,id',
            'lot_id'         => 'nullable|exists:lots,id',
            'date'           => 'required|date',
            'abattoir'       => 'nullable|string|max:255',
            'liveweight_kg'  => 'required|numeric|min:0',
            'deadweight_kg'  => 'nullable|numeric|min:0',
            'grade'          => 'nullable|string|max:100',
            'brand_number'   => 'nullable|string|max:100',
            'slaughter_cost' => 'required|numeric|min:0',   // SLAU-S1: NOT NULL in schema — must be explicit, not silently zero
            'currency'       => 'nullable|string|size:3',
            'notes'          => 'nullable|string',
        ]);
    }

    private function formatRecord(SlaughterRecord $record): array
    {
        return [
            'id'             => $record->id,
            'animal_id'      => $record->animal_id,
            'animal'         => $record->animal ? [
                'id'    => $record->animal->id,
                'tag'   => $record->animal->tag,
                'breed' => $record->animal->breed,
            ] : null,
            'lot_id'         => $record->lot_id,
            'lot'            => $record->lot ? [
                'id'   => $record->lot->id,
                'name' => $record->lot->name,
            ] : null,
            'farm_id'        => $record->farm_id,
            'farm'           => $record->farm ? [
                'id'      => $record->farm->id,
                'name_en' => $record->farm->name_en,
                'name_ar' => $record->farm->name_ar,
            ] : null,
            'date'           => $record->date->toDateString(),
            'abattoir'       => $record->abattoir,
            'liveweight_kg'  => $record->liveweight_kg,
            'deadweight_kg'  => $record->deadweight_kg,
            'dressing_pct'   => $record->dressing_pct,
            'grade'          => $record->grade,
            'brand_number'   => $record->brand_number,
            'slaughter_cost' => $record->slaughter_cost,
            'currency'       => $record->currency,
            'notes'          => $record->notes,
            'created_at'     => $record->created_at?->format('d M Y'),
        ];
    }

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function availableLots($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Lot::orderBy('name')->get(['id', 'farm_id', 'name'])
            : Lot::whereHas('farm', fn($q) => $q->where('company_id', $authUser->company_id))
                ->orderBy('name')->get(['id', 'farm_id', 'name']);
    }

    private function authorizeAnimal($authUser, int $animalId): void
    {
        if ($authUser->is_super_admin) return;
        $animal = Animal::findOrFail($animalId);
        abort_unless($animal->company_id === $authUser->company_id, 403);
    }
}