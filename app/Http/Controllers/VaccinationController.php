<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Animal;
use App\Models\FiscalYear;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lot;
use App\Models\InventoryLocation;
use App\Models\VaccinationRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Vaccination Controller
//
//  📌 METHODOLOGY REFERENCE: VaccinationBatchStore (#18)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//
//  SESSION 49 FULL REWORK — Migration 059
//
//  Vaccinations now bind directly to an inventory item
//  (category=vaccine) at a specific warehouse location, replacing
//  the old vaccines catalog architecture.
//
//  What changed:
//    — vaccine_id REMOVED from all new record paths
//    — inventory_item_id + location_id REQUIRED for new records
//    — cost auto-computed from stock row WAC × dose_given_ml
//    — inventory decremented from specific location stock row
//    — InventoryTransaction (type=consumption) written per session
//    — T4 journal entry posted: Dr WIP (1310) / Cr Vaccine (1330)
//    — For batchStore: one InventoryTransaction + one JE for total
//      batch dose (dose_per_animal × head count), not one per animal
//
//  Legacy records (vaccine_id set, inventory_item_id NULL) are
//  still readable — formatRecord() handles both paths.
//
//  The vaccines catalog page (Vaccinations/Vaccines.vue) is retired
//  from the sidebar. The vaccines() method is kept for backward
//  compatibility but marked deprecated.
// ══════════════════════════════════════════════════════════════════

class VaccinationController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view vaccinations'),
            403
        );

        $query = VaccinationRecord::with([
            'animal:id,tag,breed',
            'inventoryItem:id,name_en,name_ar',   // new
            'location:id,name_en,name_ar',          // new
            'lot:id,name',
            'farm:id,name_en,name_ar',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('farm_id'))    $query->where('farm_id', $request->farm_id);
        if ($request->filled('lot_id'))     $query->where('lot_id', $request->lot_id);
        if ($request->filled('animal_id'))  $query->where('animal_id', $request->animal_id);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('animal', fn($q) => $q->where('tag', 'like', "%{$search}%"));
        }

        if ($request->boolean('due_soon')) {
            $query->whereDate('next_due_date', '<=', now()->addDays(7))
                  ->whereDate('next_due_date', '>=', now());
        }

        $records = $query->orderBy('date', 'desc')->paginate(50)->withQueryString();

        $lots = $authUser->is_super_admin
            ? Lot::where('status', 'active')->orderBy('name')->get(['id', 'farm_id', 'name'])
            : Lot::where('company_id', $authUser->company_id)->where('status', 'active')
                ->orderBy('name')->get(['id', 'farm_id', 'name']);

        return Inertia::render('Vaccinations/Index', [
            'records'         => $records->through(fn($r) => $this->formatRecord($r)),
            'vaccineItems'    => $this->availableVaccineItems($authUser),  // new — replaces vaccines
            'locations'       => $this->availableLocations($authUser),     // new
            'farms'           => $this->availableFarms($authUser),
            'lots'            => $lots,
            'filters'         => $request->only(['farm_id', 'lot_id', 'animal_id', 'due_soon', 'search']),
        ]);
    }

    // ── Batch Store ───────────────────────────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
    //
    // Accepts one session header (farm, lot, inventory_item_id,
    // location_id, date, dose_given_ml, batch_number, next_due_date,
    // administered_by, notes) + array of animal_ids.
    //
    // For the inventory/accounting side:
    //   total_dose = dose_given_ml × count(animal_ids)
    //   One InventoryTransaction for total_dose
    //   One T4 journal entry for total cost
    //   cost_per_animal = WAC × dose_given_ml
    //
    // All or nothing — single DB::transaction.
    //
    public function batchStore(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create vaccinations'),
            403
        );

        // ── Step 1: Validate header + animal list ─────────────────
        $request->validate([
            'farm_id'            => 'required|exists:farms,id',
            'lot_id'             => 'required|exists:lots,id',
            'inventory_item_id'  => 'required|exists:inventory_items,id',
            'location_id'        => 'required|exists:inventory_locations,id',
            'date'               => 'required|date',
            'animal_ids'         => 'required|array|min:1',
            'animal_ids.*'       => 'required|exists:animals,id',
            'batch_number'       => 'nullable|string|max:255',
            'next_due_date'      => 'nullable|date|after:date',
            'dose_given_ml'      => 'required|numeric|min:0.001',
            'administered_by'    => 'nullable|exists:users,id',
            'notes'              => 'nullable|string',
        ]);

        // ── Step 2: Resolve company + authorize ───────────────────
        $lot = Lot::with('farm')->findOrFail($request->lot_id);
        $companyId = $authUser->is_super_admin
            ? $lot->farm->company_id
            : $authUser->company_id;

        if (!$authUser->is_super_admin) {
            abort_unless($companyId === $authUser->company_id, 403);
        }

        // ── Step 3: Verify all animals belong to this lot ─────────
        $animalIds  = $request->animal_ids;
        $validCount = Animal::whereIn('id', $animalIds)
            ->where('lot_id', $request->lot_id)
            ->count();
        abort_unless($validCount === count($animalIds), 422, __('validation.vaccination.animals_not_in_lot'));

        // ── Step 4: Validate inventory item is category=vaccine ───
        $masterItem = InventoryItem::where('id', $request->inventory_item_id)
            ->where('company_id', $companyId)
            ->where('category', 'vaccine')
            ->where('is_master', true)
            ->where('is_active', true)
            ->firstOrFail();

        DB::transaction(function () use (
            $request, $animalIds, $companyId, $lot, $masterItem
        ) {
            $dosePerAnimal = (float) $request->dose_given_ml;
            $animalCount   = count($animalIds);

            // ── Step 5: Load + lock location stock row ────────────
            $stockRow = InventoryItem::where('master_item_id', $masterItem->id)
                ->where('location_id', $request->location_id)
                ->where('company_id', $companyId)
                ->where('is_master', false)
                ->lockForUpdate()
                ->first();

            if (!$stockRow) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'location_id' => __('validation.vaccination.no_stock_row_at_location'),
                ]);
            }

            // ── Step 6: Hard block — total dose for whole batch ───
            // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §13
            $totalDose = (float) bcadd(
                bcmul((string) $dosePerAnimal, (string) $animalCount, 4),
                '0', 3
            );

            if (bccomp((string) $stockRow->qty_on_hand, (string) $totalDose, 4) < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'dose_given_ml' => __('validation.vaccination.insufficient_stock', [
                        'available' => $stockRow->qty_on_hand,
                        'required'  => $totalDose,
                        'item'      => $masterItem->name_en ?? $masterItem->name_ar,
                    ]),
                ]);
            }

            // ── Step 7: Read WAC + compute costs ─────────────────
            $unitCost      = (string) $stockRow->unit_cost;
            $costPerAnimal = (float) bcadd(
                bcmul($unitCost, (string) $dosePerAnimal, 4),
                '0', 2
            );
            $totalCost = (float) bcadd(
                bcmul($unitCost, (string) $totalDose, 4),
                '0', 2
            );

            // ── Step 8: Decrement stock ───────────────────────────
            $qtyAfter = (float) bcadd(
                bcsub((string) $stockRow->qty_on_hand, (string) $totalDose, 4),
                '0', 3
            );
            $stockRow->decrement('qty_on_hand', $totalDose);

            // ── Step 9: Insert vaccination records per animal ─────
            $now  = now();
            $rows = [];
            foreach ($animalIds as $animalId) {
                $rows[] = [
                    'company_id'        => $companyId,
                    'farm_id'           => $request->farm_id,
                    'lot_id'            => $request->lot_id,
                    'animal_id'         => $animalId,
                    'inventory_item_id' => $masterItem->id,   // new
                    'location_id'       => $request->location_id, // new
                    'vaccine_id'        => null,              // retired
                    'date'              => $request->date,
                    'batch_number'      => $request->batch_number,
                    'next_due_date'     => $request->next_due_date,
                    'dose_given_ml'     => $dosePerAnimal,
                    'cost'              => $costPerAnimal,    // WAC-based, not manual
                    'administered_by'   => $request->administered_by,
                    'notes'             => $request->notes,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
            VaccinationRecord::insert($rows);

            // ── Step 10: Write single InventoryTransaction for batch
            // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
            // One transaction for the total batch dose — not one per animal
            InventoryTransaction::create([
                'company_id'  => $companyId,
                'farm_id'     => $lot->farm_id,
                'location_id' => $request->location_id,
                'item_id'     => $stockRow->id,
                'type'        => 'consumption',
                'date'        => $request->date,
                'qty'         => $totalDose,
                'unit_cost'   => (float) $unitCost,
                'total'       => $totalCost,
                'qty_after'   => $qtyAfter,
                'wac_after'   => (float) $unitCost,
                'ref_type'    => 'vaccination_batch',
                'ref_id'      => null,   // batch — no single record id
                'recorded_by' => Auth::id(),
                'notes'       => 'Vaccination batch: ' . $animalCount . ' animals — Lot #' . $request->lot_id,
            ]);

            // ── Step 11: Post T4 journal entry for total batch cost
            // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
            // Dr Livestock WIP (1310) [lot_id stamped]
            // Cr Vaccine Inventory (1330)
            $je = $this->postVaccineJournalEntry(
                $companyId,
                $totalCost,
                $request->date,
                (int) $request->lot_id,
                $masterItem->name_en ?? $masterItem->name_ar,
                $animalCount
            );

            // Write journal_entry_id to all inserted records
            if ($je) {
                VaccinationRecord::where('company_id', $companyId)
                    ->where('lot_id', $request->lot_id)
                    ->where('date', $request->date)
                    ->whereIn('animal_id', $animalIds)
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $je->id]);
            }
        });

        return back()->with('success', __('messages.vaccinations_batch_recorded'));
    }

    // ── Store (single animal) ─────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create vaccinations'),
            403
        );

        // Wrap as single-animal batch
        $request->merge(['animal_ids' => [$request->input('animal_id')]]);
        return $this->batchStore($request);
    }

    // ── Destroy ───────────────────────────────────────────────────
    //
    // Deleting a vaccination record:
    //   — For new records: restore inventory, reverse JE if posted
    //   — For legacy records (vaccine_id set): simple delete
    //
    public function destroy(Request $request, VaccinationRecord $vaccinationRecord)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete vaccinations'),
            403
        );

        $this->authorizeAnimal($authUser, $vaccinationRecord->animal_id);

        // Legacy records — simple delete, no inventory to restore
        if ($vaccinationRecord->isLegacy()) {
            $vaccinationRecord->delete();
            return back()->with('success', __('messages.vaccination_deleted'));
        }

        // Guard: posted journal entry blocks individual delete
        // For batch vaccinations, deleting one animal's record would
        // not correctly reverse the batch JE — user must reverse at batch level
        if ($vaccinationRecord->journal_entry_id) {
            $je = JournalEntry::find($vaccinationRecord->journal_entry_id);
            if ($je && $je->status === 'posted') {
                return back()->withErrors([
                    'delete' => __('validation.vaccination.posted_je_blocks_delete'),
                ]);
            }
        }

        DB::transaction(function () use ($vaccinationRecord) {
            // Restore stock — dose_given_ml back to location stock row
            if ($vaccinationRecord->inventory_item_id && $vaccinationRecord->location_id) {
                $stockRow = InventoryItem::where('master_item_id', $vaccinationRecord->inventory_item_id)
                    ->where('location_id', $vaccinationRecord->location_id)
                    ->where('is_master', false)
                    ->lockForUpdate()
                    ->first();

                if ($stockRow && $vaccinationRecord->dose_given_ml > 0) {
                    $stockRow->increment('qty_on_hand', (float) $vaccinationRecord->dose_given_ml);
                }
            }

            $vaccinationRecord->delete();
        });

        return back()->with('success', __('messages.vaccination_deleted'));
    }

    // ── Vaccines catalog (deprecated) ─────────────────────────────
    // Kept for backward compatibility — route and page are retired from sidebar
    // @deprecated Use inventory items with category=vaccine instead
    public function vaccines(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view vaccinations'),
            403
        );

        // Redirect to inventory filtered to vaccine category
        return redirect()->route('inventory.index', ['category' => 'vaccine']);
    }

    // ── Private: post T4 journal entry ───────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
    //
    // T4: Dr Livestock WIP (1310) [lot_id stamped]
    //     Cr Vaccine Inventory (1330)
    //
    // Soft-fails with session warning if accounts or fiscal year missing.
    private function postVaccineJournalEntry(
        int    $companyId,
        float  $totalCost,
        string $date,
        int    $lotId,
        string $vaccineName,
        int    $animalCount
    ): ?JournalEntry {

        $wipAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1310%')
            ->where('allow_direct_posting', true)
            ->first();

        $vaccineAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1330%')
            ->where('allow_direct_posting', true)
            ->first();

        if (!$wipAccount || !$vaccineAccount) {
            session()->flash('warning', __('messages.vaccination_je_accounts_missing'));
            return null;
        }

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning', __('messages.vaccination_je_no_fiscal_year'));
            return null;
        }

        // 📌 BcmathMonetaryPrecision (#39)
        $amount = (float) bcadd((string) $totalCost, '0', 2);
        $period = (int) date('n', strtotime($date));

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $date,
            'period'         => $period,
            'reference'      => 'VACC-' . now()->format('YmdHis'),
            'description'    => 'Vaccination: ' . $vaccineName . ' — ' . $animalCount . ' animals — Lot #' . $lotId,
            'entry_type'     => 'health',   // vaccination uses same entry_type as health
            'status'         => 'posted',
            'total_debit'    => $amount,
            'total_credit'   => $amount,
            'posted_by'      => Auth::id(),
            'posted_at'      => now(),
        ]);

        // Dr Livestock WIP (1310) — lot_id stamped (batch is lot-level)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $wipAccount->id,
            'debit'      => $amount,
            'credit'     => 0,
            'lot_id'     => $lotId,    // 📌 required per Methodology #43 §9
            'animal_id'  => null,      // batch — allocated per animal at COGS time
            'memo'       => 'Vaccination: ' . $vaccineName . ' — ' . $animalCount . ' animals',
        ]);

        // Cr Vaccine Inventory (1330)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $vaccineAccount->id,
            'debit'      => 0,
            'credit'     => $amount,
            'lot_id'     => $lotId,
            'animal_id'  => null,
            'memo'       => 'Vaccine consumed from inventory',
        ]);

        return $je;
    }

    // ── Private: format record ────────────────────────────────────
    private function formatRecord(VaccinationRecord $record): array
    {
        $isLegacy = $record->isLegacy();

        return [
            'id'                 => $record->id,
            'animal_id'          => $record->animal_id,
            'animal'             => $record->animal
                ? ['id' => $record->animal->id, 'tag' => $record->animal->tag]
                : null,
            'farm_id'            => $record->farm_id,
            'farm'               => $record->farm
                ? ['id' => $record->farm->id, 'name_en' => $record->farm->name_en, 'name_ar' => $record->farm->name_ar]
                : null,
            'lot_id'             => $record->lot_id,
            'lot'                => $record->lot
                ? ['id' => $record->lot->id, 'name' => $record->lot->name]
                : null,
            // New fields
            'inventory_item_id'  => $record->inventory_item_id,
            'inventory_item'     => $record->inventoryItem
                ? ['id' => $record->inventoryItem->id, 'name_en' => $record->inventoryItem->name_en, 'name_ar' => $record->inventoryItem->name_ar]
                : null,
            'location_id'        => $record->location_id,
            'location'           => $record->location
                ? ['id' => $record->location->id, 'name_en' => $record->location->name_en, 'name_ar' => $record->location->name_ar]
                : null,
            // Legacy fields
            'is_legacy'          => $isLegacy,
            'vaccine_id'         => $isLegacy ? $record->vaccine_id : null,
            'vaccine'            => ($isLegacy && $record->vaccine)
                ? ['id' => $record->vaccine->id, 'name_en' => $record->vaccine->name_en, 'name_ar' => $record->vaccine->name_ar]
                : null,
            // Shared fields
            'date'               => $record->date->toDateString(),
            'batch_number'       => $record->batch_number,
            'next_due_date'      => $record->next_due_date?->toDateString(),
            'dose_given_ml'      => $record->dose_given_ml,
            'cost'               => $record->cost,
            'journal_entry_id'   => $record->journal_entry_id,
            'notes'              => $record->notes,
            'is_due_soon'        => $record->next_due_date
                && $record->next_due_date->isFuture()
                && $record->next_due_date->diffInDays(now()) <= 7,
            'created_at'         => $record->created_at?->format('d M Y'),
        ];
    }

    // ── Private: data helpers ─────────────────────────────────────

    /**
     * Inventory items with category=vaccine for the vaccination form.
     * Returns master items with location stock breakdown.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
     */
    private function availableVaccineItems($authUser): \Illuminate\Support\Collection
    {
        $query = InventoryItem::with([
            'locationStocks.location:id,farm_id,name_en,name_ar',
        ])
        ->where('category', 'vaccine')
        ->where('is_master', true)
        ->where('is_active', true)
        ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return $query->get()->map(fn($item) => [
            'id'      => $item->id,
            'name_en' => $item->name_en,
            'name_ar' => $item->name_ar,
            'unit'    => $item->unit,
            'stocks'  => $item->locationStocks->map(fn($s) => [
                'stock_id'         => $s->id,
                'location_id'      => $s->location_id,
                'farm_id'          => $s->location?->farm_id,
                'location_name_en' => $s->location?->name_en,
                'location_name_ar' => $s->location?->name_ar,
                'qty_on_hand'      => (float) $s->qty_on_hand,
                'unit_cost'        => (float) $s->unit_cost,   // WAC
            ])->values(),
        ]);
    }

    private function availableLocations($authUser): \Illuminate\Support\Collection
    {
        $query = InventoryLocation::where('is_active', true)->orderBy('name_en');
        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }
        return $query->get(['id', 'farm_id', 'name_en', 'name_ar', 'type']);
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