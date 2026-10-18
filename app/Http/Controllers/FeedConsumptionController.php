<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Animal;
use App\Models\FeedConsumption;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lot;
use App\Models\Farm;
use App\Models\InventoryLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Feed Consumption Controller
//
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
//  📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
//
//  SESSION 49 FULL REWORK — Migration 059
//    — inventory_item_id + location_id replace feed_item_id + ration_id
//    — unit_cost sourced from inventory stock row WAC
//    — T2 journal entry posted atomically
//
//  SESSION 53 CHANGES — Migration 060
//    — head_count REMOVED from all user-facing inputs
//    — animal_ids[] array replaces head_count in batchStore()
//    — head_count is now SERVER-COMPUTED as count(animal_ids)
//    — pivot rows inserted into feed_consumption_animals for every
//      FeedConsumption record created
//    — LivestockIntelligenceService COGS query now uses pivot table
//      to allocate feed cost only to explicitly selected animals
//    — lotsAnimalsEndpoint prop added to index() for Vue animal picker
//
//  Legacy records (feed_item_id set, inventory_item_id NULL) are still
//  readable via index() — formatRecord() handles both new and legacy.
// ══════════════════════════════════════════════════════════════════

class FeedConsumptionController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view feed'),
            403
        );

        $query = FeedConsumption::with([
            'lot:id,name',
            'farm:id,name_en,name_ar',
            'inventoryItem:id,name_en,name_ar,unit',
            'location:id,name_en,name_ar',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('lot_id'))    $query->where('lot_id', $request->lot_id);
        if ($request->filled('farm_id'))   $query->where('farm_id', $request->farm_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('date', '<=', $request->date_to);

        $records = $query->orderBy('date', 'desc')->paginate(50)->withQueryString();

        return Inertia::render('Feed/Consumption/Index', [
            'records'             => $records->through(fn($r) => $this->formatRecord($r)),
            'farms'               => $this->availableFarms($authUser),
            'lots'                => $this->availableLots($authUser),
            'inventoryFeedItems'  => $this->availableFeedInventoryItems($authUser),
            'locations'           => $this->availableLocations($authUser),
            'filters'             => $request->only(['lot_id', 'farm_id', 'date_from', 'date_to']),
           // 📌 FeedConsumptionAnimalSelection — animals keyed by lot_id for Vue picker
            'lotsAnimals'        => $this->availableLotsAnimals($authUser),
        ]);
    }

    // ── Batch store — multiple feed items for one lot ─────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
    // 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
    //
    // SESSION 53: animal_ids[] replaces head_count.
    // head_count is derived server-side as count(animal_ids).
    //
    // For each feed row:
    //   1. Load + lock the location-specific stock row
    //   2. Hard-block if qty_on_hand < qty_kg
    //   3. Read WAC from stock row — this is unit_cost
    //   4. Compute total_cost = qty_kg × WAC (bcmath)
    //   5. Decrement stock row
    //   6. Write FeedConsumption record (head_count = count of animal_ids)
    //   7. Insert feed_consumption_animals pivot rows for all animal_ids
    //   8. Write InventoryTransaction (type=consumption)
    //   9. Post T2 journal entry: Dr WIP (1310) / Cr Feed Inventory (1320)
    //  10. Write journal_entry_id back to FeedConsumption
    //
    public function batchStore(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create feed'),
            403
        );

        // ── Step 1: Validate header ───────────────────────────────
        // 📌 FeedConsumptionAnimalSelection — animal_ids replaces head_count
        $request->validate([
            'farm_id'      => 'required|exists:farms,id',
            'lot_id'       => 'required|exists:lots,id',
            'date'         => 'required|date',
            'animal_ids'   => 'required|array|min:1',
            'animal_ids.*' => 'required|integer|exists:animals,id',
            'notes'        => 'nullable|string',
        ]);

        // ── Step 2: Authorize lot + resolve companyId ─────────────
        $this->authorizeLot($authUser, $request->lot_id);
        $lot       = Lot::with('farm')->findOrFail($request->lot_id);
        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);
        $lotId     = (int) $request->lot_id;

        // ── Step 3: Derive head_count + validate animal ownership ─
        // 📌 FeedConsumptionAnimalSelection
        // head_count is server-computed — never trusted from client input.
        // All animal_ids must belong to the selected lot and be active/quarantine.
        $animalIds = array_map('intval', $request->animal_ids);
        $headCount = count($animalIds);

        $validCount = Animal::whereIn('id', $animalIds)
            ->where('lot_id', $lotId)
            ->whereIn('status', ['active', 'quarantine'])
            ->count();

        if ($validCount !== $headCount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'animal_ids' => 'One or more selected animals do not belong to the selected lot or are not active.',
            ]);
        }

        // ── Step 4: Validate rows ─────────────────────────────────
        // inventory_item_id scoped to company + category=feed
        // location_id scoped to same company
        $request->validate([
            'rows'                     => 'required|array|min:1',
            'rows.*.inventory_item_id' => [
                'required',
                Rule::exists('inventory_items', 'id')
                    ->where('company_id', $companyId)
                    ->where('category', 'feed')
                    ->where('is_master', true)
                    ->where('is_active', true),
            ],
            'rows.*.location_id' => [
                'required',
                // Validate via join — inventory_locations may belong to the company
                // directly (company_id) OR via farm (farm_id → farms.company_id).
                // Using a plain exists scoped to company_id alone rejects farm-level
                // locations whose company_id column is NULL or set on the farm row.
                function (string $attribute, mixed $value, \Closure $fail) use ($companyId) {
                    $exists = DB::table('inventory_locations as il')
                        ->leftJoin('farms as f', 'f.id', '=', 'il.farm_id')
                        ->where('il.id', $value)
                        ->where('il.is_active', true)
                        ->where(function ($q) use ($companyId) {
                            $q->where('il.company_id', $companyId)
                              ->orWhere('f.company_id', $companyId);
                        })
                        ->exists();

                    if (!$exists) {
                        $fail(__('validation.exists', ['attribute' => 'location']));
                    }
                },
            ],
            'rows.*.qty_kg' => 'required|numeric|min:0.001',
        ]);

        // ── Step 5: All rows inside one atomic transaction ────────
        DB::transaction(function () use ($request, $companyId, $authUser, $lot, $lotId, $animalIds, $headCount) {

            foreach ($request->rows as $index => $row) {

                $masterItemId = (int) $row['inventory_item_id'];
                $locationId   = (int) $row['location_id'];
                $qtyKg        = (float) $row['qty_kg'];

                // ── 5a: Load + lock location-specific stock row ───
                $stockRow = InventoryItem::where('master_item_id', $masterItemId)
                    ->where('location_id', $locationId)
                    ->where('company_id', $companyId)
                    ->where('is_master', false)
                    ->lockForUpdate()
                    ->first();

                if (!$stockRow) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "rows.{$index}.location_id" => __('validation.feed.no_stock_row_at_location'),
                    ]);
                }

                // ── 5b: Hard block — insufficient stock ───────────
                // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §13
                if (bccomp((string) $stockRow->qty_on_hand, (string) $qtyKg, 4) < 0) {
                    $masterItem = InventoryItem::find($masterItemId);
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "rows.{$index}.qty_kg" => __('validation.feed.insufficient_stock', [
                            'item'      => $masterItem?->name_en ?? $masterItem?->name_ar,
                            'available' => $stockRow->qty_on_hand,
                        ]),
                    ]);
                }

                // ── 5c: Read WAC from stock row ───────────────────
                // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
                // WAC is the actual weighted average cost at this location.
                $unitCost = (string) $stockRow->unit_cost;

                // ── 5d: Compute total_cost via bcmath ─────────────
                // 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#39)
                $totalCost = (float) \App\Support\Money::round(bcmul((string) $qtyKg, $unitCost, 4), 2);

                // ── 5e: Decrement stock ───────────────────────────
                // Capture qty_after BEFORE decrement for the transaction record
                $qtyAfter = (float) bcadd(
                    bcsub((string) $stockRow->qty_on_hand, (string) $qtyKg, 4),
                    '0', 3
                );
                $stockRow->decrement('qty_on_hand', $qtyKg);

                // ── 5f: Create FeedConsumption record ─────────────
                // 📌 FeedConsumptionAnimalSelection
                // head_count = count(animal_ids) — server-computed, not user input
                $fc = FeedConsumption::create([
                    'company_id'        => $companyId,
                    'farm_id'           => $request->farm_id,
                    'lot_id'            => $lotId,
                    'inventory_item_id' => $masterItemId,
                    'location_id'       => $locationId,
                    'feed_item_id'      => null,             // retired — null for new records
                    'ration_id'         => null,             // retired — null for new records
                    'date'              => $request->date,
                    'head_count'        => $headCount,       // SERVER-COMPUTED from count(animal_ids)
                    'qty_kg'            => $qtyKg,
                    'unit_cost'         => (float) $unitCost,
                    'total_cost'        => $totalCost,
                    'planned_qty_kg'    => null,             // ration concept retired
                    'variance_kg'       => null,             // ration concept retired
                    'notes'             => $request->notes ?? null,
                    'recorded_by'       => $authUser->id,
                ]);

                // ── 5g: Insert pivot rows — feed_consumption_animals ──
                // 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
                // One row per animal per feed item row in the session.
                // This pivot is what enables per-animal COGS accuracy at sale time.
                // LivestockIntelligenceService reads this table to determine
                // whether a specific animal participated in this session.
                $now       = now();
                $pivotRows = [];
                foreach ($animalIds as $animalId) {
                    $pivotRows[] = [
                        'feed_consumption_id' => $fc->id,
                        'animal_id'           => $animalId,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }
                DB::table('feed_consumption_animals')->insert($pivotRows);

                // ── 5h: Write InventoryTransaction (type=consumption) ──
                // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §8
                // System-created only — never created manually for feed
                InventoryTransaction::create([
                    'company_id'  => $companyId,
                    'farm_id'     => $lot->farm_id,
                    'location_id' => $locationId,
                    'item_id'     => $stockRow->id,
                    'type'        => 'consumption',
                    'date'        => $request->date,
                    'qty'         => $qtyKg,
                    'unit_cost'   => (float) $unitCost,
                    'total'       => $totalCost,
                    'qty_after'   => $qtyAfter,
                    'wac_after'   => (float) $unitCost,   // WAC unchanged on consumption
                    'ref_type'    => 'feed_consumption',
                    'ref_id'      => $fc->id,
                    'recorded_by' => $authUser->id,
                    'notes'       => 'Feed session — Lot #' . $lotId . ' — ' . $request->date,
                ]);

                // ── 5i: Post T2 journal entry ─────────────────────
                // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
                // Dr Livestock WIP (1310) [lot_id stamped]
                // Cr Feed Inventory (1320)
                $je = $this->postFeedConsumptionJournalEntry(
                    $fc,
                    $companyId,
                    $totalCost,
                    $request->date,
                    $lotId
                );

                // ── 5j: Write journal_entry_id back ──────────────
                if ($je) {
                    $fc->update(['journal_entry_id' => $je->id]);
                }
            }
        });

        return back()->with('success', __('messages.feed_consumption_batch_recorded'));
    }

    // ── Store single record ───────────────────────────────────────
    // Thin wrapper — single-row batch is the canonical path.
    // Kept for backward-compatible API usage.
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create feed'),
            403
        );

        $request->merge([
            'rows' => [[
                'inventory_item_id' => $request->input('inventory_item_id'),
                'location_id'       => $request->input('location_id'),
                'qty_kg'            => $request->input('qty_kg'),
            ]],
        ]);

        return $this->batchStore($request);
    }

    // ── Update ────────────────────────────────────────────────────
    //
    // The edit panel in Feed/Consumption/Index.vue calls this route, but the method
    // was missing — every save ended in a 500 error page.
    //
    // Rules (same philosophy as destroy()):
    //   • LEGACY records (no inventory item) → date, quantity, costs and notes may be
    //     edited: there is no stock or journal entry behind them.
    //   • INVENTORY-LINKED records → stock, WAC cost, the animal selection (COGS) and a
    //     posted T2 journal entry stand behind them, so everything except the notes is
    //     locked. To correct a quantity the user deletes the record (stock is restored)
    //     and records it again.
    public function update(Request $request, FeedConsumption $feedConsumption)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit feed'),
            403
        );

        $this->authorizeLot($authUser, $feedConsumption->lot_id);

        $this->sanitizeNullableFields($request);

        $validated = $request->validate([
            'date'       => 'nullable|date',
            'qty_kg'     => 'nullable|numeric|min:0',
            'unit_cost'  => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'notes'      => 'nullable|string',
        ]);

        if ($feedConsumption->isLegacy()) {
            $feedConsumption->update(array_filter([
                'date'       => $validated['date'] ?? null,
                'qty_kg'     => $validated['qty_kg'] ?? null,
                'unit_cost'  => $validated['unit_cost'] ?? null,
                'total_cost' => $validated['total_cost'] ?? null,
            ], fn ($v) => $v !== null) + [
                'notes' => $validated['notes'] ?? null,
            ]);

            return back()->with('success', 'تم الحفظ / Saved');
        }

        // Inventory-linked: refuse any change to the locked fields.
        $changed = [];
        if (isset($validated['date']) && $validated['date'] !== $feedConsumption->date?->toDateString()) {
            $changed['date'] = true;
        }
        if (isset($validated['qty_kg'])
            && bccomp((string) $validated['qty_kg'], (string) $feedConsumption->qty_kg, 3) !== 0) {
            $changed['qty_kg'] = true;
        }
        foreach (['farm_id', 'lot_id', 'inventory_item_id', 'location_id'] as $locked) {
            if ($request->filled($locked) && (int) $request->input($locked) !== (int) $feedConsumption->{$locked}) {
                $changed[$locked] = true;
            }
        }
        if (is_array($request->input('animal_ids'))) {
            $stored = DB::table('feed_consumption_animals')
                ->where('feed_consumption_id', $feedConsumption->id)
                ->pluck('animal_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $sent = collect($request->input('animal_ids'))->map(fn ($v) => (int) $v)->sort()->values()->all();
            if ($stored !== $sent) {
                $changed['animal_ids'] = true;
            }
        }

        if ($changed) {
            $msg = 'لا يمكن تعديل التاريخ أو الكمية أو الصنف أو المخزن أو الحيوانات بعد تسجيل الاستهلاك (المخزون والقيد المحاسبي مرتبطان به). احذف السجل وأعد تسجيله. / '
                 . 'Date, quantity, item, location and animals cannot be changed once consumption is recorded (stock and the journal entry depend on them). Delete the record and enter it again.';
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_map(fn () => $msg, $changed)
            );
        }

        $feedConsumption->update(['notes' => $validated['notes'] ?? null]);

        return back()->with('success', 'تم الحفظ / Saved');
    }

    // ── Destroy ───────────────────────────────────────────────────
    //
    // Deleting a feed consumption record:
    //   1. Restore inventory stock (increment qty back)
    //   2. Delete feed_consumption_animals pivot rows (cascade handles this)
    //   3. Delete linked InventoryTransaction
    //   4. Reverse posted T2 journal entry (or delete if draft)
    //   5. Delete the FeedConsumption record
    //
    // Cascade delete on feed_consumption_animals means pivot rows are
    // automatically removed when the FeedConsumption row is deleted.
    //
    public function destroy(Request $request, FeedConsumption $feedConsumption)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete feed'),
            403
        );

        $this->authorizeLot($authUser, $feedConsumption->lot_id);

        // Legacy records (no inventory_item_id) — simple delete, no stock restore
        if (!$feedConsumption->inventory_item_id) {
            $feedConsumption->delete();
            return back()->with('success', __('messages.feed_consumption_deleted'));
        }

        // Guard: posted journal entry blocks delete — must be reversed first
        if ($feedConsumption->journal_entry_id) {
            $je = JournalEntry::find($feedConsumption->journal_entry_id);
            if ($je && $je->status === 'posted') {
                return back()->withErrors([
                    'delete' => __('validation.feed.posted_je_blocks_delete'),
                ]);
            }
        }

        DB::transaction(function () use ($feedConsumption) {

            // Restore inventory stock row
            if ($feedConsumption->inventory_item_id && $feedConsumption->location_id) {
                $stockRow = InventoryItem::where('master_item_id', $feedConsumption->inventory_item_id)
                    ->where('location_id', $feedConsumption->location_id)
                    ->where('is_master', false)
                    ->lockForUpdate()
                    ->first();

                if ($stockRow) {
                    $stockRow->increment('qty_on_hand', (float) $feedConsumption->qty_kg);
                }
            }

            // Delete linked InventoryTransaction
            InventoryTransaction::where('ref_type', 'feed_consumption')
                ->where('ref_id', $feedConsumption->id)
                ->delete();

            // Delete draft journal entry if exists
            if ($feedConsumption->journal_entry_id) {
                $je = JournalEntry::find($feedConsumption->journal_entry_id);
                if ($je && $je->status === 'draft') {
                    $je->lines()->delete();
                    $je->delete();
                }
            }

            // feed_consumption_animals pivot rows are removed automatically
            // via CASCADE DELETE defined in Migration 060.
            $feedConsumption->delete();
        });

        return back()->with('success', __('messages.feed_consumption_deleted'));
    }

    // ── Private: post T2 journal entry ───────────────────────────
    //
    // 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §11
    //
    // T2: Dr Livestock WIP (1310) [lot_id stamped on Dr line]
    //     Cr Feed Inventory (1320)
    //
    // Soft-fails with session warning if:
    //   - Account 1310 or 1320 not found in CoA
    //   - No open fiscal year covers the consumption date
    //
    // Returns the JournalEntry on success, null on soft-fail.
    private function postFeedConsumptionJournalEntry(
        FeedConsumption $fc,
        int             $companyId,
        float           $totalCost,
        string          $date,
        int             $lotId
    ): ?JournalEntry {

        $wipAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1310%')
            ->where('allow_direct_posting', true)
            ->first();

        $feedAccount = Account::where('company_id', $companyId)
            ->where('code', 'like', '1320%')
            ->where('allow_direct_posting', true)
            ->first();

        if (!$wipAccount || !$feedAccount) {
            session()->flash('warning', __('messages.feed_je_accounts_missing'));
            return null;
        }

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning', __('messages.feed_je_no_fiscal_year'));
            return null;
        }

        $period = (int) date('n', strtotime($date));

        // 📌 BcmathMonetaryPrecision (#39)
        $amount = (float) \App\Support\Money::round((string) $totalCost, 2);

        $je = JournalEntry::create([
            'company_id'     => $companyId,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $date,
            'period'         => $period,
            'reference'      => 'FEED-' . now()->format('YmdHis'),
            'description'    => 'Feed consumption — Lot #' . $lotId . ' — ' . $date,
            'entry_type'     => 'feed_consumption',
            'status'         => 'posted',
            'total_debit'    => $amount,
            'total_credit'   => $amount,
            'posted_by'      => auth()->id(),
            'posted_at'      => now(),
            'source_type'    => FeedConsumption::class,
            'source_id'      => $fc->id,
        ]);

        // Dr Livestock WIP (1310) — lot_id stamped for cost-centre drill-down
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $wipAccount->id,
            'debit'      => $amount,
            'credit'     => 0,
            'lot_id'     => $lotId,    // 📌 required per Methodology #43 §9
            'animal_id'  => null,      // feed is lot-level, not per-animal
            'memo'       => 'Feed consumption — ' . $date,
        ]);

        // Cr Feed Inventory (1320)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $feedAccount->id,
            'debit'      => 0,
            'credit'     => $amount,
            'lot_id'     => $lotId,
            'animal_id'  => null,
            'memo'       => 'Feed consumed from inventory',
        ]);

        return $je;
    }

    // ── Private: format record ────────────────────────────────────
    // Handles both new records (inventory_item_id set) and legacy
    // records (feed_item_id set, inventory_item_id null).
    private function formatRecord(FeedConsumption $record): array
    {
        $isLegacy = $record->isLegacy();

        return [
            'id'                 => $record->id,
            'lot_id'             => $record->lot_id,
            'lot'                => $record->lot
                ? ['id' => $record->lot->id, 'name' => $record->lot->name]
                : null,
            'farm_id'            => $record->farm_id,
            'farm'               => $record->farm
                ? ['id' => $record->farm->id, 'name_en' => $record->farm->name_en, 'name_ar' => $record->farm->name_ar]
                : null,
            // New fields
            'inventory_item_id'  => $record->inventory_item_id,
            'inventory_item'     => $record->inventoryItem
                ? ['id' => $record->inventoryItem->id, 'name_en' => $record->inventoryItem->name_en, 'name_ar' => $record->inventoryItem->name_ar, 'unit' => $record->inventoryItem->unit]
                : null,
            'location_id'        => $record->location_id,
            'location'           => $record->location
                ? ['id' => $record->location->id, 'name_en' => $record->location->name_en, 'name_ar' => $record->location->name_ar]
                : null,
            // Legacy fields — shown for historical records only
            'is_legacy'          => $isLegacy,
            'feed_item_id'       => $isLegacy ? $record->feed_item_id : null,
            'feed_item'          => ($isLegacy && $record->feedItem)
                ? ['id' => $record->feedItem->id, 'name_en' => $record->feedItem->name_en, 'name_ar' => $record->feedItem->name_ar, 'unit' => $record->feedItem->unit]
                : null,
            // Shared fields
            'date'               => $record->date->toDateString(),
            'head_count'         => $record->head_count,
            'qty_kg'             => $record->qty_kg,
            'unit_cost'          => $record->unit_cost,
            'total_cost'         => $record->total_cost,
            'planned_qty_kg'     => $record->planned_qty_kg,
            'variance_kg'        => $record->variance_kg,
            'journal_entry_id'   => $record->journal_entry_id,
            'notes'              => $record->notes,
            'created_at'         => $record->created_at?->format('d M Y'),
            'animal_ids'         => DB::table('feed_consumption_animals')
                                       ->where('feed_consumption_id', $record->id)
                                       ->pluck('animal_id')
                                       ->toArray(),
        ];
    }

    // ── Private: data helpers ─────────────────────────────────────

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function availableLots($authUser): \Illuminate\Support\Collection
    {
        $base = $authUser->is_super_admin
            ? Lot::where('status', 'active')
            : Lot::whereHas('farm', fn($q) => $q->where('company_id', $authUser->company_id))
                ->where('status', 'active');

        return $base->withCount([
            'animals as active_head_count' => fn($q) => $q->whereIn('status', ['active', 'quarantine'])
        ])->orderBy('name')->get(['id', 'farm_id', 'name']);
    }

    /**
     * Inventory items with category=feed for the new consumption form.
     * Returns master items only with their location stock breakdown
     * so Vue can filter by selected farm and show available qty per location.
     *
     * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
     */
    private function availableFeedInventoryItems($authUser): \Illuminate\Support\Collection
    {
        $query = InventoryItem::with([
            'locationStocks.location:id,farm_id,name_en,name_ar',
        ])
        ->where('category', 'feed')
        ->where('is_master', true)
        ->where('is_active', true)
        ->orderBy('name_en');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return $query->get()->map(fn($item) => [
            'id'                => $item->id,
            'name_en'           => $item->name_en,
            'name_ar'           => $item->name_ar,
            'unit'              => $item->unit,
            'total_qty_on_hand' => (float) $item->locationStocks->sum('qty_on_hand'),
            'location_stocks'   => $item->locationStocks->map(fn($s) => [
                'stock_id'         => $s->id,
                'location_id'      => $s->location_id,
                'farm_id'          => $s->location?->farm_id,
                'location_name_en' => $s->location?->name_en,
                'location_name_ar' => $s->location?->name_ar,
                'qty_on_hand'      => (float) $s->qty_on_hand,
                'unit_cost'        => (float) $s->unit_cost,
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

    private function authorizeLot($authUser, int $lotId): void
    {
        if ($authUser->is_super_admin) return;
        $lot = Lot::with('farm')->findOrFail($lotId);
        abort_unless((int) $lot->farm->company_id === (int) $authUser->company_id, 403);
    }
   

    private function availableLotsAnimals($authUser): array
    {
        $query = Animal::whereIn('status', ['active', 'quarantine'])
            ->orderBy('tag');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        $grouped = [];
        $query->get(['id', 'lot_id', 'tag', 'breed', 'sex'])->each(function ($a) use (&$grouped) {
            if (!$a->lot_id) return;
            $grouped[$a->lot_id][] = [
                'id'    => $a->id,
                'tag'   => $a->tag,
                'breed' => $a->breed,
                'sex'   => $a->sex,
            ];
        });

        return $grouped;
    }
}  // ← closing brace of the class