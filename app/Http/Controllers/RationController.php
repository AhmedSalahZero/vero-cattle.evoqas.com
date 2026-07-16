<?php

namespace App\Http\Controllers;

use App\Models\Ration;
use App\Models\RationLine;
use App\Models\FeedItem;
use App\Models\Lot;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Ration Controller
//  Manages ration formulations per lot. Nutritional totals
//  (CP%, TDN%, DM kg/head/day, cost/head/day) are calculated
//  by FeedNutritionService — not in this controller.
//  Controller stores the ration definition and its lines.
// ══════════════════════════════════════════════════════════════════

class RationController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view rations'),
            403
        );

        $query = Ration::with([
            'lot:id,name',
            'farm:id,name_en,name_ar',
            'lines.feedItem:id,name_en,name_ar,unit,unit_cost',
        ])->withCount('lines');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('lot_id'))  $query->where('lot_id', $request->lot_id);
        if ($request->filled('farm_id')) $query->where('farm_id', $request->farm_id);

        $rations = $query->orderBy('effective_date', 'desc')->get();

        return Inertia::render('Feed/Rations/Index', [
            'rations'   => $rations->map(fn($r) => $this->formatRation($r)),
            'farms'     => $this->availableFarms($authUser),
            'lots'      => $this->availableLots($authUser),
            'feedItems' => $this->availableFeedItems($authUser),
            'filters'   => $request->only(['lot_id', 'farm_id']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create rations'),
            403
        );

        $validated = $this->validateRation($request);
        $this->authorizeLot($authUser, $validated['lot_id']);

        $companyId = $authUser->is_super_admin
            ? Lot::find($validated['lot_id'])->farm->company_id
            : $authUser->company_id;

        $ration = Ration::create([
            'company_id'    => $companyId,
            'farm_id'       => $validated['farm_id'],
            'lot_id'        => $validated['lot_id'],
            'name'          => $validated['name'],
            'effective_date'=> $validated['effective_date'],
            'is_active'     => $validated['is_active'] ?? true,
            'notes'         => $validated['notes'] ?? null,
        ]);

        // Store ration lines
        foreach ($validated['lines'] as $line) {
            RationLine::create([
                'ration_id'       => $ration->id,
                'feed_item_id'    => $line['feed_item_id'],
                'qty_per_head_kg' => $line['qty_per_head_kg'],
            ]);
        }

        // Nutritional totals calculated and stored by FeedNutritionService observer

        return back()->with('success', __('messages.ration_created'));
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Ration $ration)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit rations'),
            403
        );

        $this->authorizeLot($authUser, $ration->lot_id);

        $validated = $this->validateRation($request, $ration->id);

        $ration->update([
            'name'           => $validated['name'],
            'effective_date' => $validated['effective_date'],
            'is_active'      => $validated['is_active'] ?? $ration->is_active,
            'notes'          => $validated['notes'] ?? null,
        ]);

        // Replace lines
        $ration->lines()->delete();
        foreach ($validated['lines'] as $line) {
            RationLine::create([
                'ration_id'       => $ration->id,
                'feed_item_id'    => $line['feed_item_id'],
                'qty_per_head_kg' => $line['qty_per_head_kg'],
            ]);
        }

        return back()->with('success', __('messages.ration_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, Ration $ration)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete rations'),
            403
        );

        $this->authorizeLot($authUser, $ration->lot_id);
        $ration->lines()->delete();
        $ration->delete();

        return back()->with('success', __('messages.ration_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateRation(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'farm_id'        => 'required|exists:farms,id',
            'lot_id'         => 'required|exists:lots,id',
            'name'           => 'required|string|max:255',
            'effective_date' => 'required|date',
            'is_active'      => 'boolean',
            'notes'          => 'nullable|string',
            'lines'          => 'required|array|min:1',
            'lines.*.feed_item_id'    => 'required|exists:feed_items,id',
            'lines.*.qty_per_head_kg' => 'required|numeric|min:0.001',
        ]);
    }

    private function formatRation(Ration $ration): array
    {
        return [
            'id'                  => $ration->id,
            'lot_id'              => $ration->lot_id,
            'lot'                 => $ration->lot ? [
                'id'   => $ration->lot->id,
                'name' => $ration->lot->name,
            ] : null,
            'farm_id'             => $ration->farm_id,
            'farm'                => $ration->farm ? [
                'id'      => $ration->farm->id,
                'name_en' => $ration->farm->name_en,
                'name_ar' => $ration->farm->name_ar,
            ] : null,
            'name'                => $ration->name,
            'effective_date'      => $ration->effective_date->toDateString(),
            'is_active'           => $ration->is_active,
            'cost_per_head_day'   => $ration->cost_per_head_day,
            'total_cp_pct'        => $ration->total_cp_pct,
            'total_tdn_pct'       => $ration->total_tdn_pct,
            'total_dm_kg'         => $ration->total_dm_kg,
            'notes'               => $ration->notes,
            'lines_count'         => $ration->lines_count ?? 0,
            'lines'               => $ration->relationLoaded('lines')
                ? $ration->lines->map(fn($l) => [
                    'id'              => $l->id,
                    'feed_item_id'    => $l->feed_item_id,
                    'feed_item_name_en' => $l->feedItem?->name_en,
                    'feed_item_name_ar' => $l->feedItem?->name_ar,
                    'qty_per_head_kg' => $l->qty_per_head_kg,
                    'cost_per_head'   => $l->cost_per_head,
                    'cp_contribution' => $l->cp_contribution,
                    'tdn_contribution'=> $l->tdn_contribution,
                ])
                : [],
            'created_at'          => $ration->created_at?->format('d M Y'),
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
            ? Lot::where('status', 'active')->orderBy('name')->get(['id', 'farm_id', 'name'])
            : Lot::whereHas('farm', fn($q) => $q->where('company_id', $authUser->company_id))
                ->where('status', 'active')->orderBy('name')->get(['id', 'farm_id', 'name']);
    }

    private function availableFeedItems($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? FeedItem::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'unit', 'unit_cost', 'cp_pct', 'tdn_pct', 'dm_pct'])
            : FeedItem::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'unit', 'unit_cost', 'cp_pct', 'tdn_pct', 'dm_pct']);
    }

    private function authorizeLot($authUser, int $lotId): void
    {
        if ($authUser->is_super_admin) return;
        $lot = Lot::with('farm')->findOrFail($lotId);
        abort_unless($lot->farm->company_id === $authUser->company_id, 403);
    }
}
