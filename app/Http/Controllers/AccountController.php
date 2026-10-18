<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Account Controller (Chart of Accounts)
//  Manages the company's chart of accounts. Accounts form a tree
//  via parent_id. System accounts (is_system=1) are protected from
//  deletion. Only Accountant / Farm Owner roles may access this.
// ══════════════════════════════════════════════════════════════════

class AccountController extends Controller
{
    // ── Index — full chart of accounts (flat list + tree meta) ────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view accounts'),
            403
        );

        // Resolve company_id (§6.5 — session-based for Super Admin)
        $companyId = $this->resolveCompanyId($request);

        $query = Account::with('parent:id,code,name_en,name_ar')
            ->withCount('children')
            ->where('company_id', $companyId);

        // Optional filters
        if ($request->filled('type'))      $query->where('type', $request->type);
        if ($request->filled('is_active')) $query->where('is_active', (bool) $request->is_active);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('name_en', 'like', "%{$s}%")
                  ->orWhere('name_ar', 'like', "%{$s}%")
            );
        }

        // ── Pull live balances for all accounts in one query ──────
        // Sum all posted journal lines for this company, keyed by account_id.
        // formatAccount() uses this to compute the running balance per account.
        $balances = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->groupBy('jl.account_id')
            ->select(
                'jl.account_id',
                DB::raw('SUM(jl.debit)  as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get()
            ->keyBy('account_id');

        // Build as a plain PHP array keyed by id — Collections are immutable
        // and cannot be mutated during the rollup pass below.
        $accounts = [];
        foreach ($query->orderBy('code')->get() as $a) {
            $accounts[$a->id] = $this->formatAccount($a, $balances);
        }

        // ── Parent-id ancestor rollup ─────────────────────────────
        //
        // Every account with a non-zero balance walks UP its parent_id chain
        // and adds its balance to each ancestor. This correctly handles:
        //
        //   1110001 (balance 30M, parent→1110, grandparent→1100)
        //     → adds 30M to 1110  (intermediate seeded leaf)
        //     → adds 30M to 1100  (group header)
        //
        // Code-prefix matching was tried previously and failed because
        // '1110001'.startsWith('1100') === false — the codes don't share
        // a prefix in the string sense, they share it via the parent_id tree.
        //
        // parent_id IS reliable: the seeder always sets it, and the UI panel
        // always sets it via the parent picker when creating accounts.
        // Accounts with parent_id = null are root-level groups (1100, 1200…)
        // and correctly receive no addition from this loop.

        foreach ($accounts as $accId => $acc) {
            if (($acc['balance'] ?? 0) == 0) continue; // nothing to propagate

            // Walk up the parent chain, adding this account's balance to every ancestor
            $parentId = $acc['parent_id'] ?? null;
            while ($parentId !== null && isset($accounts[$parentId])) {
                $accounts[$parentId]['balance'] = round(
                    ($accounts[$parentId]['balance'] ?? 0) + ($acc['balance'] ?? 0),
                    2
                );
                $parentId = $accounts[$parentId]['parent_id'] ?? null;
            }
        }

        $accounts = array_values($accounts);

        $companies = [];
        if ($authUser->is_super_admin) {
            $companies = Company::orderBy('name_en')
                ->get(['id', 'name_en', 'name_ar'])
                ->map(fn($c) => ['id' => $c->id, 'name_en' => $c->name_en, 'name_ar' => $c->name_ar])
                ->values()
                ->all();
        }

        return Inertia::render('Accounting/Accounts/Index', [
            'accounts'          => $accounts,
            'filters'           => $request->only(['type', 'is_active', 'search', 'company_id']),
            'types'             => $this->accountTypes(),
            'isSuperAdmin'      => (bool) $authUser->is_super_admin,
            'companies'         => $companies,
            'selectedCompanyId' => $companyId,
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create accounts'),
            403
        );

        $parents = Account::where('company_id', $authUser->company_id ?? $request->company_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);

        return Inertia::render('Accounting/Accounts/CreateEdit', [
            'parents' => $parents,
            'types'   => $this->accountTypes(),
            'mode'    => 'create',
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create accounts'),
            403
        );

        $companyId = $this->resolveCompanyId($request);

        // RISK-F4 FIX: If a parent account is selected, reconstruct the full
        // code server-side from parent.code + suffix. Never trust the full
        // code from client when a parent is present.
        if ($request->filled('parent_id') && $request->filled('code_suffix')) {
            $parent   = Account::findOrFail((int) $request->input('parent_id'));
            $suffix   = preg_replace('/\D/', '', $request->input('code_suffix')); // digits only
            $fullCode = $parent->code . $suffix;
            $request->merge(['code' => $fullCode]);
        }

        $this->sanitizeNullableFields($request);
        $validated = $this->validateAccount($request, null, $companyId);

        // Cross-field bilingual guard §5.2
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        Account::create([
            'company_id'           => $companyId,
            'parent_id'            => $validated['parent_id'] ?? null,
            'code'                 => $validated['code'],
            'name_en'              => $validated['name_en'],
            'name_ar'              => $validated['name_ar'] ?? null,
            'type'                 => $validated['type'],
            'normal_balance'       => $validated['normal_balance'] ?? $this->defaultNormalBalance($validated['type']),
            'is_system'            => false,
            'allow_direct_posting' => $validated['allow_direct_posting'] ?? true,
            'is_active'            => $validated['is_active'] ?? true,
            'currency'             => $validated['currency'] ?? null,
            'notes'                => $validated['notes'] ?? null,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', __('messages.account_created'));
    }

    // ── Edit form ─────────────────────────────────────────────────
    public function edit(Account $account)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit accounts') && (int) $authUser->company_id === (int) $account->company_id),
            403
        );

        $parents = Account::where('company_id', $account->company_id)
            ->where('id', '!=', $account->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type']);

        return Inertia::render('Accounting/Accounts/CreateEdit', [
            'account' => $this->formatAccount($account->load('parent:id,code,name_en')),
            'parents' => $parents,
            'types'   => $this->accountTypes(),
            'mode'    => 'edit',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Account $account)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit accounts') && (int) $authUser->company_id === (int) $account->company_id),
            403
        );

        // System accounts: only notes and is_active may change
        if ($account->is_system) {
            $account->update($request->only(['notes', 'is_active']));
            return back()->with('success', __('messages.account_updated'));
        }

        // RISK-F4 FIX: same prefix enforcement on edit
        if ($request->filled('parent_id') && $request->filled('code_suffix')) {
            $parent   = Account::findOrFail((int) $request->input('parent_id'));
            $suffix   = preg_replace('/\D/', '', $request->input('code_suffix'));
            $fullCode = $parent->code . $suffix;
            $request->merge(['code' => $fullCode]);
        }

        $this->sanitizeNullableFields($request);
        $validated = $this->validateAccount($request, $account->id, $account->company_id);

        // Cross-field bilingual guard §5.2
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $account->update([
            'parent_id'            => $validated['parent_id'] ?? null,
            'code'                 => $validated['code'],
            'name_en'              => $validated['name_en'],
            'name_ar'              => $validated['name_ar'] ?? null,
            'type'                 => $validated['type'],
            'normal_balance'       => $validated['normal_balance'] ?? $account->normal_balance,
            'allow_direct_posting' => $validated['allow_direct_posting'] ?? $account->allow_direct_posting,
            'is_active'            => $validated['is_active'] ?? $account->is_active,
            'currency'             => $validated['currency'] ?? $account->currency,
            'notes'                => $validated['notes'] ?? null,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', __('messages.account_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Account $account)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete accounts') && (int) $authUser->company_id === (int) $account->company_id),
            403
        );

        abort_if($account->is_system, 422, __('messages.account_system_protected'));
        abort_if($account->journalLines()->exists(), 422, __('messages.account_has_postings'));
        abort_if($account->children()->exists(), 422, __('messages.account_has_children'));

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('success', __('messages.account_deleted'));
    }

    // ── Toggle active ─────────────────────────────────────────────
    public function toggleActive(Account $account)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit accounts') && (int) $authUser->company_id === (int) $account->company_id),
            403
        );

        abort_if($account->is_system && $account->is_active, 422, __('messages.account_system_protected'));

        // QA-075: the Trial Balance and Balance Sheet only list ACTIVE accounts. Deactivating an account that still
        // has a balance would make its amounts silently disappear from the reports (and break the balance check).
        if ($account->is_active) {
            $net = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
                ->where('je.company_id', $account->company_id)
                ->where('jl.account_id', $account->id)
                ->whereIn('je.status', ['posted', 'reversed'])
                ->selectRaw('COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) as net')
                ->value('net');
            abort_if(
                bccomp((string) ($net ?? '0'), '0', 2) !== 0,
                422,
                'This account still has a balance of ' . number_format((float) $net, 2) . '. Move the balance to another account first, then deactivate it.'
            );
        }

        $account->update(['is_active' => !$account->is_active]);

        return back()->with('success', __('messages.account_updated'));
    }

    // ── Private helpers ───────────────────────────────────────────

    private function validateAccount(Request $request, ?int $ignoreId, int $companyId): array
    {
        return $request->validate([
            'code'                 => [
                'required', 'string', 'max:50',
                Rule::unique('accounts')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'name_en'              => 'nullable|string|max:255',
            'name_ar'              => 'nullable|string|max:255',
            'type'                 => ['required', Rule::in(['asset','liability','equity','revenue','cogs','expense'])],
            'normal_balance'       => ['nullable', Rule::in(['debit','credit'])],
            'parent_id'            => 'nullable|integer|exists:accounts,id',
            'allow_direct_posting' => 'boolean',
            'is_active'            => 'boolean',
            'currency'             => 'nullable|string|size:3',
            'notes'                => 'nullable|string|max:1000',
        ]);
    }

    /**
     * Shape an Account model into the array Vue expects.
     *
     * @param  Account        $a
     * @param  \Illuminate\Support\Collection|null  $balances  Keyed by account_id from the bulk balance query.
     *                                                          Pass null when called from edit() — balance not needed there.
     */
    private function formatAccount(Account $a, $balances = null): array
    {
        // ── Compute running balance from pre-fetched totals ───────
        // debit-normal  accounts: balance = total_debit  − total_credit
        // credit-normal accounts: balance = total_credit − total_debit
        $debit   = (float) ($balances?->get($a->id)?->total_debit  ?? 0);
        $credit  = (float) ($balances?->get($a->id)?->total_credit ?? 0);
        $balance = ($a->normal_balance === 'debit')
            ? $debit - $credit
            : $credit - $debit;

        return [
            'id'                   => $a->id,
            'parent_id'            => $a->parent_id,
            'code'                 => $a->code,
            'name_en'              => $a->name_en,
            'name_ar'              => $a->name_ar,
            'type'                 => $a->type,
            'normal_balance'       => $a->normal_balance,
            'balance'              => round($balance, 2),   // ← live running balance
            'is_system'            => (bool) $a->is_system,
            'allow_direct_posting' => (bool) $a->allow_direct_posting,
            'is_active'            => (bool) $a->is_active,
            'currency'             => $a->currency,
            'notes'                => $a->notes,
            'parent'               => $a->relationLoaded('parent') ? $a->parent?->only(['id','code','name_en','name_ar']) : null,
            'children_count'       => $a->children_count ?? 0,
        ];
    }

    /** Default normal balance by account type */
    private function defaultNormalBalance(string $type): string
    {
        return match($type) {
            'asset', 'cogs', 'expense'        => 'debit',
            'liability', 'equity', 'revenue'  => 'credit',
            default                           => 'debit',
        };
    }

    private function accountTypes(): array
    {
        return ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];
    }
}