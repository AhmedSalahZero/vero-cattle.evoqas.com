<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Lot;
use App\Models\Farm;
use App\Models\Supplier;
use App\Models\AnimalBreed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Animal Controller
//  The foundational operational record. Every other module
//  references an animal. Supports full lifecycle tracking from
//  procurement through slaughter or sale.
// ══════════════════════════════════════════════════════════════════

class AnimalController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view animals'),
            403
        );

        $query = Animal::with([
            'lot:id,name',
            'farm:id,name_en,name_ar',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        // Filters
        if ($request->filled('farm_id'))  $query->where('farm_id', $request->farm_id);
        if ($request->filled('lot_id'))   $query->where('lot_id', $request->lot_id);
        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('sex'))      $query->where('sex', $request->sex);
        if ($request->filled('breed'))    $query->where('breed', 'like', '%' . $request->breed . '%');
        if ($request->filled('tag'))      $query->where('tag', 'like', '%' . $request->tag . '%');

        $animals = $query->orderByRaw('CAST(REGEXP_REPLACE(tag, "[^0-9]", "") AS UNSIGNED), tag')
        ->paginate(50)->withQueryString();

        return Inertia::render('Animals/Index', [
            'animals'  => $animals->through(fn($a) => $this->formatAnimal($a)),
            'farms'    => $this->availableFarms($authUser),
            'lots'     => $this->availableLots($authUser, $request->farm_id),
            'filters'  => $request->only(['farm_id', 'lot_id', 'status', 'sex', 'breed', 'tag']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    // SESSION 46: Renders Animals/BornCreate.vue — a dedicated form
    // for born-on-farm animals only. Purchased animals must go through
    // Procurement → Receive Animals (ProcurementController::receive()).
    // Suppliers are not passed — born animals have no supplier.
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create animals'),
            403
        );

        return Inertia::render('Animals/BornCreate', [
            'farms' => $this->availableFarms($authUser),
            'lots'  => $this->availableLots($authUser),
            'breeds' => $this->availableBreeds($authUser),   // ← NEW
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create animals'),
            403
        );

        $validated = $this->validateAnimal($request);

        // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
        // Purchased animals must come through a confirmed PO via
        // ProcurementController::confirmReceipt() — not directly here.
        // This route is restricted to born_on_farm only.
        if (($validated['source'] ?? '') === 'purchased') {
            abort(422, 'Purchased animals must be received through a Purchase Order. Use Procurement → Receive Animals.');
        }

        $this->authorizeFarm($authUser, $validated['farm_id']);

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')
                ->store('photos/animals', 'public');
        }

        Animal::create($validated);

        return redirect()->route('animals.index')
            ->with('success', __('messages.animal_created'));
    }

    // ── Store Bulk ────────────────────────────────────────────────
    // Receives: { animals: [ {tag, rfid, sex, breed, farm_id, lot_id,
    //             purchase_weight_kg, purchase_price, freight_cost, ...}, ... ] }
    // Called by Mode A (average weight) and Mode B (per-unit weights).
    // Uses DB::table()->insert() — NOT Animal::insert() — because the
    // Animal model uses SoftDeletes, which causes __callStatic to fail
    // when insert() is called as a static method inside a transaction closure.
    public function storeBulk(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create animals'),
            403
        );

        // ── Top-level validation ───────────────────────────────────
        // company_id is always validated here regardless of role.
        // Super admin sends it in the payload; regular users have it
        // enforced from their own session record via the 'in' rule.
        $request->validate([
            'animals'    => 'required|array|min:1|max:5000',
            'company_id' => 'required|exists:companies,id',
        ]);
        
        // 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
        // storeBulk() is restricted to born_on_farm animals only.
        // Purchased animals must arrive via ProcurementController::confirmReceipt().
        $hasPurchased = collect($request->input('animals', []))
            ->contains(fn($row) => ($row['source'] ?? '') === 'purchased');

        if ($hasPurchased) {
            abort(422, 'Purchased animals must be received through a Purchase Order. Use Procurement → Receive Animals.');
        }

        $animals   = $request->input('animals');
        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);
    

        // ── Per-row validation ─────────────────────────────────────
        $rowErrors = [];

        foreach ($animals as $index => $row) {
            $rowValidator = validator($row, [
                'farm_id'            => 'required|exists:farms,id',
                'lot_id'             => 'nullable|exists:lots,id',
                'tag'                => [
                    'required', 'string', 'max:255',
                    Rule::unique('animals', 'tag')
                        ->where('company_id', $companyId),
                ],
                'rfid'               => 'nullable|string|max:255',
                'breed'              => 'nullable|string|max:255',
                'dob'                => 'nullable|date',
                'dob_estimated'      => 'boolean',
                'sex'                => ['required', Rule::in(['male', 'female', 'castrated'])],
                'color'              => 'nullable|string|max:255',
                'source'             => ['required', Rule::in(['purchased', 'born_on_farm'])],
                'purchase_date'      => 'nullable|date',
                'supplier_id'        => 'nullable|exists:suppliers,id',
                'purchase_price'     => 'nullable|numeric|min:0',
                'purchase_weight_kg' => 'nullable|numeric|min:0',
                'freight_cost'       => 'nullable|numeric|min:0',
                'status'             => ['nullable', Rule::in(['active', 'sold', 'slaughtered', 'dead', 'quarantine', 'transferred'])],
                'status_date'        => 'nullable|date',
                'notes'              => 'nullable|string',
            ]);

            if ($rowValidator->fails()) {
                $rowErrors["animals.{$index}"] = $rowValidator->errors()->all();
            }
        }

        if (!empty($rowErrors)) {
            return back()->withErrors($rowErrors)->withInput();
        }

        // ── FIX #3: Authorize every unique farm_id, not just row 0 ─
        // Original code only checked $animals[0]['farm_id'], meaning a
        // malicious user could mix a valid farm in row 0 with foreign
        // farm IDs in every other row and bypass the ownership check.
        $uniqueFarmIds = collect($animals)->pluck('farm_id')->unique();
        foreach ($uniqueFarmIds as $farmId) {
            $this->authorizeFarm($authUser, $farmId);
        }

        // ── Insert all rows in a single transaction ────────────────
        DB::transaction(function () use ($animals, $companyId) {
            // FIX #1: DB::table()->insert() passes values directly to PDO,
            // which cannot bind Carbon objects. format() produces a plain
            // string that PDO accepts without error.
            $now  = now()->format('Y-m-d H:i:s');
            $rows = [];

            foreach ($animals as $row) {
                $rows[] = [
                    'company_id'         => $companyId,
                    'farm_id'            => $row['farm_id'],
                    'lot_id'             => $row['lot_id']             ?? null,
                    'tag'                => $row['tag'],
                    'rfid'               => $row['rfid']               ?? null,
                    'breed'              => $row['breed']              ?? null,
                    'dob'                => $row['dob']                ?? null,
                    // FIX #4: Explicit (int) cast for TINYINT(1) column.
                    // PHP false can silently become an empty string via PDO
                    // on strict MySQL modes; 0/1 is always unambiguous.
                    'dob_estimated'      => isset($row['dob_estimated']) ? (int) $row['dob_estimated'] : 0,
                    'sex'                => $row['sex'],
                    'color'              => $row['color']              ?? null,
                    'markings'           => null,
                    'source'             => $row['source'],
                    'dam_id'             => null,
                    'sire_id'            => null,
                    'purchase_date'      => $row['purchase_date']      ?? null,
                    'supplier_id'        => $row['supplier_id']        ?? null,
                    'purchase_price'     => $row['purchase_price']     ?? null,
                    'purchase_weight_kg' => $row['purchase_weight_kg'] ?? null,
                    'freight_cost'       => $row['freight_cost']       ?? null,
                    'status'             => $row['status']             ?? 'active',
                    'status_date'        => $row['status_date']        ?? null,
                    'notes'              => $row['notes']              ?? null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                    // deleted_at intentionally omitted — NULL by default
                ];
            }

            // DB::table() bypasses Eloquent entirely — no SoftDeletes conflict,
            // no __callStatic issue, and significantly faster for bulk inserts.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('animals')->insert($chunk);
            }
        });

        $count = count($animals);

        return redirect()->route('animals.index')
            ->with('success', __('messages.animals_bulk_created', ['count' => $count]));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, Animal $animal)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view animals'),
            403
        );

        $this->authorizeFarm($authUser, $animal->farm_id);

        $animal->load([
            'lot:id,name',
            'farm:id,name_en,name_ar',
            'supplier:id,name_en,name_ar',
            'dam:id,tag,breed',
            'sire:id,tag,breed',
            'weightRecords' => fn($q) => $q->orderBy('date', 'desc')->limit(10),
            'healthRecords' => fn($q) => $q->orderBy('date', 'desc')->limit(10),
            'vaccinationRecords' => fn($q) => $q->orderBy('date', 'desc')->limit(10),
        ]);

        return Inertia::render('Animals/Show', [
            'animal'   => $this->formatAnimal($animal),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(Request $request, Animal $animal)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit animals'),
            403
        );

        $this->authorizeFarm($authUser, $animal->farm_id);

        return Inertia::render('Animals/CreateEdit', [
            'animal'    => $this->formatAnimal($animal),
            'farms'     => $this->availableFarms($authUser),
            'lots'      => $this->availableLots($authUser, $animal->farm_id),
            'suppliers' => $this->availableSuppliers($authUser),
            'breeds'    => $this->availableBreeds($authUser),   // ← ADD
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Animal $animal)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit animals'),
            403
        );

        $this->authorizeFarm($authUser, $animal->farm_id);

        $validated = $this->validateAnimal($request, $animal->id);

        if ($request->hasFile('photo')) {
            if ($animal->photo) {
                Storage::disk('public')->delete($animal->photo);
            }
            $validated['photo'] = $request->file('photo')
                ->store('photos/animals', 'public');
        }

        $animal->update($validated);

        return redirect()->route('animals.show', $animal->id)
            ->with('success', __('messages.animal_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, Animal $animal)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete animals'),
            403
        );

        $this->authorizeFarm($authUser, $animal->farm_id);

        if ($animal->photo) {
            Storage::disk('public')->delete($animal->photo);
        }

        $animal->delete();

        return redirect()->route('animals.index')
            ->with('success', __('messages.animal_deleted'));
    }

    // ── Bulk lot assignment ───────────────────────────────────────
    public function bulkAssignLot(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit animals'),
            403
        );

        $request->validate([
            'animal_ids'   => 'required|array|min:1',
            'animal_ids.*' => 'exists:animals,id',
            'lot_id'       => 'required|exists:lots,id',
        ]);

        $lot = Lot::findOrFail($request->lot_id);
        $this->authorizeFarm($authUser, $lot->farm_id);

       $query = Animal::whereIn('id', $request->animal_ids);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        $query->update(['lot_id' => $lot->id]);

        return back()->with('success', __('messages.animals_assigned'));
    }

    // ── Private helpers ───────────────────────────────────────────
    private function validateAnimal(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'company_id'         => 'required|exists:companies,id',
            'farm_id'            => 'required|exists:farms,id',
            'lot_id'             => 'nullable|exists:lots,id',
            'tag'                => [
                'required', 'string', 'max:255',
                Rule::unique('animals', 'tag')
                    ->where('company_id', request('company_id'))
                    ->ignore($ignoreId),
            ],
            'rfid'               => 'nullable|string|max:255',
            'breed'              => 'nullable|string|max:255',
            'dob'                => 'nullable|date',
            'dob_estimated'      => 'boolean',
            'sex'                => ['required', Rule::in(['male', 'female', 'castrated'])],
            'color'              => 'nullable|string|max:255',
            'markings'           => 'nullable|string|max:255',
            'source'             => ['required', Rule::in(['purchased', 'born_on_farm'])],
            'dam_id'             => 'nullable|exists:animals,id',
            'sire_id'            => 'nullable|exists:animals,id',
            'purchase_date'      => 'nullable|date',
            'supplier_id'        => 'nullable|exists:suppliers,id',
            'purchase_price'     => 'nullable|numeric|min:0',
            'purchase_weight_kg' => 'nullable|numeric|min:0',
            'freight_cost'       => 'nullable|numeric|min:0',
            'photo'              => 'nullable|image|max:2048',
            'status'             => ['nullable', Rule::in(['active', 'sold', 'slaughtered', 'dead', 'quarantine', 'transferred'])],
            'status_date'        => 'nullable|date',
            'notes'              => 'nullable|string',
        ]);
    }

    private function formatAnimal(Animal $animal): array
    {
        return [
            'id'                  => $animal->id,
            'company_id'          => $animal->company_id,
            'farm_id'             => $animal->farm_id,
            'farm'                => $animal->farm ? [
                'id'      => $animal->farm->id,
                'name_en' => $animal->farm->name_en,
                'name_ar' => $animal->farm->name_ar,
            ] : null,
            'lot_id'              => $animal->lot_id,
            'lot'                 => $animal->lot ? [
                'id'   => $animal->lot->id,
                'name' => $animal->lot->name,
            ] : null,
            'tag'                 => $animal->tag,
            'rfid'                => $animal->rfid,
            'qr_code'             => $animal->qr_code,
            'breed'               => $animal->breed,
            'dob'                 => $animal->dob?->toDateString(),
            'dob_estimated'       => $animal->dob_estimated,
            'sex'                 => $animal->sex,
            'color'               => $animal->color,
            'markings'            => $animal->markings,
            'source'              => $animal->source,
            'dam_id'              => $animal->dam_id,
            'sire_id'             => $animal->sire_id,
            'purchase_date'       => $animal->purchase_date?->toDateString(),
            'supplier_id'         => $animal->supplier_id,
            'supplier'            => $animal->supplier ? [
                'id'      => $animal->supplier->id,
                'name_en' => $animal->supplier->name_en,
                'name_ar' => $animal->supplier->name_ar,
            ] : null,
            'purchase_price'      => $animal->purchase_price,
            'purchase_weight_kg'  => $animal->purchase_weight_kg,
            'freight_cost'        => $animal->freight_cost,
            'photo'               => $animal->photo
                ? asset('storage/' . $animal->photo)
                : null,
            'status'              => $animal->status,
            'status_date'         => $animal->status_date?->toDateString(),
            'notes'               => $animal->notes,
            'weight_records'      => $animal->relationLoaded('weightRecords')
                ? $animal->weightRecords->map(fn($w) => [
                    'id'         => $w->id,
                    'date'       => $w->date->toDateString(),
                    'weight_kg'  => $w->weight_kg,
                    'adg_kg'     => $w->adg_kg,
                ])
                : [],
            'created_at'          => $animal->created_at?->format('d M Y'),
        ];
    }

    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    private function availableLots($authUser, ?int $farmId = null): \Illuminate\Support\Collection
    {
        $query = Lot::where('status', 'active');

        if ($farmId) {
            $query->where('farm_id', $farmId);
        } elseif (!$authUser->is_super_admin) {
            $query->whereHas('farm', fn($q) =>
                $q->where('company_id', $authUser->company_id)
            );
        }

        return $query->orderBy('name')->get(['id', 'farm_id', 'name']);
    }

    private function availableSuppliers($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Supplier::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Supplier::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_ar']);
    }

    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
    }
    private function availableBreeds($authUser): \Illuminate\Support\Collection
    {
        $companyId = $authUser->is_super_admin
            ? session('selected_company_id')
            : $authUser->company_id;
    
        if (!$companyId) return collect();
    
        return AnimalBreed::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }
}