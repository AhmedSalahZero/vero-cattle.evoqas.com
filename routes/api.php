<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
// Breeding / Calving panels — load active animals for a farm
// Used by: Breeding/Index.vue, Calvings/Index.vue
// ══════════════════════════════════════════════════════════════════
Route::middleware('auth:web')->get('/lots-animals', function (Request $request) {
    $user   = auth()->user();
    $farmId = $request->farm_id;
    $lotId  = $request->lot_id;
    $status = $request->status ?? 'active';

    abort_unless($farmId || $lotId, 422);

    $query = \App\Models\Animal::where('status', $status)->orderBy('tag');

    if ($lotId) {
        $query->where('lot_id', $lotId);
    } else {
        $query->where('farm_id', $farmId);
    }

    if (!$user->is_super_admin) {
        $query->where('company_id', $user->company_id);
    }

    return $query->get(['id', 'tag', 'breed', 'sex']);
});

// ══════════════════════════════════════════════════════════════════
// Sales CreateEdit — load animals for sale with weights
// 📌 METHODOLOGY REFERENCE: ROIperLot / BatchPriceDistribution
//
// sale_type = 'live'    → status=active,      weight from weight_records (latest)
// sale_type = 'carcass' → status=slaughtered, weight from slaughter_records.deadweight_kg
//
// Animals with no weight record (live) are returned with has_weight=false
// so Vue can block them from selection and show a warning.
//
// Used by: Sales/CreateEdit.vue
// ══════════════════════════════════════════════════════════════════
Route::middleware('auth:web')->get('/sales-animals', function (Request $request) {
    $user     = auth()->user();
    $farmId   = (int) $request->farm_id;
    $saleType = $request->sale_type ?? 'live'; // 'live' | 'carcass'

    abort_unless($farmId, 422);
    abort_unless(in_array($saleType, ['live', 'carcass']), 422);

    if ($saleType === 'live') {
        // ── Live animals — status=active, weight from weight_records ──
        $query = \App\Models\Animal::where('farm_id', $farmId)
            ->where('status', 'active')
            ->orderBy('tag');

        if (!$user->is_super_admin) {
            $query->where('company_id', $user->company_id);
        }

        $animals = $query->get(['id', 'tag', 'breed', 'sex', 'lot_id']);

        // Attach latest weight per animal via a single subquery join
        // to avoid N+1 — one DB round-trip for all animals
        $animalIds = $animals->pluck('id');

        $latestWeights = DB::table('weight_records as wr')
            ->joinSub(
                DB::table('weight_records')
                    ->select('animal_id', DB::raw('MAX(date) as max_date'))
                    ->whereIn('animal_id', $animalIds)
                    ->groupBy('animal_id'),
                'latest',
                fn($join) => $join
                    ->on('wr.animal_id', '=', 'latest.animal_id')
                    ->on('wr.date', '=', 'latest.max_date')
            )
            ->select('wr.animal_id', 'wr.weight_kg', 'wr.date')
            ->get()
            ->keyBy('animal_id');

        return $animals->map(function ($a) use ($latestWeights) {
            $w = $latestWeights->get($a->id);
            return [
                'id'          => $a->id,
                'tag'         => $a->tag,
                'breed'       => $a->breed,
                'sex'         => $a->sex,
                'lot_id'      => $a->lot_id,
                'weight_kg'   => $w ? (float) $w->weight_kg : null,
                'weight_date' => $w ? $w->date : null,
                'has_weight'  => $w !== null,
                'sale_type'   => 'live',
            ];
        });
    }

    // ── Carcass animals — status=slaughtered + has slaughter record ──
    // Weight = deadweight_kg from slaughter_records
    $query = \App\Models\Animal::where('farm_id', $farmId)
        ->where('status', 'slaughtered')
        ->orderBy('tag');

    if (!$user->is_super_admin) {
        $query->where('company_id', $user->company_id);
    }

    $animals = $query->get(['id', 'tag', 'breed', 'sex', 'lot_id']);

    $animalIds = $animals->pluck('id');

    // Get the most recent slaughter record per animal
    // (edge case: animal could theoretically have >1 record if data is unusual)
    $slaughterRecords = DB::table('slaughter_records as sr')
        ->joinSub(
            DB::table('slaughter_records')
                ->select('animal_id', DB::raw('MAX(date) as max_date'))
                ->whereIn('animal_id', $animalIds)
                ->groupBy('animal_id'),
            'latest',
            fn($join) => $join
                ->on('sr.animal_id', '=', 'latest.animal_id')
                ->on('sr.date', '=', 'latest.max_date')
        )
        ->select('sr.id as slaughter_id', 'sr.animal_id', 'sr.deadweight_kg',
                 'sr.liveweight_kg', 'sr.dressing_pct', 'sr.date', 'sr.grade')
        ->whereNotNull('sr.deadweight_kg')   // only animals with a deadweight recorded
        ->get()
        ->keyBy('animal_id');

    return $animals
        ->filter(fn($a) => $slaughterRecords->has($a->id)) // exclude slaughtered with no SR
        ->map(function ($a) use ($slaughterRecords) {
            $sr = $slaughterRecords->get($a->id);
            return [
                'id'            => $a->id,
                'tag'           => $a->tag,
                'breed'         => $a->breed,
                'sex'           => $a->sex,
                'lot_id'        => $a->lot_id,
                'weight_kg'     => (float) $sr->deadweight_kg,   // deadweight for carcass sales
                'weight_date'   => $sr->date,
                'has_weight'    => true,
                'slaughter_id'  => $sr->slaughter_id,
                'liveweight_kg' => $sr->liveweight_kg ? (float) $sr->liveweight_kg : null,
                'dressing_pct'  => $sr->dressing_pct  ? (float) $sr->dressing_pct  : null,
                'grade'         => $sr->grade,
                'sale_type'     => 'carcass',
            ];
        })
        ->values();
});