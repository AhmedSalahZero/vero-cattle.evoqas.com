<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\FeedMixingBatch;
use App\Models\FeedMixingLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Services\FeedMixingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — FeedMixingController
//
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//
//  Thin controller: validates, scopes to the company, calls
//  FeedMixingService. NO cost / stock / journal maths lives here.
//
//  Permissions: reuses the existing "feed" module permissions
//    view feed   → list + view
//    create feed → new batch
//    edit feed   → edit draft, start, finish, cancel
//    delete feed → delete draft
// ══════════════════════════════════════════════════════════════════

class FeedMixingController extends Controller
{
    public function __construct(private FeedMixingService $service) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('view feed'), 403);

        $companyId = $this->resolveCompanyId($request);

        $query = FeedMixingBatch::with([
                'farm:id,name_en,name_ar',
                'location:id,name_en,name_ar',
                'outputItem:id,name_en,name_ar,unit',
            ])
            ->where('company_id', $companyId);

        if ($request->filled('status'))  $query->where('status', $request->status);
        if ($request->filled('farm_id')) $query->where('farm_id', $request->farm_id);

        $batches = $query->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $counts = FeedMixingBatch::where('company_id', $companyId)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->pluck('c', 'status');

        return Inertia::render('FeedMixing/Index', [
            'batches' => [
                'data'  => $batches->getCollection()->map(fn ($b) => $this->formatBatch($b))->values(),
                'links' => $batches->linkCollection(),
                'total' => $batches->total(),
            ],
            'counts'  => [
                'draft'       => (int) ($counts['draft'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed'   => (int) ($counts['completed'] ?? 0),
                'cancelled'   => (int) ($counts['cancelled'] ?? 0),
            ],
            'farms'   => $this->farms($companyId),
            'filters' => $request->only(['status', 'farm_id']),
            'can'     => $this->can($authUser),
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('create feed'), 403);

        $companyId = $this->resolveCompanyId($request);

        // "Copy previous batch" — pre-fills the form with an earlier recipe
        $copy = null;
        if ($request->filled('copy')) {
            $source = FeedMixingBatch::with('lines')->find($request->copy);
            if ($source && (int) $source->company_id === $companyId) {
                $copy = [
                    'farm_id'        => $source->farm_id,
                    'location_id'    => $source->location_id,
                    'output_item_id' => $source->output_item_id,
                    'lines'          => $source->lines->map(fn ($l) => [
                        'item_id' => $l->item_id,
                        'qty'     => (string) $l->qty,
                    ])->values(),
                ];
            }
        }

        return Inertia::render('FeedMixing/CreateEdit', array_merge(
            ['mode' => 'create', 'batch' => null, 'copy' => $copy],
            $this->formData($companyId)
        ));
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('create feed'), 403);

        $companyId = $this->resolveCompanyId($request);
        $this->sanitizeNullableFields($request);
        $validated = $this->validateBatch($request, $companyId);

        $batch = DB::transaction(function () use ($validated, $companyId, $authUser) {
            $batch = FeedMixingBatch::create([
                'company_id'     => $companyId,
                'farm_id'        => $validated['farm_id'],
                'location_id'    => $validated['location_id'],
                'output_item_id' => $validated['output_item_id'],
                'reference'      => $validated['reference'] ?? null,
                'status'         => 'draft',
                'start_date'     => $validated['start_date'],
                'notes'          => $validated['notes'] ?? null,
                'created_by'     => $authUser->id,
            ]);

            if (empty($batch->reference)) {
                $batch->update(['reference' => 'MIX-' . str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT)]);
            }

            $this->syncLines($batch, $validated['lines']);
            return $batch;
        });

        return redirect()->route('feed.mixing.show', $batch)
            ->with('success', 'Feed mixing batch created · تم إنشاء دفعة التصنيع');
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('view feed'), 403);

        $companyId = $this->resolveCompanyId($request);
        $this->authorizeBatch($feedMixingBatch, $companyId);

        $feedMixingBatch->load([
            'farm:id,name_en,name_ar',
            'location:id,name_en,name_ar',
            'outputItem:id,name_en,name_ar,unit',
            'lines.item:id,name_en,name_ar,unit,category',
            'startJournalEntry:id,reference',
            'finishJournalEntry:id,reference',
            'cancelJournalEntry:id,reference',
            'creator:id,name',
        ]);

        return Inertia::render('FeedMixing/Show', [
            'batch' => $this->formatBatch($feedMixingBatch, true),
            'can'   => $this->can($authUser),
            'today' => now()->toDateString(),
        ]);
    }

    // ── Edit form (draft only) ────────────────────────────────────
    public function edit(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('edit feed'), 403);

        $companyId = $this->resolveCompanyId($request);
        $this->authorizeBatch($feedMixingBatch, $companyId);
        abort_unless($feedMixingBatch->status === 'draft', 422, 'Only a draft batch can be edited.');

        $feedMixingBatch->load('lines.item:id,name_en,name_ar,unit,category');

        return Inertia::render('FeedMixing/CreateEdit', array_merge(
            ['mode' => 'edit', 'batch' => $this->formatBatch($feedMixingBatch, true), 'copy' => null],
            $this->formData($companyId)
        ));
    }

    // ── Update (draft only) ───────────────────────────────────────
    public function update(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('edit feed'), 403);

        $companyId = $this->resolveCompanyId($request);
        $this->authorizeBatch($feedMixingBatch, $companyId);

        $this->sanitizeNullableFields($request);
        $validated = $this->validateBatch($request, $companyId);

        DB::transaction(function () use ($feedMixingBatch, $validated) {
            $fresh = FeedMixingBatch::lockForUpdate()->findOrFail($feedMixingBatch->id);
            abort_unless($fresh->status === 'draft', 422, 'Only a draft batch can be edited.');

            $fresh->update([
                'farm_id'        => $validated['farm_id'],
                'location_id'    => $validated['location_id'],
                'output_item_id' => $validated['output_item_id'],
                'reference'      => $validated['reference'] ?: $fresh->reference,
                'start_date'     => $validated['start_date'],
                'notes'          => $validated['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $validated['lines']);
        });

        return redirect()->route('feed.mixing.show', $feedMixingBatch)
            ->with('success', 'Feed mixing batch updated · تم تحديث الدفعة');
    }

    // ── Destroy (draft only) ──────────────────────────────────────
    public function destroy(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('delete feed'), 403);

        $companyId = $this->resolveCompanyId($request);
        $this->authorizeBatch($feedMixingBatch, $companyId);

        DB::transaction(function () use ($feedMixingBatch) {
            $fresh = FeedMixingBatch::lockForUpdate()->findOrFail($feedMixingBatch->id);
            abort_unless(
                $fresh->status === 'draft',
                422,
                'Only a draft batch can be deleted. Started batches must be cancelled instead.'
            );
            $fresh->delete();   // lines cascade
        });

        return redirect()->route('feed.mixing.index')
            ->with('success', 'Feed mixing batch deleted · تم حذف الدفعة');
    }

    // ── Start ─────────────────────────────────────────────────────
    public function start(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('edit feed'), 403);

        $this->authorizeBatch($feedMixingBatch, $this->resolveCompanyId($request));

        $this->service->start($feedMixingBatch);

        return back()->with('success', 'Mixing started — ingredients moved to WIP · بدأ الخلط وتم نقل المكونات إلى قيد التشغيل');
    }

    // ── Finish ────────────────────────────────────────────────────
    public function finish(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('edit feed'), 403);

        $this->authorizeBatch($feedMixingBatch, $this->resolveCompanyId($request));

        $this->sanitizeNullableFields($request);
        $validated = $request->validate([
            'finished_qty_kg' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'finish_date'     => ['nullable', 'date'],
        ]);

        $this->service->finish(
            $feedMixingBatch,
            (string) $validated['finished_qty_kg'],
            $validated['finish_date'] ?? null
        );

        return back()->with('success', 'Mixing finished — mix added to inventory · اكتمل الخلط وأُضيف العلف المخلوط للمخزون');
    }

    // ── Cancel ────────────────────────────────────────────────────
    public function cancel(Request $request, FeedMixingBatch $feedMixingBatch)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin || $authUser->hasPermissionTo('edit feed'), 403);

        $this->authorizeBatch($feedMixingBatch, $this->resolveCompanyId($request));

        $this->sanitizeNullableFields($request);
        $validated = $request->validate(['cancel_date' => ['nullable', 'date']]);

        $this->service->cancel($feedMixingBatch, $validated['cancel_date'] ?? null);

        return back()->with('success', 'Batch cancelled — ingredients returned to stock · أُلغيت الدفعة وأُعيدت المكونات للمخزون');
    }

    // ══════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════

    private function authorizeBatch(FeedMixingBatch $batch, int $companyId): void
    {
        abort_unless((int) $batch->company_id === $companyId, 403);
    }

    private function can($authUser): array
    {
        $su = (bool) $authUser->is_super_admin;
        return [
            'create' => $su || $authUser->hasPermissionTo('create feed'),
            'edit'   => $su || $authUser->hasPermissionTo('edit feed'),
            'delete' => $su || $authUser->hasPermissionTo('delete feed'),
        ];
    }

    private function validateBatch(Request $request, int $companyId): array
    {
        $validated = $request->validate([
            'farm_id' => ['required', 'integer',
                Rule::exists('farms', 'id')->where('company_id', $companyId)],
            'location_id' => ['required', 'integer',
                Rule::exists('inventory_locations', 'id')->where('company_id', $companyId)],
            'output_item_id' => ['required', 'integer',
                Rule::exists('inventory_items', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_master', true)
                    ->where('category', 'feed')],
            'reference'  => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'notes'      => ['nullable', 'string', 'max:2000'],

            'lines'               => ['required', 'array', 'min:1', 'max:40'],
            'lines.*.item_id'     => ['required', 'integer', 'distinct',
                Rule::exists('inventory_items', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_master', true)],
            'lines.*.qty'         => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ]);

        // The location must belong to the chosen farm, or be a central warehouse
        $location = InventoryLocation::where('company_id', $companyId)->find($validated['location_id']);
        if ($location && $location->farm_id !== null
            && (int) $location->farm_id !== (int) $validated['farm_id']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'location_id' => 'This location belongs to a different farm.',
            ]);
        }

        // A mix cannot be an ingredient of itself
        foreach ($validated['lines'] as $i => $line) {
            if ((int) $line['item_id'] === (int) $validated['output_item_id']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "lines.{$i}.item_id" => 'The finished mix cannot be one of its own ingredients.',
                ]);
            }
        }

        return $validated;
    }

    /** Replace all ingredient lines of a draft batch. Costs are NOT set here — start() sets them. */
    private function syncLines(FeedMixingBatch $batch, array $lines): void
    {
        FeedMixingLine::where('batch_id', $batch->id)->delete();

        foreach ($lines as $line) {
            FeedMixingLine::create([
                'batch_id' => $batch->id,
                'item_id'  => (int) $line['item_id'],
                'qty'      => bcadd((string) $line['qty'], '0', 3),
            ]);
        }
    }

    private function farms(int $companyId)
    {
        return Farm::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name_en')->get(['id', 'name_en', 'name_ar']);
    }

    /** Everything the form needs to build its dropdowns. */
    private function formData(int $companyId): array
    {
        $locations = InventoryLocation::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name_en')->get(['id', 'farm_id', 'name_en', 'name_ar']);

        $masters = InventoryItem::with('locationStocks:id,master_item_id,location_id,qty_on_hand,unit_cost')
            ->where('company_id', $companyId)
            ->where('is_master', true)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'unit', 'category']);

        $items = $masters->map(fn ($m) => [
            'id'       => $m->id,
            'name_en'  => $m->name_en,
            'name_ar'  => $m->name_ar,
            'unit'     => $m->unit,
            'category' => $m->category,
            'stocks'   => $m->locationStocks->map(fn ($s) => [
                'location_id' => $s->location_id,
                'qty'         => (string) $s->qty_on_hand,
                'unit_cost'   => (string) $s->unit_cost,
            ])->values(),
        ])->values();

        return [
            'farms'     => $this->farms($companyId),
            'locations' => $locations,
            'items'     => $items,                                            // every ingredient candidate
            'mixItems'  => $items->where('category', 'feed')->values(),       // possible finished mixes
        ];
    }

    private function formatBatch(FeedMixingBatch $b, bool $full = false): array
    {
        $row = [
            'id'                  => $b->id,
            'reference'           => $b->reference,
            'status'              => $b->status,
            'start_date'          => $b->start_date?->toDateString(),
            'finish_date'         => $b->finish_date?->toDateString(),
            'farm_id'             => $b->farm_id,
            'farm_name_en'        => $b->farm?->name_en,
            'farm_name_ar'        => $b->farm?->name_ar,
            'location_id'         => $b->location_id,
            'location_name_en'    => $b->location?->name_en,
            'location_name_ar'    => $b->location?->name_ar,
            'output_item_id'      => $b->output_item_id,
            'output_name_en'      => $b->outputItem?->name_en,
            'output_name_ar'      => $b->outputItem?->name_ar,
            'output_unit'         => $b->outputItem?->unit,
            'total_cost'          => (string) $b->total_cost,
            'total_ingredient_kg' => (string) $b->total_ingredient_kg,
            'finished_qty_kg'     => $b->finished_qty_kg !== null ? (string) $b->finished_qty_kg : null,
            'cost_per_kg'         => $b->cost_per_kg !== null ? (string) $b->cost_per_kg : null,
            'notes'               => $b->notes,
        ];

        if ($full) {
            $row['start_je']  = $b->startJournalEntry?->only(['id', 'reference']);
            $row['finish_je'] = $b->finishJournalEntry?->only(['id', 'reference']);
            $row['cancel_je'] = $b->cancelJournalEntry?->only(['id', 'reference']);
            $row['created_by_name'] = $b->creator?->name;
            $row['lines'] = $b->lines->map(fn ($l) => [
                'id'           => $l->id,
                'item_id'      => $l->item_id,
                'name_en'      => $l->item?->name_en,
                'name_ar'      => $l->item?->name_ar,
                'unit'         => $l->item?->unit,
                'category'     => $l->item?->category,
                'qty'          => (string) $l->qty,
                'unit_cost'    => $l->unit_cost !== null ? (string) $l->unit_cost : null,
                'total_cost'   => $l->total_cost !== null ? (string) $l->total_cost : null,
                'account_code' => $l->account_code,
            ])->values();
        }

        return $row;
    }
}
