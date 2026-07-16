<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Farm;
use App\Models\FarmCostAllocation;
use App\Models\FarmOverheadLine;
use App\Models\FarmOverheadType;
use App\Models\FiscalYear;
use App\Services\FarmCostAllocationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO — Farm Cost Allocation Controller
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//
//  Routes:
//    GET    /farm-cost-allocations                → index
//    GET    /farm-cost-allocations/create         → create
//    POST   /farm-cost-allocations                → store
//    GET    /farm-cost-allocations/{id}/edit      → edit
//    PUT    /farm-cost-allocations/{id}            → update  (draft only)
//    DELETE /farm-cost-allocations/{id}            → destroy (draft only)
//    POST   /farm-cost-allocations/{id}/post      → post accrual JE
//    POST   /farm-cost-allocations/{id}/settle    → settle actual costs
//    GET    /farm-cost-allocations/overhead-types → overheadTypes (index page)
//    POST   /farm-cost-allocations/overhead-types → storeOverheadType
//    PUT    /farm-cost-allocations/overhead-types/{id} → updateOverheadType
//    DELETE /farm-cost-allocations/overhead-types/{id} → destroyOverheadType
//    GET    /farm-cost-allocations/preview-rates  → previewRates (AJAX)
// ══════════════════════════════════════════════════════════════════

class FarmCostAllocationController extends Controller
{
    public function __construct(private FarmCostAllocationService $service) {}

    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view journal_entries'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $query = FarmCostAllocation::with(['farm:id,name', 'fiscalYear:id,year'])
            ->where('company_id', $companyId);

        if ($request->filled('farm_id'))  $query->where('farm_id', $request->farm_id);
        if ($request->filled('year'))     $query->where('year', $request->year);
        if ($request->filled('status'))   $query->where('status', $request->status);

        $allocations = $query->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn($a) => $this->formatAllocation($a));

        // KPI strip
        $pendingPost   = FarmCostAllocation::where('company_id', $companyId)->where('status', 'draft')->count();
        $pendingSettle = FarmCostAllocation::where('company_id', $companyId)->where('status', 'posted')->count();
        $currentYear   = now()->year;
        $totalLaborYtd = FarmCostAllocation::where('company_id', $companyId)
            ->where('year', $currentYear)
            ->whereIn('status', ['posted', 'settled', 'locked'])
            ->sum('labor_estimated');

        $companies = [];
        if ($authUser->is_super_admin) {
            $companies = Company::orderBy('name_en')
                ->get(['id', 'name_en', 'name_ar'])
                ->map(fn($c) => ['id' => $c->id, 'name_en' => $c->name_en, 'name_ar' => $c->name_ar])
                ->values()->all();
        }

        return Inertia::render('FarmCostAllocation/Index', [
            'allocations'    => $allocations,
            'farms'          => $this->availableFarms($authUser, $companyId),
            'companies'      => $companies,
            'filters'        => $request->only(['farm_id', 'year', 'status', 'company_id']),
            'kpis'           => [
                'pending_post'    => $pendingPost,
                'pending_settle'  => $pendingSettle,
                'total_labor_ytd' => (float) $totalLaborYtd,
            ],
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        return Inertia::render('FarmCostAllocation/CreateEdit', [
            'mode'          => 'create',
            'allocation'    => null,
            'farms'         => $this->availableFarms($authUser, $companyId),
            'fiscalYears'   => $this->availableFiscalYears($companyId),
            'overheadTypes' => $this->availableOverheadTypes($companyId),
            'paymentAccounts' => $this->availablePaymentAccounts($companyId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        $this->sanitizeNullableFields($request);

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        abort_unless((int) $companyId > 0, 422, 'Company required');

        $validated = $request->validate([
            'farm_id'         => ['required', 'integer', 'exists:farms,id'],
            'fiscal_year_id'  => ['required', 'integer', 'exists:fiscal_years,id'],
            'month'           => ['required', 'integer', 'min:1', 'max:12'],
            'year'            => ['required', 'integer', 'min:2000', 'max:2100'],
            'labor_estimated' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'overhead_lines'  => ['nullable', 'array'],
            'overhead_lines.*.overhead_type_id' => ['required', 'integer', 'exists:farm_overhead_types,id'],
            'overhead_lines.*.estimated_amount' => ['required', 'numeric', 'min:0'],
        ]);

        // Guard: duplicate month/farm
        $exists = FarmCostAllocation::where('farm_id', $validated['farm_id'])
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'month' => 'An allocation record already exists for this farm and month.',
            ]);
        }

        // Compute month boundaries
        $monthStart = Carbon::createFromDate($validated['year'], $validated['month'], 1)->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        DB::transaction(function () use ($validated, $companyId, $monthStart, $monthEnd, $authUser) {
            $allocation = FarmCostAllocation::create([
                'company_id'      => $companyId,
                'farm_id'         => $validated['farm_id'],
                'fiscal_year_id'  => $validated['fiscal_year_id'],
                'month'           => $validated['month'],
                'year'            => $validated['year'],
                'month_start'     => $monthStart->toDateString(),
                'month_end'       => $monthEnd->toDateString(),
                'labor_estimated' => $validated['labor_estimated'],
                'notes'           => $validated['notes'] ?? null,
                'status'          => 'draft',
                'created_by'      => $authUser->id,
            ]);

            // Create overhead lines
            foreach ($validated['overhead_lines'] ?? [] as $line) {
                FarmOverheadLine::create([
                    'farm_cost_allocation_id' => $allocation->id,
                    'overhead_type_id'        => $line['overhead_type_id'],
                    'estimated_amount'        => $line['estimated_amount'],
                ]);
            }
        });

        return redirect()->route('farm-cost-allocations.index')
            ->with('success', 'Cost allocation record created.');
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(FarmCostAllocation $farmCostAllocation)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        abort_unless($farmCostAllocation->isDraft(), 403, 'Only draft allocations can be edited.');

        $companyId = $farmCostAllocation->company_id;
        $farmCostAllocation->load('overheadLines.overheadType');

        return Inertia::render('FarmCostAllocation/CreateEdit', [
            'mode'            => 'edit',
            'allocation'      => $this->formatAllocationFull($farmCostAllocation),
            'farms'           => $this->availableFarms($authUser, $companyId),
            'fiscalYears'     => $this->availableFiscalYears($companyId),
            'overheadTypes'   => $this->availableOverheadTypes($companyId),
            'paymentAccounts' => $this->availablePaymentAccounts($companyId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, FarmCostAllocation $farmCostAllocation)
    {
        abort_unless($farmCostAllocation->isDraft(), 403, 'Only draft allocations can be edited.');

        $this->sanitizeNullableFields($request);

        $validated = $request->validate([
            'labor_estimated' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'overhead_lines'  => ['nullable', 'array'],
            'overhead_lines.*.overhead_type_id' => ['required', 'integer', 'exists:farm_overhead_types,id'],
            'overhead_lines.*.estimated_amount' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($validated, $farmCostAllocation) {
            $farmCostAllocation->update([
                'labor_estimated' => $validated['labor_estimated'],
                'notes'           => $validated['notes'] ?? null,
            ]);

            // Sync overhead lines
            $farmCostAllocation->overheadLines()->delete();
            foreach ($validated['overhead_lines'] ?? [] as $line) {
                FarmOverheadLine::create([
                    'farm_cost_allocation_id' => $farmCostAllocation->id,
                    'overhead_type_id'        => $line['overhead_type_id'],
                    'estimated_amount'        => $line['estimated_amount'],
                ]);
            }
        });

        return redirect()->route('farm-cost-allocations.index')
            ->with('success', 'Cost allocation updated.');
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(FarmCostAllocation $farmCostAllocation)
    {
        abort_unless(
            Auth::user()->is_super_admin ||
            Auth::user()->hasPermissionTo('delete journal_entries'),
            403
        );

        abort_unless($farmCostAllocation->isDraft(), 403, 'Only draft allocations can be deleted.');

        // Cascade deletes overhead lines automatically (DB constraint)
        $farmCostAllocation->delete();

        return redirect()->route('farm-cost-allocations.index')
            ->with('success', 'Allocation record deleted.');
    }

    // ── Post month-end accrual ────────────────────────────────────
    public function post(FarmCostAllocation $farmCostAllocation)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        try {
            $this->service->postMonthEndAccrual($farmCostAllocation, $authUser->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        return redirect()->route('farm-cost-allocations.index')
            ->with('success', 'Month-end accrual posted successfully.');
    }

    // ── Settle actual costs ───────────────────────────────────────
    public function settle(Request $request, FarmCostAllocation $farmCostAllocation)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        $this->sanitizeNullableFields($request);

        $request->validate([
            'labor_actual'         => ['required', 'numeric', 'min:0'],
            'payment_account_id'   => ['required', 'integer', 'exists:accounts,id'],
            'overhead_actuals'     => ['nullable', 'array'],
            'overhead_actuals.*'   => ['numeric', 'min:0'],
        ]);

        $overheadActuals = [];
        foreach ($request->input('overhead_actuals', []) as $typeId => $amount) {
            $overheadActuals[(int) $typeId] = (string) $amount;
        }

        try {
            $this->service->settleActual(
                $farmCostAllocation,
                (string) $request->labor_actual,
                $overheadActuals,
                (int) $request->payment_account_id,
                $authUser->id
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['settle' => $e->getMessage()]);
        }

        return redirect()->route('farm-cost-allocations.index')
            ->with('success', 'Actual costs settled successfully.');
    }

    // ── Preview rates (AJAX) ──────────────────────────────────────
    public function previewRates(Request $request)
    {
        $authUser = Auth::user();

        $request->validate([
            'farm_id'          => ['required', 'integer'],
            'month'            => ['required', 'integer', 'min:1', 'max:12'],
            'year'             => ['required', 'integer'],
            'labor_estimated'  => ['required', 'numeric', 'min:0'],
        ]);

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $monthStart = Carbon::createFromDate($request->year, $request->month, 1)
            ->startOfMonth()->toDateString();
        $monthEnd   = Carbon::createFromDate($request->year, $request->month, 1)
            ->endOfMonth()->toDateString();

        $preview = $this->service->previewRates(
            (int) $request->farm_id,
            $companyId,
            $monthStart,
            $monthEnd,
            (string) $request->labor_estimated
        );

        return response()->json($preview);
    }

    // ── Overhead Types — Index ────────────────────────────────────
    public function overheadTypes(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view accounts'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $types = FarmOverheadType::with(['accruedAccount:id,code,name_en,name_ar', 'varianceAccount:id,code,name_en,name_ar'])
            ->where('company_id', $companyId)
            ->orderBy('name_en')
            ->get()
            ->map(fn($t) => $this->formatOverheadType($t));

        // Accounts for the dropdowns — must be posting accounts
        $accrualAccounts  = $this->accrualAccountOptions($companyId);
        $varianceAccounts = $this->varianceAccountOptions($companyId);

        return Inertia::render('FarmCostAllocation/OverheadTypes', [
            'types'            => $types,
            'accrualAccounts'  => $accrualAccounts,
            'varianceAccounts' => $varianceAccounts,
        ]);
    }

    // ── Overhead Types — Store ────────────────────────────────────
    public function storeOverheadType(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create accounts'),
            403
        );

        $this->sanitizeNullableFields($request);

        // 🔒 SA hard-block: write operations must have explicit company selection
        if ($authUser->is_super_admin) {
            $companyId = (int) $request->input('company_id');
            abort_unless($companyId > 0, 422, 'Super Admin must select a company before saving.');
        } else {
            $companyId = (int) $authUser->company_id;
        }

        $validated = $request->validate([
            'name_en'             => ['nullable', 'string', 'max:255'],
            'name_ar'             => ['nullable', 'string', 'max:255'],
            'accrued_account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'variance_account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            return back()->withErrors(['name_en' => 'At least one name is required.']);
        }

        FarmOverheadType::create(array_merge($validated, [
            'company_id' => $companyId,
            'is_active'  => true,
        ]));

        return back()->with('success', 'Overhead type created.');
    }

    // ── Overhead Types — Update ───────────────────────────────────
    public function updateOverheadType(Request $request, FarmOverheadType $farmOverheadType)
    {
        $this->sanitizeNullableFields($request);

        $validated = $request->validate([
            'name_en'             => ['nullable', 'string', 'max:255'],
            'name_ar'             => ['nullable', 'string', 'max:255'],
            'accrued_account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'variance_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'is_active'           => ['boolean'],
        ]);

        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            return back()->withErrors(['name_en' => 'At least one name is required.']);
        }

        $farmOverheadType->update($validated);

        return back()->with('success', 'Overhead type updated.');
    }

    // ── Overhead Types — Destroy ──────────────────────────────────
    public function destroyOverheadType(FarmOverheadType $farmOverheadType)
    {
        // Block deletion if any lines reference this type
        $inUse = FarmOverheadLine::where('overhead_type_id', $farmOverheadType->id)->exists();
        if ($inUse) {
            return back()->withErrors([
                'overhead_type' => 'This overhead type is used in existing allocation records and cannot be deleted. Deactivate it instead.',
            ]);
        }

        $farmOverheadType->delete();

        return back()->with('success', 'Overhead type deleted.');
    }

    // ── FORMAT HELPERS ────────────────────────────────────────────

    private function formatAllocation(FarmCostAllocation $a): array
    {
        return [
            'id'                => $a->id,
            'farm_id'           => $a->farm_id,
            'farm_name_en'      => optional($a->farm)->name_en,
            'farm_name_ar'      => optional($a->farm)->name_ar,
            'fiscal_year_id'    => $a->fiscal_year_id,
            'month'             => $a->month,
            'year'              => $a->year,
            'month_label'       => $a->monthLabel(),
            'month_start'       => $a->month_start?->toDateString(),
            'month_end'         => $a->month_end?->toDateString(),
            'labor_estimated'   => (float) $a->labor_estimated,
            'labor_actual'      => $a->labor_actual ? (float) $a->labor_actual : null,
            'labor_variance'    => $a->labor_variance ? (float) $a->labor_variance : null,
            'total_animal_days' => $a->total_animal_days,
            'rate_per_animal_day' => $a->rate_per_animal_day ? (float) $a->rate_per_animal_day : null,
            'status'            => $a->status,
            'can_edit'          => $a->isDraft(),
            'can_delete'        => $a->isDraft(),
            'can_post'          => $a->canPost(),
            'can_settle'        => $a->canSettle(),
            'labor_je_id'       => $a->labor_journal_entry_id,
            'settlement_je_id'  => $a->labor_settlement_je_id,
            'posted_at'         => $a->posted_at?->toDateTimeString(),
            'settled_at'        => $a->settled_at?->toDateTimeString(),
        ];
    }

    private function formatAllocationFull(FarmCostAllocation $a): array
    {
        $base = $this->formatAllocation($a);
        $base['overhead_lines'] = $a->overheadLines->map(fn($l) => [
            'id'                => $l->id,
            'overhead_type_id'  => $l->overhead_type_id,
            'overhead_type_name_en' => optional($l->overheadType)->name_en,
            'overhead_type_name_ar' => optional($l->overheadType)->name_ar,
            'estimated_amount'  => (float) $l->estimated_amount,
            'actual_amount'     => $l->actual_amount ? (float) $l->actual_amount : null,
            'variance_amount'   => $l->variance_amount ? (float) $l->variance_amount : null,
        ])->values()->all();
        return $base;
    }

    private function formatOverheadType(FarmOverheadType $t): array
    {
        return [
            'id'                  => $t->id,
            'name_en'             => $t->name_en,
            'name_ar'             => $t->name_ar,
            'accrued_account_id'  => $t->accrued_account_id,
            'accrued_account_code'=> optional($t->accruedAccount)->code,
            'accrued_account_name'=> optional($t->accruedAccount)->name_en,
            'variance_account_id' => $t->variance_account_id,
            'variance_account_code'=> optional($t->varianceAccount)->code,
            'variance_account_name'=> optional($t->varianceAccount)->name_en,
            'is_active'           => $t->is_active,
        ];
    }

    // ── DATA HELPERS ──────────────────────────────────────────────

   private function availableFarms($authUser, int $companyId): array
    {
        return Farm::where('company_id', $companyId)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar'])
            ->map(fn($f) => [
                'id'      => $f->id,
                'name_en' => $f->name_en,
                'name_ar' => $f->name_ar,
            ])
            ->values()
            ->all();
    }

    private function availableFiscalYears(int $companyId): array
    {
        return FiscalYear::where('company_id', $companyId)
            ->where('status', 'open')
            ->orderByDesc('year')
            ->get(['id', 'year', 'start_date', 'end_date', 'status'])
            ->map(fn($fy) => [
                'id'         => $fy->id,
                'year'       => $fy->year,
                'start_date' => $fy->start_date?->format('Y-m-d'),
                'end_date'   => $fy->end_date?->format('Y-m-d'),
                'status'     => $fy->status,
            ])
            ->values()
            ->all();
    }

    private function availableOverheadTypes(int $companyId): array
    {
        return FarmOverheadType::with(['accruedAccount:id,code,name_en', 'varianceAccount:id,code,name_en'])
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get()
            ->map(fn($t) => $this->formatOverheadType($t))
            ->values()
            ->all();
    }

    private function availablePaymentAccounts(int $companyId): array
    {
        // Cash (14xx) and Bank (15xx) accounts only
        return Account::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->where(fn($q) =>
                $q->where('code', 'like', '14%')
                  ->orWhere('code', 'like', '15%')
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->map(fn($a) => [
                'id'      => $a->id,
                'code'    => $a->code,
                'name_en' => $a->name_en,
                'name_ar' => $a->name_ar,
            ])
            ->values()
            ->all();
    }

    private function accrualAccountOptions(int $companyId): array
    {
        // Accounts under 2270 group (code starts with '227')
        return Account::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->where('code', 'like', '22%')
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->map(fn($a) => [
                'id'      => $a->id,
                'code'    => $a->code,
                'name_en' => $a->name_en,
                'name_ar' => $a->name_ar,
            ])
            ->values()
            ->all();
    }

    private function varianceAccountOptions(int $companyId): array
    {
        // Accounts under 6300 and 6320 groups
        return Account::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->where('code', 'like', '63%')
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->map(fn($a) => [
                'id'      => $a->id,
                'code'    => $a->code,
                'name_en' => $a->name_en,
                'name_ar' => $a->name_ar,
            ])
            ->values()
            ->all();
    }
}