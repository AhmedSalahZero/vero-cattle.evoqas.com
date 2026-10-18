<?php

namespace App\Http\Controllers;

use App\Models\FeedItem;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Feed Item Controller
//  Manages the feed catalog. Nutritional calculations (CP, TDN,
//  DM totals for rations) live in FeedNutritionService.
//  This controller handles catalog CRUD only.
// ══════════════════════════════════════════════════════════════════

class FeedItemController extends Controller
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

        $query = FeedItem::with('supplier:id,name_en,name_ar');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('type')) $query->where('type', $request->type);

        $items = $query->where('is_active', true)->orderBy('name_en')->get();

        return Inertia::render('Feed/Items/Index', [
            'items'     => $items->map(fn($i) => $this->formatItem($i)),
            'suppliers' => $this->availableSuppliers($authUser),
            'filters'   => $request->only(['type']),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create feed'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $validated = $this->validateItem($request);

        FeedItem::create([
            ...$validated,
            'company_id' => $companyId,
        ]);

        return back()->with('success', __('messages.feed_item_created'));
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, FeedItem $feedItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit feed'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $feedItem->company_id === (int) $authUser->company_id, 403);
        }

        $validated = $this->validateItem($request, $feedItem->id);
        $feedItem->update($validated);

        return back()->with('success', __('messages.feed_item_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, FeedItem $feedItem)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete feed'),
            403
        );

        if (!$authUser->is_super_admin) {
            abort_unless((int) $feedItem->company_id === (int) $authUser->company_id, 403);
        }

        // Soft-deactivate instead of hard delete to preserve consumption history
        $feedItem->update(['is_active' => false]);

        return back()->with('success', __('messages.feed_item_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateItem(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name_en'     => 'required|string|max:255',
            'name_ar'     => 'nullable|string|max:255',
            'type'        => ['required', Rule::in(['roughage', 'concentrate', 'supplement', 'mineral', 'other'])],
            'unit'        => 'required|string|max:50',
            'unit_cost'   => 'required|numeric|min:0',
            'cp_pct'      => 'nullable|numeric|min:0|max:100',
            'tdn_pct'     => 'nullable|numeric|min:0|max:100',
            'dm_pct'      => 'nullable|numeric|min:0|max:100',
            'ndf_pct'     => 'nullable|numeric|min:0|max:100',
            'me_mj'       => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'is_active'   => 'boolean',
            'notes'       => 'nullable|string',
        ]);
    }

    private function formatItem(FeedItem $item): array
    {
        return [
            'id'          => $item->id,
            'name_en'     => $item->name_en,
            'name_ar'     => $item->name_ar,
            'type'        => $item->type,
            'unit'        => $item->unit,
            'unit_cost'   => $item->unit_cost,
            'cp_pct'      => $item->cp_pct,
            'tdn_pct'     => $item->tdn_pct,
            'dm_pct'      => $item->dm_pct,
            'ndf_pct'     => $item->ndf_pct,
            'me_mj'       => $item->me_mj,
            'supplier_id' => $item->supplier_id,
            'supplier'    => $item->supplier ? [
                'id'      => $item->supplier->id,
                'name_en' => $item->supplier->name_en,
                'name_ar' => $item->supplier->name_ar,
            ] : null,
            'is_active'   => $item->is_active,
            'notes'       => $item->notes,
        ];
    }

    private function availableSuppliers($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Supplier::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Supplier::where('company_id', $authUser->company_id)->where('is_active', true)
                ->orderBy('name_en')->get(['id', 'name_en', 'name_ar']);
    }
}