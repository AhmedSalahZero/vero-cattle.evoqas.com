<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Location Service
//
//  Owns all multi-location inventory business logic:
//
//  fanOutNewItem()     — when a master item is created, auto-create
//                        a location-stock row (qty=0) for every
//                        active location in the company.
//
//  syncNewLocation()   — when a new location is created, auto-create
//                        a location-stock row (qty=0) for every
//                        existing active master item.
//
//  recordTransfer()    — atomic transfer between two locations.
//                        Creates two InventoryTransaction rows and
//                        updates both stock rows in one DB transaction.
//
//  getStockSummary()   — aggregated stock view for the Index page:
//                        master items with location breakdown.
//
//  syncMasterEdit()    — when master item name/sku/category/unit
//                        is edited, push changes to all location rows.
// ══════════════════════════════════════════════════════════════════

class InventoryLocationService
{
    // ── Fan-out: new master item → create stock rows everywhere ───
    /**
     * Called after a new master InventoryItem is created.
     * Creates one location-stock row per active location in
     * the same company, all with qty_on_hand = 0.
     */
    public function fanOutNewItem(InventoryItem $master): void
    {
        $locations = InventoryLocation::where('company_id', $master->company_id)
            ->where('is_active', true)
            ->get();

        foreach ($locations as $location) {
            InventoryItem::create([
                'company_id'      => $master->company_id,
                'farm_id'         => $location->farm_id,
                'location_id'     => $location->id,
                'master_item_id'  => $master->id,
                'is_master'       => false,
                'category'        => $master->category,
                'unit'            => $master->unit,
                'unit_cost'       => $master->unit_cost,
                'qty_on_hand'     => 0,
                'reorder_point'   => $master->reorder_point,
                'is_active'       => true,
            ]);
        }
    }

    // ── Sync: new location → create stock rows for all items ─────
    /**
     * Called after a new InventoryLocation is created.
     * Creates one location-stock row per active master item
     * in the same company, all with qty_on_hand = 0.
     */
    public function syncNewLocation(InventoryLocation $location): void
    {
        $masters = InventoryItem::where('company_id', $location->company_id)
            ->where('is_master', true)
            ->where('is_active', true)
            ->get();

        foreach ($masters as $master) {
            // Only create if not already exists
            $exists = InventoryItem::where('master_item_id', $master->id)
                ->where('location_id', $location->id)
                ->exists();

            if (!$exists) {
             InventoryItem::create([
                    'company_id'     => $location->company_id,
                    'farm_id'        => $location->farm_id,
                    'location_id'    => $location->id,
                    'master_item_id' => $master->id,
                    'is_master'      => false,
                    'category'       => $master->category,
                    'unit'           => $master->unit,
                    'unit_cost'      => $master->unit_cost,
                    'qty_on_hand'    => 0,
                    'reorder_point'  => $master->reorder_point,
                    'is_active'      => true,
                ]);
            }
        }
    }

    // ── Atomic transfer between two locations ─────────────────────
    /**
     * 📌 METHODOLOGY REFERENCE: InventoryLocationTransfer
     *
     * Steps:
     * 1. Lock both stock rows
     * 2. Validate source has enough qty (hard block)
     * 3. Decrement source, increment destination
     * 4. Create transfer_out transaction on source
     * 5. Create transfer_in  transaction on destination
     * 6. Both share transfer_batch_ref for traceability
     *
     * @param int   $masterItemId      The master item being transferred
     * @param int   $sourceLocationId  From this location
     * @param int   $destLocationId    To this location
     * @param float $qty               Quantity to transfer
     * @param string $date             Transaction date
     * @param int   $recordedBy        User ID
     * @param string|null $notes
     */
    public function recordTransfer(
        int    $masterItemId,
        int    $sourceLocationId,
        int    $destLocationId,
        float  $qty,
        string $date,
        int    $recordedBy,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use (
            $masterItemId, $sourceLocationId, $destLocationId,
            $qty, $date, $recordedBy, $notes
        ) {
            // 📌 Step 1 — Lock both stock rows
            $sourceStock = InventoryItem::lockForUpdate()
                ->where('master_item_id', $masterItemId)
                ->where('location_id', $sourceLocationId)
                ->firstOrFail();

            $destStock = InventoryItem::lockForUpdate()
                ->where('master_item_id', $masterItemId)
                ->where('location_id', $destLocationId)
                ->firstOrFail();

            // 📌 Step 2 — Hard blocks (BUG-T5: both guards run BEFORE any write)
            // qty > 0 must be checked first — a zero/negative qty would pass the
            // stock check (0 < 0 is false) and silently create ghost transactions.
            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    'qty' => __('validation.inventory.transfer_qty_positive'),
                ]);
            }

            // Stock check runs against the locked pre-decrement value.
            // BUG-T5 fix: this must be here, before decrement() is called.
            // The old code had refresh() after decrement which overwrote
            // qty_on_hand with the post-decrement DB value — making the
            // guard compare the remaining balance against qty, not the
            // available balance. A transfer of exactly qty_on_hand always
            // "passed" (remaining = 0, not < qty).
            if ($sourceStock->qty_on_hand < $qty) {
                throw ValidationException::withMessages([
                    'qty' => __('validation.inventory.insufficient_transfer_stock', [
                        'available' => $sourceStock->qty_on_hand,
                        'location'  => $sourceStock->location?->display_label ?? 'source',
                    ]),
                ]);
            }

            // Capture pre-decrement values before any write — used in transaction rows below
            $unitCost         = $sourceStock->unit_cost;
            $companyId        = $sourceStock->company_id;
            $sourceQtyAfter   = (float) bcsub((string) $sourceStock->qty_on_hand, (string) $qty, 4);
            $destQtyAfter     = (float) bcadd((string) $destStock->qty_on_hand,   (string) $qty, 4);
            $total            = (float) bcadd(bcmul((string) $qty, (string) $unitCost, 4), '0', 2);

            // 📌 Step 3 — Update stock (writes happen AFTER all guards pass)
            $sourceStock->decrement('qty_on_hand', $qty);
            $destStock->increment('qty_on_hand', $qty);

            // Batch ref ties the two sides together
            $batchRef = 'TRF-' . strtoupper(Str::random(8));

            // 📌 Step 4 — transfer_out on source
            $outTx = InventoryTransaction::create([
                'company_id'              => $companyId,
                'farm_id'                 => $sourceStock->farm_id,
                'location_id'             => $sourceLocationId,
                'counterpart_location_id' => $destLocationId,
                'item_id'                 => $sourceStock->id,
                'type'                    => 'transfer_out',
                'date'                    => $date,
                'qty'                     => $qty,
                'unit_cost'               => $unitCost,
                'total'                   => $total,
                'qty_after'               => $sourceQtyAfter,
                'transfer_batch_ref'      => $batchRef,
                'recorded_by'             => $recordedBy,
                'notes'                   => $notes,
            ]);

            // 📌 Step 5 — transfer_in on destination
            $inTx = InventoryTransaction::create([
                'company_id'              => $companyId,
                'farm_id'                 => $destStock->farm_id,
                'location_id'             => $destLocationId,
                'counterpart_location_id' => $sourceLocationId,
                'item_id'                 => $destStock->id,
                'type'                    => 'transfer_in',
                'date'                    => $date,
                'qty'                     => $qty,
                'unit_cost'               => $unitCost,
                'total'                   => $total,
                'qty_after'               => $destQtyAfter,
                'transfer_batch_ref'      => $batchRef,
                'recorded_by'             => $recordedBy,
                'notes'                   => $notes,
            ]);

            return ['out' => $outTx, 'in' => $inTx, 'batch_ref' => $batchRef];
        });
    }

    // ── Stock summary for Index page ──────────────────────────────
    /**
     * Returns master items with their location breakdown.
     * Each master item includes:
     *   - total_qty (sum across all locations)
     *   - locations array with individual stock rows
     *   - has_low_stock flag (any location below reorder point)
     *   - has_out_of_stock flag (any location at zero)
     */
    /**
     * @param int|null $companyId  NULL = super admin all-companies view
     * @param array    $filters
     * @param bool     $isSuperAdmin
     * @param int|null $filterCompanyId  optional company filter for super admin
     */
    public function getStockSummary(
        ?int $companyId,
        array $filters = [],
        bool $isSuperAdmin = false,
        ?int $filterCompanyId = null
    ): \Illuminate\Support\Collection {
        $query = InventoryItem::with([
            'locationStocks.location.farm',
        ])
        ->where('is_master', true)
        ->where('is_active', true);

        // Scope by company
        if ($isSuperAdmin) {
            // Super admin: show all companies, or filter to one if selected
            if ($filterCompanyId) {
                $query->where('company_id', $filterCompanyId);
            }
            // No company filter = show everything across all companies
        } else {
            // Regular user: always scoped to their company
            $query->where('company_id', $companyId);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn($q) =>
                $q->where('name_en', 'like', "%{$s}%")
                  ->orWhere('name_ar', 'like', "%{$s}%")
                  ->orWhere('sku', 'like', "%{$s}%")
            );
        }

        return $query->orderBy('name_en')->get()->map(function (InventoryItem $master) use ($filters) {
            $stocks = $master->locationStocks;

            // Apply location/farm filter at this level
            if (!empty($filters['location_id'])) {
                $stocks = $stocks->where('location_id', $filters['location_id']);
            }
            if (!empty($filters['farm_id'])) {
                $stocks = $stocks->filter(fn($s) =>
                    $s->location && $s->location->farm_id == $filters['farm_id']
                );
            }

            $totalQty    = $stocks->sum('qty_on_hand');
            $hasLowStock = $stocks->contains(fn($s) =>
                $s->reorder_point && $s->qty_on_hand <= $s->reorder_point
            );
            $hasOutOfStock = $stocks->contains(fn($s) => $s->qty_on_hand <= 0);

            // low_stock filter — skip master if no low-stock locations
            if (!empty($filters['low_stock']) && $filters['low_stock']) {
                if (!$hasLowStock) return null;
            }

            return [
                'id'              => $master->id,
                'company_id'      => $master->company_id,
                'name_en'         => $master->name_en,
                'name_ar'         => $master->name_ar,
                'sku'             => $master->sku,
                'category'        => $master->category,
                'unit'            => $master->unit,
                'is_active'       => $master->is_active,
                'notes'           => $master->notes,
                'total_qty'       => (float) $totalQty,
                'has_low_stock'   => $hasLowStock,
                'has_out_of_stock'=> $hasOutOfStock,
                'locations'       => $stocks->map(fn($s) => [
                    'stock_id'       => $s->id,
                    'location_id'    => $s->location_id,
                    'location_name_en' => $s->location?->name_en,
                    'location_name_ar' => $s->location?->name_ar,
                    'farm_id'        => $s->location?->farm_id,
                    'farm_name_en'   => $s->location?->farm?->name_en,
                    'farm_name_ar'   => $s->location?->farm?->name_ar,
                    'is_central'     => $s->location?->farm_id === null,
                    'qty_on_hand'    => (float) $s->qty_on_hand,
                    'unit_cost'      => (float) $s->unit_cost,
                    'reorder_point'  => $s->reorder_point ? (float) $s->reorder_point : null,
                    'is_low_stock'   => $s->is_low_stock,
                    'is_out_of_stock'=> $s->qty_on_hand <= 0,
                ])->values(),
            ];
        })->filter()->values();
    }

    // ── Sync master edits to all location rows ────────────────────
    /**
     * When user edits master item name/category/unit —
     * those changes propagate to all location-stock rows.
     * Cost and reorder_point stay per-location (not synced).
     */
    public function syncMasterEdit(InventoryItem $master): void
    {
        InventoryItem::where('master_item_id', $master->id)
            ->update([
                'is_active' => $master->is_active,
                // name/sku/category/unit live only on master,
                // location rows read from master via relationship.
                // Only is_active needs syncing here.
            ]);
    }
}