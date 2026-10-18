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
            'farms'            => $this->availableFarms($authUser),
            'accounts'         => $this->availableAccounts($authUser),
            'payment_accounts' => $this->availablePaymentAccounts($authUser),
            'mode'             => 'create',
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

        // §6.5 — session-based company resolution for Super Admin.
        // If a farm is linked, derive from farm (authoritative).
        // Otherwise use session selected_company_id.
        $companyId = $this->resolveCompanyId($request);

        // Every account picked must belong to THIS company (the form only checks it exists)
        $this->assertAccountsBelongToCompany($validated, $companyId);

        // Land never depreciates — enforce server-side regardless of form input
        if (($validated['category'] ?? '') === 'land') {
            $validated['salvage_value']        = 0;
            $validated['depreciation_method']  = 'none';
            $validated['useful_life_months']   = null;
            $validated['db_rate']              = null;
        }

        DB::transaction(function () use ($validated, $companyId, $authUser) {
            $asset = FixedAsset::create([
                ...$validated,
                'company_id' => $companyId,
                // net_book_value is a MySQL generated column — never write it explicitly
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
            'asset'            => $this->formatAsset($fixedAsset),
            'farms'            => $this->availableFarms($authUser),
            'accounts'         => $this->availableAccounts($authUser),
            'payment_accounts' => $this->availablePaymentAccounts($authUser),
            'mode'             => 'edit',
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
        $this->assertAccountsBelongToCompany($validated, (int) $fixedAsset->company_id);

        // Land never depreciates — enforce server-side
        if (($validated['category'] ?? $fixedAsset->category) === 'land') {
            $validated['salvage_value']       = 0;
            $validated['depreciation_method'] = 'none';
            $validated['useful_life_months']  = null;
            $validated['db_rate']             = null;
        }

        // net_book_value is a MySQL generated column — never write it explicitly
        unset($validated['net_book_value']);

        // ── Purchase JE correction ─────────────────────────────────────────
        // Do NOT rely on 'did payment_account_id change in this request'.
        // The field may have been saved in a previous edit while the JE
        // correction never fired. Instead: after saving, read the actual JE
        // credit line and compare it to the asset's payment_account_id and
        // purchase_cost. Correct unconditionally if either differs.
        $self = $this;

        DB::transaction(function () use ($fixedAsset, $validated, $authUser, $self) {
            $fixedAsset->update($validated);
            $fresh = $fixedAsset->fresh();

            if (!$fresh->payment_account_id) {
                return;
            }

            // Check whether the existing JE credit line already matches
            // both the payment account AND the current purchase cost.
            $jeIsCorrect = false;
            if ($fresh->journal_entry_id) {
                $creditLine = JournalLine::where('entry_id', $fresh->journal_entry_id)
                    ->where('account_id', $fresh->payment_account_id)
                    ->where('credit', '>', 0)
                    ->first();

                $jeIsCorrect = $creditLine &&
                    bccomp((string) $creditLine->credit, (string) $fresh->purchase_cost, 2) === 0;
            }

            if (!$jeIsCorrect) {
                $self->correctPurchaseJournalEntry($fresh, $authUser);
            }
        });

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
        abort_unless((int) $fiscalYear->company_id === (int) $fixedAsset->company_id, 422,
            'Fiscal year does not belong to this asset\'s company.');

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
            $authUser->hasPermissionTo('edit fixed_assets'),   // 'post depreciation' was never seeded → 500 for non-super-admins
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
            // net_book_value is a MySQL GENERATED column (cost − accumulated depreciation):
            // the database recomputes it itself. Writing it here made every depreciation
            // posting fail with error 3105, so it is deliberately NOT written.
            $fixedAsset->update([
                'accumulated_depreciation' => $newAccum,
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

            // ── Resolve the accounts used by the disposal entry ───
            // BUG FIX: this used to look up 4200 (= Meat / Carcass Sales revenue) for the
            // gain, 6300 (= Farm Labour) for the loss and 1100 (= the Fixed Assets GROUP,
            // which cannot be posted to) for cash. A disposal therefore posted to the wrong
            // accounts or failed. The chart of accounts has ONE account for this:
            // 6800 "Gain / Loss on Asset Disposal" — credit = gain, debit = loss.
            $gainLossAccount = Account::where('company_id', $fixedAsset->company_id)
                ->where('code', '6800')
                ->where('allow_direct_posting', true)
                ->first();
            $gainAccount = $gainLossAccount;
            $lossAccount = $gainLossAccount;

            // Proceeds go to the account the asset was paid from if that is a cash/bank
            // account, otherwise to the first active cash (14xx) then bank (15xx) account.
            $cashAccount = $this->resolveDisposalCashAccount($fixedAsset);

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
                // Likely cause: account 6800 (Gain / Loss on Asset Disposal) or a
                // cash / bank account (14xx / 15xx) is missing.
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => __('messages.disposal_je_unbalanced_missing_accounts'),
                    ], 422)
                );
            }

            // Sync header totals to match actual line sums
            $je->update([
                'total_debit'  => (float) \App\Support\Money::round((string) $actualDebit, 2),
                'total_credit' => (float) \App\Support\Money::round((string) $actualCredit, 2),
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
    //  PRIVATE — Cash / bank account that receives disposal proceeds
    // ══════════════════════════════════════════════════════════════
    private function resolveDisposalCashAccount(FixedAsset $asset): ?Account
    {
        $isCashOrBank = fn (?Account $a) => $a
            && (int) $a->company_id === (int) $asset->company_id
            && $a->allow_direct_posting
            && $a->is_active
            && (str_starts_with((string) $a->code, '14') || str_starts_with((string) $a->code, '15'));

        if ($asset->payment_account_id) {
            $paid = Account::find($asset->payment_account_id);
            if ($isCashOrBank($paid)) {
                return $paid;
            }
        }

        foreach (['14', '15'] as $prefix) {
            $found = Account::where('company_id', $asset->company_id)
                ->where('code', 'like', $prefix . '%')
                ->where('allow_direct_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            if ($found) {
                return $found;
            }
        }

        return null;
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

    // ── Post purchase journal entry on asset creation ─────────────────
    // Dr  Fixed Asset Account        (asset_account_id)
    // Cr  Payment Account            (payment_account_id — user selected:
    //                                  Cash 14xx, Bank 15xx, or Payables 21xx)
    private function postPurchaseJournalEntry(FixedAsset $asset, $authUser): void
    {
        // payment_account_id is required in validateAsset() so it will always
        // be set on new assets. The nullable FK exists only for legacy rows
        // created before this column was added.
        if (!$asset->payment_account_id) {
            session()->flash('warning', __('messages.fa_purchase_je_skipped_missing_account'));
            return;
        }

        $paymentAccount = Account::find($asset->payment_account_id);

        if (!$paymentAccount) {
            session()->flash('warning', __('messages.fa_purchase_je_skipped_missing_account'));
            return;
        }

        // Guard: fiscal year must exist and be open for the purchase date
        $fiscalYear = FiscalYear::where('company_id', $asset->company_id)
            ->where('status', 'open')
            ->where('start_date', '<=', $asset->purchase_date)
            ->where('end_date',   '>=', $asset->purchase_date)
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning', __('messages.fa_purchase_je_skipped_no_fiscal_year'));
            return;
        }

        $je = JournalEntry::create([
            'company_id'     => $asset->company_id,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $asset->purchase_date,
            'reference'      => 'FA-' . $asset->asset_code,
            'description'    => 'Purchase of fixed asset: ' . ($asset->name_en ?? $asset->name_ar),
            'period'         => (int) $asset->purchase_date->format('n'),
            'status'         => 'posted',
            'source_type'    => FixedAsset::class,
            'source_id'      => $asset->id,
            'entry_type'     => 'depreciation',   // closest available entry_type for asset transactions
            'total_debit'    => $asset->purchase_cost,
            'total_credit'   => $asset->purchase_cost,
            'posted_by'      => $authUser->id,
            'posted_at'      => now(),
        ]);

        // Dr — Fixed Asset Account (asset increases)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $asset->asset_account_id,
            'debit'      => $asset->purchase_cost,
            'credit'     => 0,
        ]);

        // Cr — Payment Account (cash paid / bank transferred / liability created)
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $paymentAccount->id,
            'debit'      => 0,
            'credit'     => $asset->purchase_cost,
        ]);

        $asset->update(['journal_entry_id' => $je->id]);
    }

    // ── Correct purchase JE when payment account changes on edit ─────
    //
    // Two cases:
    //   A) A purchase JE was already posted (journal_entry_id is set)
    //      → reverse the old JE, post a new one to the new account
    //   B) No JE exists yet (e.g. no open fiscal year at creation time)
    //      → post a fresh JE to the newly selected account
    //
    protected function correctPurchaseJournalEntry(FixedAsset $asset, $authUser): void
    {
        if (!$asset->payment_account_id) {
            return;
        }

        // For a correction/reversal entry, use any open fiscal year —
        // pick the most recent one by end_date. Do NOT filter by today's date
        // or the purchase date: the accountant may be correcting a legacy asset
        // while the current calendar year has no open FY yet (e.g. FY2025 is
        // open but today is 2026 — date-range filtering would find nothing).
        $fiscalYear = FiscalYear::where('company_id', $asset->company_id)
            ->where('status', 'open')
            ->orderBy('end_date', 'desc')
            ->first();

        if (!$fiscalYear) {
            session()->flash('warning', __('messages.fa_purchase_je_skipped_no_fiscal_year'));
            return;
        }

        $paymentAccount = Account::find($asset->payment_account_id);
        if (!$paymentAccount) {
            return;
        }

        // ── Case A: reverse the existing purchase JE ──────────────
        if ($asset->journal_entry_id) {
            $original = JournalEntry::with('lines')->find($asset->journal_entry_id);

            if ($original) {
                $reversal = JournalEntry::create([
                    'company_id'     => $asset->company_id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'date'           => $asset->purchase_date instanceof \Carbon\Carbon
                                            ? $asset->purchase_date->toDateString()
                                            : $asset->purchase_date,
                    'reference'      => 'REV-FA-' . $asset->asset_code,
                    'description'    => 'Reversal of purchase JE: ' . ($asset->name_en ?? $asset->name_ar) . ' — payment account correction',
                    'period'         => (int) \Carbon\Carbon::parse($asset->purchase_date)->format('n'),
                    'status'         => 'posted',
                    'source_type'    => FixedAsset::class,
                    'source_id'      => $asset->id,
                    'entry_type'     => 'depreciation',
                    'total_debit'    => $original->total_credit,
                    'total_credit'   => $original->total_debit,
                    'reversal_of_id' => $original->id,
                    'posted_by'      => $authUser->id,
                    'posted_at'      => now(),
                ]);

                // Mirror lines with debit/credit swapped
                foreach ($original->lines as $line) {
                    JournalLine::create([
                        'entry_id'   => $reversal->id,
                        'account_id' => $line->account_id,
                        'debit'      => $line->credit,
                        'credit'     => $line->debit,
                    ]);
                }
            }
        }

        // ── Case A + B: post fresh JE to the new payment account ──
        $je = JournalEntry::create([
            'company_id'     => $asset->company_id,
            'fiscal_year_id' => $fiscalYear->id,
            'date'           => $asset->purchase_date instanceof \Carbon\Carbon
                                    ? $asset->purchase_date->toDateString()
                                    : $asset->purchase_date,
            'reference'      => 'FA-' . $asset->asset_code . '-ADJ',
            'description'    => 'Purchase JE correction: ' . ($asset->name_en ?? $asset->name_ar),
            'period'         => (int) \Carbon\Carbon::parse($asset->purchase_date)->format('n'),
            'status'         => 'posted',
            'source_type'    => FixedAsset::class,
            'source_id'      => $asset->id,
            'entry_type'     => 'depreciation',
            'total_debit'    => $asset->purchase_cost,
            'total_credit'   => $asset->purchase_cost,
            'posted_by'      => $authUser->id,
            'posted_at'      => now(),
        ]);

        // Dr — Fixed Asset Account
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $asset->asset_account_id,
            'debit'      => $asset->purchase_cost,
            'credit'     => 0,
        ]);

        // Cr — New Payment Account
        JournalLine::create([
            'entry_id'   => $je->id,
            'account_id' => $paymentAccount->id,
            'debit'      => 0,
            'credit'     => $asset->purchase_cost,
        ]);

        // Point asset to the latest purchase JE
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
            'farm_id'            => 'nullable|exists:farms,id',
            'asset_code'         => [
                'required', 'string', 'max:255',
                Rule::unique('fixed_assets', 'asset_code')
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'name_en'            => 'nullable|string|max:255',
            'name_ar'            => 'nullable|string|max:255',
            'category'           => ['required', Rule::in(['land', 'building', 'vehicle', 'equipment', 'furniture', 'other'])],
            'purchase_date'      => 'required|date',
            'in_service_date'    => 'nullable|date|after_or_equal:purchase_date',
            'status'             => ['nullable', Rule::in(['active', 'fully_depreciated', 'disposed', 'impaired'])],
            'notes'              => 'nullable|string',
            // payment_account_id is outside the lockFinancials block intentionally.
            // Changing the credit account is always allowed; update() handles
            // the reversal + re-post of the purchase JE transparently.
            'payment_account_id' => 'nullable|exists:accounts,id',
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

        // QA-023 — cross-field checks the form assumes (land is normalised later, so skip it here).
        // Without these a bad asset saved "successfully" but silently produced NO depreciation
        // (a schedule needs a life / rate and a salvage value below the cost).
        if (! $lockFinancials && ($validated['category'] ?? null) !== 'land') {
            $cost    = (string) ($validated['purchase_cost'] ?? '0');
            $salvage = (string) ($validated['salvage_value'] ?? '0');
            if (bccomp($salvage, $cost, 4) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'salvage_value' => __('Salvage value cannot be higher than the purchase cost.'),
                ]);
            }
            $method = $validated['depreciation_method'] ?? null;
            if ($method === 'straight_line' && empty($validated['useful_life_months'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'useful_life_months' => __('Useful life (months) is required for straight-line depreciation.'),
                ]);
            }
            if ($method === 'declining_balance' && empty($validated['db_rate'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'db_rate' => __('Annual rate is required for declining-balance depreciation.'),
                ]);
            }
        }

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
            'payment_account_id'       => $asset->payment_account_id,
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

    // Payment accounts for the purchase JE credit side.
    // Intentionally does NOT filter by allow_direct_posting — cash and bank
    // accounts (14xx, 15xx) are often marked allow_direct_posting=false because
    // they are managed through the Payments module, but they are valid credit
    // accounts for a fixed asset purchase journal entry.
    private function availablePaymentAccounts($authUser): \Illuminate\Support\Collection
    {
        $query = Account::where('is_active', true)
                        ->where(function ($q) {
                            $q->whereIn('type', ['asset', 'liability'])
                              ->where(function ($q2) {
                                  $q2->whereRaw("LEFT(code, 2) IN ('14', '15')")  // cash & bank
                                     ->orWhereRaw("LEFT(code, 2) IN ('21', '22')") // payables
                                     ->orWhere('allow_direct_posting', true);       // any other posting account
                              });
                        });

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
    /**
     * The form only checks that an account id EXISTS. Make sure each chosen account
     * belongs to the asset's own company — otherwise the purchase / depreciation /
     * disposal entries could post into another company's books.
     */
    private function assertAccountsBelongToCompany(array $validated, int $companyId): void
    {
        foreach (['asset_account_id', 'acc_dep_account_id', 'dep_expense_account_id', 'payment_account_id'] as $field) {
            if (empty($validated[$field])) {
                continue;
            }
            $ok = Account::where('id', $validated[$field])->where('company_id', $companyId)->exists();
            if (! $ok) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $field => 'الحساب المختار لا يخص هذه الشركة / The selected account does not belong to this company.',
                ]);
            }
        }
    }

    private function authorizeAsset($authUser, FixedAsset $asset): void
    {
        if ($authUser->is_super_admin) return;
        abort_unless((int) $asset->company_id === (int) $authUser->company_id, 403);
    }

    // ── Authorize farm access ─────────────────────────────────────
    private function authorizeFarm($authUser, int $farmId): void
    {
        if ($authUser->is_super_admin) return;
        $farm = Farm::findOrFail($farmId);
        abort_unless((int) $farm->company_id === (int) $authUser->company_id, 403);
    }
}