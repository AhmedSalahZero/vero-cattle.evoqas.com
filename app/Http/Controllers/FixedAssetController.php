<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AssetDepreciationLine;
use App\Models\Farm;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Fixed Asset Controller
//
//  Manages the company fixed-asset register and depreciation
//  schedule. Two depreciation methods are supported:
//
//    • Straight-Line (SL)
//        Monthly charge = (Cost − Salvage) ÷ Useful Life (months)
//        Charge is constant every period until fully depreciated.
//
//    • Declining Balance (DB)
//        Monthly charge = Net Book Value × (Annual Rate ÷ 12)
//        Charge decreases each period as NBV falls.
//        Floor: charge cannot push AccumDep below (Cost − Salvage).
//
//  Depreciation computation lives in private helpers below.
//  Journal-entry posting for each depreciation line is done here
//  (thin service wrapper can be extracted later if needed).
//
//  Key actions beyond standard CRUD:
//    generateSchedule  — creates all pending lines for a fiscal year
//    postPeriod        — posts one period's lines & creates JE
//    dispose           — records disposal, posts gain/loss JE
// ══════════════════════════════════════════════════════════════════

class FixedAssetController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view fixed_assets'),
            403
        );

        $query = FixedAsset::with([
            'farm:id,name_en,name_ar',
            'assetAccount:id,code,name_en,name_ar',
        ]);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        // ── Filters ───────────────────────────────────────────────
        if ($request->filled('farm_id'))  $query->where('farm_id', $request->farm_id);
        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('status'))   $query->where('status', $request->status);

        $assets = $query->orderBy('asset_code')->paginate(50)->withQueryString();

        return Inertia::render('FixedAssets/Index', [
            'assets'   => $assets->through(fn($a) => $this->formatAsset($a)),
            'farms'    => $this->availableFarms($authUser),
            'filters'  => $request->only(['farm_id', 'category', 'status']),
            'summary'  => $this->buildSummary($authUser),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create fixed_assets'),
            403
        );

        return Inertia::render('FixedAssets/CreateEdit', [
            'farms'    => $this->availableFarms($authUser),
            'accounts' => $this->availableAccounts($authUser),
            'mode'     => 'create',                          // ✅ FIX 1 — added
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create fixed_assets'),
            403
        );

        $validated = $this->validateAsset($request);

        if ($validated['farm_id']) {
            $this->authorizeFarm($authUser, $validated['farm_id']);
        }

        $companyId = $authUser->is_super_admin
            ? ($validated['farm_id']
                ? Farm::find($validated['farm_id'])->company_id
                : $request->input('company_id'))
            : $authUser->company_id;

        DB::transaction(function () use ($validated, $companyId, $authUser) {
            $asset = FixedAsset::create([
                ...$validated,
                'company_id'    => $companyId,
                'net_book_value' => $validated['purchase_cost'] ?? 0, // ✅ FA-S2 FIX: initial NBV = purchase cost
            ]);

            // Auto-post purchase journal entry if accounting is standalone
            $this->postPurchaseJournalEntry($asset, $authUser);
        });

        return redirect()->route('fixed-assets.index')
            ->with('success', __('messages.fixed_asset_created'));
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);

        $fixedAsset->load([
            'farm:id,name_en,name_ar',
            'assetAccount:id,code,name_en,name_ar',
            'accumulatedDepreciationAccount:id,code,name_en,name_ar',
            'depreciationExpenseAccount:id,code,name_en,name_ar',
            'journalEntry:id,reference,date',
            'depreciationLines' => fn($q) => $q->with([
                'fiscalYear:id,year',
                'journalEntry:id,reference',             // ✅ FIX 3 — added
            ])->orderBy('depreciation_date'),
        ]);

        return Inertia::render('FixedAssets/Show', [
            'asset'        => $this->formatAsset($fixedAsset, detailed: true),
            'fiscal_years' => FiscalYear::where('company_id', $fixedAsset->company_id)
                                ->where('status', 'open')
                                ->get(['id', 'year']),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);

        // Block edits on disposed assets
        abort_if($fixedAsset->status === 'disposed', 403,
            __('messages.cannot_edit_disposed_asset'));

        return Inertia::render('FixedAssets/CreateEdit', [
            'asset'    => $this->formatAsset($fixedAsset),
            'farms'    => $this->availableFarms($authUser),
            'accounts' => $this->availableAccounts($authUser),
            'mode'     => 'edit',                            // ✅ FIX 1 — added
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);
        abort_if($fixedAsset->status === 'disposed', 403,
            __('messages.cannot_edit_disposed_asset'));

        // Block changes to financial fields once depreciation lines exist
        $hasPostedLines = $fixedAsset->depreciationLines()
            ->where('is_posted', true)->exists();

        $validated = $this->validateAsset($request, $fixedAsset->id, $hasPostedLines);

        $fixedAsset->update($validated);

        return redirect()->route('fixed-assets.show', $fixedAsset->id)
            ->with('success', __('messages.fixed_asset_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('delete fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);

        // Cannot delete if posted depreciation lines exist
        abort_if(
            $fixedAsset->depreciationLines()->where('is_posted', true)->exists(),
            422,
            __('messages.cannot_delete_asset_with_posted_depreciation')
        );

        DB::transaction(function () use ($fixedAsset) {
            $fixedAsset->depreciationLines()->delete(); // remove pending schedule
            $fixedAsset->delete();                      // soft delete
        });

        return redirect()->route('fixed-assets.index')
            ->with('success', __('messages.fixed_asset_deleted'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DEPRECIATION SCHEDULE — Generate
    //  Creates all pending depreciation lines for a given asset
    //  and fiscal year. Does NOT post them — posting is a separate
    //  accountant action (postPeriod).
    // ══════════════════════════════════════════════════════════════
    public function generateSchedule(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);

        $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
        ]);

        $fiscalYear = FiscalYear::findOrFail($request->fiscal_year_id);

        abort_if(
            $fixedAsset->depreciation_method === 'none',
            422,
            __('messages.asset_not_depreciable')
        );

        abort_if(
            $fixedAsset->isFullyDepreciated(),
            422,
            __('messages.asset_fully_depreciated')
        );

        DB::transaction(function () use ($fixedAsset, $fiscalYear) {
            $this->buildDepreciationSchedule($fixedAsset, $fiscalYear);
        });

        return back()->with('success', __('messages.depreciation_schedule_generated'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DEPRECIATION — Post one period
    //  Posts all unposted lines for a given fiscal year + month,
    //  creates a balanced journal entry (Dr Depreciation Expense /
    //  Cr Accumulated Depreciation), updates accumulated_depreciation
    //  on the asset, and marks lines as posted.
    // ══════════════════════════════════════════════════════════════
    public function postPeriod(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('post depreciation'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);

        $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'period_month'   => 'required|integer|min:1|max:12',
        ]);

        $line = AssetDepreciationLine::where('asset_id', $fixedAsset->id)
            ->where('fiscal_year_id', $request->fiscal_year_id)
            ->where('period_month', $request->period_month)
            ->where('is_posted', false)
            ->firstOrFail();

        DB::transaction(function () use ($line, $fixedAsset, $authUser) {

            // ── Create balanced journal entry ─────────────────────
            // Dr Depreciation Expense / Cr Accumulated Depreciation
            $je = JournalEntry::create([
                'company_id'     => $fixedAsset->company_id,
                'fiscal_year_id' => $line->fiscal_year_id,
                'date'           => $line->depreciation_date,
                'reference'      => 'DEP-' . $fixedAsset->asset_code . '-' . str_pad($line->period_month, 2, '0', STR_PAD_LEFT),
                'description'    => 'Depreciation: ' . $fixedAsset->name_en,
                'period'         => $line->period_month,
                'status'         => 'posted',
                'source_type'    => FixedAsset::class,
                'source_id'      => $fixedAsset->id,
                'entry_type'     => 'auto',
                'total_debit'    => $line->amount,
                'total_credit'   => $line->amount,
                'posted_by'      => $authUser->id,
                'posted_at'      => now(),
            ]);

            // Debit — Depreciation Expense
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $fixedAsset->dep_expense_account_id,
                'debit'      => $line->amount,
                'credit'     => 0,
            ]);

            // Credit — Accumulated Depreciation
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $fixedAsset->acc_dep_account_id,
                'debit'      => 0,
                'credit'     => $line->amount,
            ]);

            // ── Mark line as posted ───────────────────────────────
            $line->update([
                'is_posted'        => true,
                'posted_at'        => now(),
                'posted_by'        => $authUser->id,
                'journal_entry_id' => $je->id,
            ]);

            // ── Update asset running totals ───────────────────────
            // 📌 METHODOLOGY REFERENCE: DepreciationScheduleBuilder (#4)
            $newAccum = (float) bcadd(
                (string) $fixedAsset->accumulated_depreciation,
                (string) $line->amount,
                4
            );
            // ✅ FA-S2 FIX: compute and persist net_book_value after each period
            $newNbv = (float) bcsub(
                (string) $fixedAsset->purchase_cost,
                (string) $newAccum,
                4
            );
            $fixedAsset->update([
                'accumulated_depreciation' => $newAccum,
                'net_book_value'           => max(0.0, $newNbv), // never below zero
                'status' => $newAccum >= $fixedAsset->depreciable_base
                    ? 'fully_depreciated'
                    : 'active',
            ]);
        });

        return back()->with('success', __('messages.depreciation_posted'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DISPOSAL
    //  Records asset disposal (sale or write-off), posts a gain/
    //  loss journal entry, and marks asset as disposed.
    //
    //  Journal entry on disposal:
    //    Dr  Accumulated Depreciation   (clear contra account)
    //    Dr  Cash / AR                  (proceeds, if any)
    //    Dr  Loss on Disposal           (if proceeds < NBV)
    //    Cr  Fixed Asset Account        (original cost)
    //    Cr  Gain on Disposal           (if proceeds > NBV)
    // ══════════════════════════════════════════════════════════════
    public function dispose(Request $request, FixedAsset $fixedAsset)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('edit fixed_assets'),
            403
        );

        $this->authorizeAsset($authUser, $fixedAsset);
        abort_if($fixedAsset->status === 'disposed', 422,
            __('messages.asset_already_disposed'));

        $validated = $request->validate([
            'disposal_date'     => 'required|date',
            'disposal_proceeds' => 'required|numeric|min:0',
            'notes'             => 'nullable|string',
        ]);

        // ── FA-F2 FIX: resolve fiscal year from disposal date ─────────
        // Without fiscal_year_id the disposal JE is invisible in Trial
        // Balance, P&L, Budget actuals, and every period-filtered report.
        $disposeDate = $validated['disposal_date'];
        $fiscalYear  = FiscalYear::where('company_id', $fixedAsset->company_id)
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $disposeDate)
            ->whereDate('end_date',   '>=', $disposeDate)
            ->first();

        abort_unless(
            $fiscalYear,
            422,
            __('messages.no_open_fiscal_year_for_date')
        );

        DB::transaction(function () use ($validated, $fixedAsset, $authUser, $fiscalYear) {

            $proceeds  = (float) $validated['disposal_proceeds'];
            $cost      = (float) $fixedAsset->purchase_cost;
            $accumDep  = (float) $fixedAsset->accumulated_depreciation;
            $nbv       = $cost - $accumDep;
            $gainLoss  = $proceeds - $nbv; // positive = gain, negative = loss

            // ── Find or derive gain/loss accounts from CoA ────────
            // These account codes follow the standard VERO chart.
            // If they don't exist yet the JE will still be created
            // with the asset's own accounts — accountant can adjust.
            $gainAccount = Account::where('company_id', $fixedAsset->company_id)
                ->where('code', '4200')  // Gain on asset disposal
                ->first();

            $lossAccount = Account::where('company_id', $fixedAsset->company_id)
                ->where('code', '6300')  // Loss on asset disposal
                ->first();

            $cashAccount = Account::where('company_id', $fixedAsset->company_id)
                ->where('code', '1100')  // Cash
                ->first();

            // Compute total debit = AccumDep + Proceeds + Loss (if any)
            $totalDebit  = $accumDep + $proceeds + ($gainLoss < 0 ? abs($gainLoss) : 0);
            $totalCredit = $cost     + ($gainLoss > 0 ? $gainLoss : 0);

            $je = JournalEntry::create([
                'company_id'     => $fixedAsset->company_id,
                'fiscal_year_id' => $fiscalYear->id,              // ✅ FA-F2 FIX
                'date'           => $validated['disposal_date'],
                'reference'      => 'DISP-' . $fixedAsset->asset_code,
                'description'    => 'Disposal: ' . $fixedAsset->name_en,
                'period'         => (int) date('n', strtotime($validated['disposal_date'])),
                'status'         => 'posted',
                'source_type'    => FixedAsset::class,
                'source_id'      => $fixedAsset->id,
                'entry_type'     => 'manual',
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
                'posted_by'      => $authUser->id,
                'posted_at'      => now(),
                'notes'          => $validated['notes'] ?? null,
            ]);

            // Dr Accumulated Depreciation (clear contra)
            if ($accumDep > 0) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $fixedAsset->acc_dep_account_id,
                    'debit'      => $accumDep,
                    'credit'     => 0,
                ]);
            }

            // Dr Cash (proceeds received)
            if ($proceeds > 0 && $cashAccount) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $cashAccount->id,
                    'debit'      => $proceeds,
                    'credit'     => 0,
                ]);
            }

            // Dr Loss on disposal (if proceeds < NBV)
            if ($gainLoss < 0 && $lossAccount) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $lossAccount->id,
                    'debit'      => abs($gainLoss),
                    'credit'     => 0,
                ]);
            }

            // Cr Fixed Asset at cost (remove from books)
            JournalLine::create([
                'entry_id'   => $je->id,
                'account_id' => $fixedAsset->asset_account_id,
                'debit'      => 0,
                'credit'     => $cost,
            ]);

            // Cr Gain on disposal (if proceeds > NBV)
            if ($gainLoss > 0 && $gainAccount) {
                JournalLine::create([
                    'entry_id'   => $je->id,
                    'account_id' => $gainAccount->id,
                    'debit'      => 0,
                    'credit'     => $gainLoss,
                ]);
            }

            // ── FA-F1 FIX: assert actual line totals balance ──────
            // The header total_debit / total_credit was computed
            // conditionally — missing CoA accounts silently reduce line
            // totals below the header, bypassing JournalBalanceValidation.
            // Reload actual line sums from DB and assert balance here.
            // 📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
            $actualDebit  = JournalLine::where('entry_id', $je->id)
                ->selectRaw('COALESCE(SUM(debit), 0) as total')->value('total') ?? '0';
            $actualCredit = JournalLine::where('entry_id', $je->id)
                ->selectRaw('COALESCE(SUM(credit), 0) as total')->value('total') ?? '0';

            $imbalance = ltrim(bcsub((string) $actualDebit, (string) $actualCredit, 4), '-');

            if (bccomp($imbalance, '0.0050', 4) > 0) {
                // Roll back the transaction — the JE entry is invalid.
                // Likely cause: Chart of Accounts accounts 1100, 4200, or 6300
                // are missing. The accountant must seed them before disposing.
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => __('messages.disposal_je_unbalanced_missing_accounts'),
                    ], 422)
                );
            }

            // Sync header totals to match actual line sums
            $je->update([
                'total_debit'  => (float) bcadd((string) $actualDebit,  '0', 2),
                'total_credit' => (float) bcadd((string) $actualCredit, '0', 2),
            ]);

            // ── Update asset record ───────────────────────────────
            $fixedAsset->update([
                'status'                    => 'disposed',
                'disposal_date'             => $validated['disposal_date'],
                'disposal_proceeds'         => $proceeds,
                'disposal_journal_entry_id' => $je->id,
            ]);

            // Cancel all remaining unposted depreciation lines
            $fixedAsset->depreciationLines()
                ->where('is_posted', false)
                ->delete();
        });

        return redirect()->route('fixed-assets.show', $fixedAsset->id)
            ->with('success', __('messages.asset_disposed'));
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE — Depreciation Schedule Builder
    //
    //  📌 METHODOLOGY AND LOGIC REFERENCE
    //  See: DepreciationScheduleBuilder
    //
    //  STRAIGHT-LINE:
    //    Monthly Charge = (Cost − Salvage) ÷ Useful Life (months)
    //    Constant every period. Final period adjusted so
    //    AccumDep never exceeds (Cost − Salvage).
    //
    //  DECLINING BALANCE:
    //    Monthly Charge = Net Book Value × (Annual Rate ÷ 12)
    //    NBV = Cost − AccumDep at start of each period.
    //    Floor: charge capped so AccumDep never exceeds depreciable base.
    //
    //  Variables:
    //    $cost         — purchase_cost
    //    $salvage      — salvage_value
    //    $base         — depreciable base = cost − salvage
    //    $accum        — accumulated_depreciation at schedule start
    //    $nbv          — net book value = cost − accum
    //    $monthlyRate  — annual db_rate ÷ 12
    //    $remaining    — base − accum (max depreciable remaining)
    // ══════════════════════════════════════════════════════════════
    private function buildDepreciationSchedule(
        FixedAsset $asset,
        FiscalYear $fiscalYear
    ): void {
        $base       = $asset->depreciable_base;          // cost − salvage
        $accum      = (float) $asset->accumulated_depreciation;
        $method     = $asset->depreciation_method;

        // SL monthly charge — constant
        $slCharge   = $asset->monthly_sl_charge;

        // DB monthly rate
        $monthlyRate = $method === 'declining_balance' && $asset->db_rate
            ? (float) $asset->db_rate / 12
            : 0;

        $runningAccum = $accum; // tracks accumulation as we build lines

        for ($month = 1; $month <= 12; $month++) {

            // Skip if a line for this period already exists
            $exists = AssetDepreciationLine::where('asset_id', $asset->id)
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('period_month', $month)
                ->exists();

            if ($exists) continue;

            // Skip if already fully depreciated
            $remaining = $base - $runningAccum;
            if ($remaining <= 0) break;

            // ── Compute charge for this period ────────────────────
            if ($method === 'straight_line') {
                $charge = min($slCharge, $remaining); // cap final period
            } else {
                // Declining balance: apply rate to current NBV
                $currentNbv = (float) $asset->purchase_cost - $runningAccum;
                $charge = round($currentNbv * $monthlyRate, 2);
                $charge = min($charge, $remaining);   // floor at depreciable base
            }

            if ($charge <= 0) break;

            $runningAccum += $charge;

            // ── Depreciation date = last day of this period ───────
            $depDate = \Carbon\Carbon::create(
                $fiscalYear->start_date->year,
                $fiscalYear->start_date->addMonths($month - 1)->month,
                1
            )->endOfMonth()->toDateString();

            AssetDepreciationLine::create([
                'asset_id'            => $asset->id,
                'company_id'          => $asset->company_id,
                'fiscal_year_id'      => $fiscalYear->id,
                'period_month'        => $month,
                'depreciation_date'   => $depDate,
                'amount'              => $charge,
                'accumulated_to_date' => $runningAccum,
                'is_posted'           => false,
                // ✅ FA-S3 FIX: annotate each line for auditor traceability
                'notes'               => 'Period ' . $month . ' — ' . ucfirst(str_replace('_', ' ', $asset->depreciation_method)) . ' depreciation',
            ]);
        }
    }

    // ── Post purchase journal entry on asset creation ─────────────
    // Dr Fixed Asset Account / Cr Accounts Payable (2100)
    private function postPurchaseJournalEntry(FixedAsset $asset, $authUser): void
    {
        $apAccount = Account::where('company_id', $asset->company_id)
            ->where('code', '2100')   // Accounts Payable
            ->first();

        if (!$apAccount) {
            // ✅ FA-P5-1 FIX: surface the skip as a warning — never fail silently.
            // The asset is created correctly; only the accounting trail is missing.
            // Accountant must seed account 2100 (Accounts Payable) and manually
            // post the purchase JE from the asset's Show page.
            session()->flash('warning', __('messages.fa_purchase_je_skipped_missing_account'));
            return;
        }

        $je = JournalEntry::create([
            'company_id'     => $asset->company_id,
            'date'           => $asset->purchase_date,
            'reference'      => 'FA-' . $asset->asset_code,
            'description'    => 'Purchase of fixed asset: ' . $asset->name_en,
            'period'         => (int) $asset->purchase_date->format('n'),
            'status'         => 'posted',
            'source_type'    => FixedAsset::class,
            'source_id'      => $asset->id,
            'entry_type'     => 'auto',
            'total_debit'    => $asset->purchase_cost,
            'total_credit'   => $asset->purchase_cost,
            'posted_by'      => $authUser->id,
            'posted_at'      => now(),
        ]);

        // Dr Fixed Asset Account
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $asset->asset_account_id,
            'debit'      => $asset->purchase_cost,
            'credit'     => 0,
        ]);

        // Cr Accounts Payable
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $apAccount->id,
            'debit'      => 0,
            'credit'     => $asset->purchase_cost,
        ]);

        $asset->update(['journal_entry_id' => $je->id]);
    }

    // ── Index summary totals ──────────────────────────────────────
    private function buildSummary($authUser): array
    {
        $query = FixedAsset::query();

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return [
            'total_cost'      => (clone $query)->whereNotIn('status', ['disposed'])->sum('purchase_cost'),
            'total_accum_dep' => (clone $query)->whereNotIn('status', ['disposed'])->sum('accumulated_depreciation'),
            'total_nbv'       => (clone $query)->whereNotIn('status', ['disposed'])->sum('net_book_value'),
            'active_count'    => (clone $query)->where('status', 'active')->count(),
            'fully_dep_count' => (clone $query)->where('status', 'fully_depreciated')->count(),
            'disposed_count'  => (clone $query)->where('status', 'disposed')->count(),
        ];
    }

    // ── Validation ────────────────────────────────────────────────
    private function validateAsset(
        Request $request,
        ?int $ignoreId = null,
        bool $lockFinancials = false
    ): array {
        $rules = [
            'farm_id'         => 'nullable|exists:farms,id',
            'asset_code'      => [
                'required', 'string', 'max:255',
                Rule::unique('fixed_assets', 'asset_code')->ignore($ignoreId),
            ],
            'name_en'         => 'nullable|string|max:255',
            'name_ar'         => 'nullable|string|max:255',
            'category'        => ['required', Rule::in(['land', 'building', 'vehicle', 'equipment', 'furniture', 'other'])],
            'purchase_date'   => 'required|date',
            'in_service_date' => 'nullable|date|after_or_equal:purchase_date',
            'status'          => ['nullable', Rule::in(['active', 'fully_depreciated', 'disposed', 'impaired'])],
            'notes'           => 'nullable|string',
        ];

        // Financial fields locked once depreciation has been posted
        if (!$lockFinancials) {
            $rules = array_merge($rules, [
                'purchase_cost'          => 'required|numeric|min:0.01',
                'salvage_value'          => 'nullable|numeric|min:0',
                'depreciation_method'    => ['required', Rule::in(['straight_line', 'declining_balance', 'none'])],
                'useful_life_months'     => 'nullable|integer|min:1|max:600',
                'db_rate'                => 'nullable|numeric|min:0.0001|max:1',
                'asset_account_id'       => 'required|exists:accounts,id',
                'acc_dep_account_id'     => 'required|exists:accounts,id',
                'dep_expense_account_id' => 'required|exists:accounts,id',
            ]);
        }

        $validated = $request->validate($rules);

        // ── Cross-field bilingual guard ───────────────────────────
        // At least one of name_en / name_ar must be non-null
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        return $validated;
    }

    // ── Format asset for Inertia ──────────────────────────────────
    private function formatAsset(FixedAsset $asset, bool $detailed = false): array
    {
        $data = [
            'id'                       => $asset->id,
            'company_id'               => $asset->company_id,
            'farm_id'                  => $asset->farm_id,
            'farm'                     => $asset->farm ? [
                'id'      => $asset->farm->id,
                'name_en' => $asset->farm->name_en,
                'name_ar' => $asset->farm->name_ar,
            ] : null,
            'asset_code'               => $asset->asset_code,
            'name_en'                  => $asset->name_en,
            'name_ar'                  => $asset->name_ar,
            'category'                 => $asset->category,
            'purchase_date'            => $asset->purchase_date?->toDateString(),
            'in_service_date'          => $asset->in_service_date?->toDateString(),
            'purchase_cost'            => $asset->purchase_cost,
            'salvage_value'            => $asset->salvage_value,
            'depreciation_method'      => $asset->depreciation_method,
            'useful_life_months'       => $asset->useful_life_months,
            'db_rate'                  => $asset->db_rate,
            'accumulated_depreciation' => $asset->accumulated_depreciation,
            'net_book_value'           => $asset->net_book_value,
            'depreciable_base'         => $asset->depreciable_base,
            'remaining_depreciable'    => $asset->remaining_depreciable,
            'monthly_sl_charge'        => $asset->monthly_sl_charge,
            'is_fully_depreciated'     => $asset->isFullyDepreciated(),
            'asset_account_id'         => $asset->asset_account_id,
            'asset_account'            => $asset->assetAccount ? [
                'id'      => $asset->assetAccount->id,
                'code'    => $asset->assetAccount->code,
                'name_en' => $asset->assetAccount->name_en,
                'name_ar' => $asset->assetAccount->name_ar,
            ] : null,
            'acc_dep_account_id'       => $asset->acc_dep_account_id,
            'dep_expense_account_id'   => $asset->dep_expense_account_id,
            'status'                   => $asset->status,
            'disposal_date'            => $asset->disposal_date?->toDateString(),
            'disposal_proceeds'        => $asset->disposal_proceeds,
            'notes'                    => $asset->notes,
            'created_at'               => $asset->created_at?->format('d M Y'),
            // ✅ FIX 2 — has_posted_lines for CreateEdit lock logic
            'has_posted_lines'         => $asset->relationLoaded('depreciationLines')
                ? $asset->depreciationLines->where('is_posted', true)->isNotEmpty()
                : $asset->depreciationLines()->where('is_posted', true)->exists(),
        ];

        // ── Extra detail for Show page ────────────────────────────
        if ($detailed) {
            $data['acc_dep_account']     = $asset->accumulatedDepreciationAccount ? [
                'id'      => $asset->accumulatedDepreciationAccount->id,
                'code'    => $asset->accumulatedDepreciationAccount->code,
                'name_en' => $asset->accumulatedDepreciationAccount->name_en,
                'name_ar' => $asset->accumulatedDepreciationAccount->name_ar,
            ] : null;
            $data['dep_expense_account'] = $asset->depreciationExpenseAccount ? [
                'id'      => $asset->depreciationExpenseAccount->id,
                'code'    => $asset->depreciationExpenseAccount->code,
                'name_en' => $asset->depreciationExpenseAccount->name_en,
                'name_ar' => $asset->depreciationExpenseAccount->name_ar,
            ] : null;
            $data['journal_entry']       = $asset->journalEntry ? [
                'id'        => $asset->journalEntry->id,
                'reference' => $asset->journalEntry->reference,
                'date'      => $asset->journalEntry->date?->toDateString(),
            ] : null;
            $data['depreciation_lines']  = $asset->relationLoaded('depreciationLines')
                ? $asset->depreciationLines->map(fn($l) => $this->formatDepreciationLine($l))
                : [];
        }

        return $data;
    }

    // ── Format a single depreciation line ─────────────────────────
    private function formatDepreciationLine(AssetDepreciationLine $line): array
    {
        return [
            'id'                  => $line->id,
            'fiscal_year_id'      => $line->fiscal_year_id,
            'fiscal_year'         => $line->fiscalYear ? [
                'id'   => $line->fiscalYear->id,
                'year' => $line->fiscalYear->year,
            ] : null,
            'period_month'        => $line->period_month,
            'depreciation_date'   => $line->depreciation_date?->toDateString(),
            'amount'              => $line->amount,
            'accumulated_to_date' => $line->accumulated_to_date,
            'is_posted'           => $line->is_posted,
            'posted_at'           => $line->posted_at?->format('d M Y H:i'),
            'journal_entry_id'    => $line->journal_entry_id,
            // ✅ FIX 3 — journal_entry_ref for Show page table display
            'journal_entry_ref'   => $line->relationLoaded('journalEntry')
                ? $line->journalEntry?->reference
                : null,
        ];
    }

    // ── Available accounts for CoA dropdowns ──────────────────────
    private function availableAccounts($authUser): \Illuminate\Support\Collection
    {
        $query = Account::where('is_active', true)
                        ->where('allow_direct_posting', true);

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        return $query->orderBy('code')
                     ->get(['id', 'code', 'name_en', 'name_ar', 'type']);
    }

    // ── Available farms ───────────────────────────────────────────
    private function availableFarms($authUser): \Illuminate\Support\Collection
    {
        return $authUser->is_super_admin
            ? Farm::where('is_active', true)->orderBy('name_en')
                  ->get(['id', 'company_id', 'name_en', 'name_ar'])
            : Farm::where('company_id', $authUser->company_id)
                  ->where('is_active', true)->orderBy('name_en')
                  ->get(['id', 'company_id', 'name_en', 'name_ar']);
    }

    // ── Authorize access to a specific asset ─────────────────────
    private function authorizeAsset($authUser, FixedAsset $asset): void
    {
        if ($authUser->is_super_admin) return;
        abort_unless($asset->company_id === $authUser->company_id, 403);
    }

    // ── Authorize farm access ─────────────────────────────────────
    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless($farm->company_id === $authUser->company_id, 403);
    }
}