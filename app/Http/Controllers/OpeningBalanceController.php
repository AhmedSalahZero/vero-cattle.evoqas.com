<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Opening Balance Controller
//
//  📌 METHODOLOGY REFERENCE: OpeningBalanceEquityPlug
//
//  Purpose:
//    Wizard for entering a company's opening balances. The form
//    mirrors the company's own chart of accounts — every account
//    the user created is listed, grouped by type (asset → liability
//    → equity), with the user simply filling in the balance for each.
//
//  Special sections within the account list:
//    - AR accounts (type=asset, code LIKE '16%' excl '161%'):
//        A "Customers" button expands a per-customer sub-table so
//        the user can break the account total down by customer.
//    - NR accounts (type=asset, code LIKE '161%'):
//        Same — per-customer sub-table.
//    - AP accounts (type=liability, code LIKE '221%'):
//        "Suppliers" button expands per-supplier sub-table.
//    - NP accounts (type=liability, code LIKE '222%'):
//        Same — per-supplier sub-table.
//    - Inventory accounts (1310–1350):
//        Auto-populated from inventory items catalog. User enters
//        qty + unit_cost; value = qty × unit_cost (always Debit).
//
//  Key rules:
//    - Only balance-sheet accounts (asset, liability, equity).
//      Revenue, COGS, expense accounts never appear here.
//    - Only allow_direct_posting = true accounts included.
//    - One opening balance entry per fiscal year per company.
//    - Equity plug auto-computed server-side.
//    - Once posted → reversal-only.
//
//  Routes:
//    GET    /opening-balances              → index
//    GET    /opening-balances/create       → create
//    POST   /opening-balances              → store
//    GET    /opening-balances/{je}/edit    → edit
//    PUT    /opening-balances/{je}         → update (draft only)
//    POST   /opening-balances/{je}/post    → post
// ══════════════════════════════════════════════════════════════════

class OpeningBalanceController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view journal_entries'),
            403
        );

        $query = JournalEntry::with(['fiscalYear:id,year,start_date,end_date,status'])
            ->where('entry_type', 'opening_balance')
            ->withCount('lines');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        $entries = $query->orderByDesc('date')->get()
            ->map(fn($e) => $this->formatEntry($e));

        return Inertia::render('Accounting/OpeningBalances/Index', [
            'entries'     => $entries,
            'fiscalYears' => $this->availableFiscalYears($authUser),
        ]);
    }

    // ── Create wizard ─────────────────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        $preselectedFyId = $request->integer('fiscal_year_id') ?: null;

        if ($preselectedFyId) {
            $existing = JournalEntry::where('company_id', $authUser->company_id ?? 0)
                ->where('fiscal_year_id', $preselectedFyId)
                ->where('entry_type', 'opening_balance')
                ->first();

            if ($existing) {
                if ($existing->status === 'posted') {
                    return redirect()->route('opening-balances.index')
                        ->with('error', __('messages.opening_balance_already_posted'));
                }
                return redirect()->route('opening-balances.edit', $existing);
            }
        }

        $companyId = $authUser->company_id;
        if (!$companyId && $preselectedFyId) {
            $fy = FiscalYear::find($preselectedFyId);
            $companyId = $fy?->company_id;
        }

        return Inertia::render('Accounting/OpeningBalances/CreateEdit', [
            'mode'                    => 'create',
            'entry'                   => null,
            'fiscalYears'             => $this->availableFiscalYears($authUser),
            'accountsByType'          => $this->balanceSheetAccountsGrouped($authUser, $companyId),
            'customers'               => $this->availableCustomers($authUser, $companyId),
            'suppliers'               => $this->availableSuppliers($authUser, $companyId),
            'inventoryItems'          => $this->availableInventoryItems($authUser, $companyId),
            'reAccounts'              => $this->accountsByType($authUser, 'equity', $companyId),
            'preselectedFiscalYearId' => $preselectedFyId,
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

        $validated = $this->validateWizard($request);
        $autoPost  = (bool) ($validated['auto_post'] ?? false);

        // Guard: one opening balance per FY per company
        $duplicate = JournalEntry::where('company_id', $companyId)
            ->where('fiscal_year_id', $validated['fiscal_year_id'])
            ->where('entry_type', 'opening_balance')
            ->first();

        if ($duplicate) {
            if ($duplicate->status === 'posted') {
                return back()->withErrors(['fiscal_year_id' => __('messages.opening_balance_already_posted')]);
            }
            return redirect()->route('opening-balances.edit', $duplicate)
                ->with('error', __('messages.opening_balance_draft_exists'));
        }

        $fy = FiscalYear::findOrFail($validated['fiscal_year_id']);
        abort_unless($fy->status === 'open', 422, __('messages.fiscal_year_not_open'));
        abort_unless($fy->company_id === $companyId, 403);

        DB::transaction(function () use ($validated, $companyId, $autoPost, $fy) {
            // 📌 METHODOLOGY REFERENCE: OpeningBalanceEquityPlug
            $lines  = $this->buildJournalLines($validated, $companyId);
            $this->assertBalanced($lines);
            $totals = $this->lineTotals($lines);

            $entry = JournalEntry::create([
                'company_id'     => $companyId,
                'fiscal_year_id' => $validated['fiscal_year_id'],
                'date'           => $validated['date'],
                'reference'      => 'OB-' . $fy->year,
                'description'    => $validated['description'] ?? ('Opening Balance ' . $fy->year),
                'period'         => (int) date('n', strtotime($validated['date'])),
                'status'         => $autoPost ? 'posted' : 'draft',
                'entry_type'     => 'opening_balance',
                'total_debit'    => $totals['debit'],
                'total_credit'   => $totals['credit'],
                'posted_by'      => $autoPost ? auth()->id() : null,
                'posted_at'      => $autoPost ? now() : null,
                'notes'          => $validated['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }
        });

        return redirect()->route('opening-balances.index')
            ->with('success', $autoPost
                ? __('messages.opening_balance_created_posted')
                : __('messages.opening_balance_created'));
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(JournalEntry $openingBalance)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') &&
             $authUser->company_id === $openingBalance->company_id),
            403
        );

        abort_unless($openingBalance->entry_type === 'opening_balance', 404);
        abort_unless($openingBalance->status === 'draft', 422, __('messages.opening_balance_already_posted'));

        $openingBalance->load(['lines.account:id,code,name_en,name_ar,type,normal_balance', 'fiscalYear:id,year']);

        $companyId = $authUser->company_id ?? $openingBalance->company_id;

        return Inertia::render('Accounting/OpeningBalances/CreateEdit', [
            'mode'           => 'edit',
            'entry'          => $this->formatEntryFull($openingBalance),
            'fiscalYears'    => $this->availableFiscalYears($authUser),
            'accountsByType' => $this->balanceSheetAccountsGrouped($authUser, $companyId),
            'customers'      => $this->availableCustomers($authUser, $companyId),
            'suppliers'      => $this->availableSuppliers($authUser, $companyId),
            'inventoryItems' => $this->availableInventoryItems($authUser, $companyId),
            'reAccounts'     => $this->accountsByType($authUser, 'equity', $companyId),
            'preselectedFiscalYearId' => $openingBalance->fiscal_year_id,
        ]);
    }

    // ── Update (draft only) ───────────────────────────────────────
    public function update(Request $request, JournalEntry $openingBalance)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') &&
             $authUser->company_id === $openingBalance->company_id),
            403
        );

        abort_unless($openingBalance->entry_type === 'opening_balance', 404);
        abort_unless($openingBalance->status === 'draft', 422, __('messages.opening_balance_already_posted'));

        $this->sanitizeNullableFields($request);
        $companyId = $openingBalance->company_id;
        $validated = $this->validateWizard($request);
        $autoPost  = (bool) ($validated['auto_post'] ?? false);

        DB::transaction(function () use ($openingBalance, $validated, $companyId, $autoPost) {
            // 📌 METHODOLOGY REFERENCE: OpeningBalanceEquityPlug
            $lines  = $this->buildJournalLines($validated, $companyId);
            $this->assertBalanced($lines);
            $totals = $this->lineTotals($lines);

            $openingBalance->update([
                'date'         => $validated['date'],
                'description'  => $validated['description'] ?? $openingBalance->description,
                'period'       => (int) date('n', strtotime($validated['date'])),
                'status'       => $autoPost ? 'posted' : 'draft',
                'total_debit'  => $totals['debit'],
                'total_credit' => $totals['credit'],
                'posted_by'    => $autoPost ? auth()->id() : null,
                'posted_at'    => $autoPost ? now() : null,
                'notes'        => $validated['notes'] ?? null,
            ]);

            $openingBalance->lines()->delete();
            foreach ($lines as $line) {
                $openingBalance->lines()->create($line);
            }
        });

        return redirect()->route('opening-balances.index')
            ->with('success', $autoPost
                ? __('messages.opening_balance_updated_posted')
                : __('messages.opening_balance_updated'));
    }

    // ── Post (draft → posted) ─────────────────────────────────────
    public function post(JournalEntry $openingBalance)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('create journal_entries') &&
             $authUser->company_id === $openingBalance->company_id),
            403
        );

        abort_unless($openingBalance->entry_type === 'opening_balance', 404);
        abort_unless($openingBalance->status === 'draft', 422, __('messages.opening_balance_already_posted'));

        $openingBalance->update([
            'status'    => 'posted',
            'posted_by' => $authUser->id,
            'posted_at' => now(),
        ]);

        return back()->with('success', __('messages.opening_balance_posted'));
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE — LINE BUILDER
    // ══════════════════════════════════════════════════════════════

    /**
     * Build journal lines from wizard input.
     *
     * account_lines[] — one entry per balance-sheet account.
     *   Fields: account_id, amount, side ('dr'|'cr'), memo
     *   Optional: customer_rows[] → {customer_id, amount}
     *             supplier_rows[] → {supplier_id, amount}
     *   When customer_rows/supplier_rows present, the account-level
     *   amount is ignored — individual sub-rows are posted instead,
     *   each carrying the partner name in the memo.
     *
     * inventory_lines[] — one per inventory item.
     *   Fields: account_id, item_id, qty, unit_cost
     *   value = qty × unit_cost, always Debit.
     *
     * Equity plug: difference between total Dr and total Cr is
     * auto-posted to the Retained Earnings account.
     */
    private function buildJournalLines(array $validated, int $companyId): array
    {
        $lines = [];

        // ── Regular account lines ─────────────────────────────────
        foreach ($validated['account_lines'] ?? [] as $row) {
            $side          = $row['side'] ?? 'dr';
            $customerRows  = array_filter($row['customer_rows'] ?? [], fn($r) => (float)($r['amount'] ?? 0) > 0);
            $supplierRows  = array_filter($row['supplier_rows'] ?? [], fn($r) => (float)($r['amount'] ?? 0) > 0);

            if (!empty($customerRows)) {
                // Post one line per customer — account total = sum of rows
                foreach ($customerRows as $cr) {
                    $cAmount = (float) $cr['amount'];
                    $customer = Customer::find($cr['customer_id']);
                    $lines[] = [
                        'account_id' => (int) $row['account_id'],
                        'debit'      => $side === 'dr' ? $cAmount : 0,
                        'credit'     => $side === 'cr' ? $cAmount : 0,
                        'memo'       => ($customer?->name_en ?? $customer?->name_ar ?? '') . ' — Opening Balance',
                    ];
                }
            } elseif (!empty($supplierRows)) {
                foreach ($supplierRows as $sr) {
                    $sAmount = (float) $sr['amount'];
                    $supplier = Supplier::find($sr['supplier_id']);
                    $lines[] = [
                        'account_id' => (int) $row['account_id'],
                        'debit'      => $side === 'dr' ? $sAmount : 0,
                        'credit'     => $side === 'cr' ? $sAmount : 0,
                        'memo'       => ($supplier?->name_en ?? $supplier?->name_ar ?? '') . ' — Opening Balance',
                    ];
                }
            } else {
                $amount = (float) ($row['amount'] ?? 0);
                if ($amount <= 0) continue;
                $lines[] = [
                    'account_id' => (int) $row['account_id'],
                    'debit'      => $side === 'dr' ? $amount : 0,
                    'credit'     => $side === 'cr' ? $amount : 0,
                    'memo'       => $row['memo'] ?? null,
                ];
            }
        }

        // ── Inventory lines (Debit) ───────────────────────────────
        foreach ($validated['inventory_lines'] ?? [] as $row) {
            $qty   = (float) ($row['qty']       ?? 0);
            $cost  = (float) ($row['unit_cost']  ?? 0);
            $value = round($qty * $cost, 2);
            if ($value <= 0) continue;

            $item = InventoryItem::find($row['item_id']);
            $lines[] = [
                'account_id' => (int) $row['account_id'],
                'debit'      => $value,
                'credit'     => 0,
                'memo'       => ($item?->name_en ?? $item?->name_ar ?? '') . ' — Opening Stock',
            ];
        }

        // ── Equity plug (Retained Earnings) ──────────────────────
        // 📌 OpeningBalanceEquityPlug step C
        $totalDr = array_sum(array_column($lines, 'debit'));
        $totalCr = array_sum(array_column($lines, 'credit'));
        $plug    = round($totalDr - $totalCr, 4);
        $reId    = $validated['retained_earnings_account_id'] ?? null;

        if (abs($plug) > 0.005 && $reId) {
            $lines[] = [
                'account_id' => (int) $reId,
                'debit'      => $plug < 0 ? abs($plug) : 0,
                'credit'     => $plug > 0 ? $plug       : 0,
                'memo'       => 'Opening Balance — Equity Plug',
            ];
        }

        return $lines;
    }

    private function assertBalanced(array $lines): void
    {
        $d = array_sum(array_column($lines, 'debit'));
        $c = array_sum(array_column($lines, 'credit'));
        if (abs($d - $c) > 0.005) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'balance' => __('messages.journal_not_balanced'),
            ]);
        }
    }

    private function lineTotals(array $lines): array
    {
        return [
            'debit'  => round(array_sum(array_column($lines, 'debit')),  2),
            'credit' => round(array_sum(array_column($lines, 'credit')), 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE — VALIDATION
    // ══════════════════════════════════════════════════════════════

    private function validateWizard(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id'               => 'required|integer|exists:fiscal_years,id',
            'date'                         => 'required|date',
            'description'                  => 'nullable|string|max:255',
            'notes'                        => 'nullable|string|max:2000',
            'retained_earnings_account_id' => 'nullable|integer|exists:accounts,id',
            'auto_post'                    => 'sometimes|boolean',

            // Flat account lines (one per balance-sheet posting account)
            'account_lines'                               => 'nullable|array',
            'account_lines.*.account_id'                  => 'required_with:account_lines|integer|exists:accounts,id',
            'account_lines.*.amount'                      => 'nullable|numeric|min:0',
            'account_lines.*.side'                        => 'required_with:account_lines|in:dr,cr',
            'account_lines.*.memo'                        => 'nullable|string|max:255',

            // Per-customer sub-rows (AR / NR accounts)
            'account_lines.*.customer_rows'               => 'nullable|array',
            'account_lines.*.customer_rows.*.customer_id' => 'required_with:account_lines.*.customer_rows|integer|exists:customers,id',
            'account_lines.*.customer_rows.*.amount'      => 'required_with:account_lines.*.customer_rows|numeric|min:0',

            // Per-supplier sub-rows (AP / NP accounts)
            'account_lines.*.supplier_rows'               => 'nullable|array',
            'account_lines.*.supplier_rows.*.supplier_id' => 'required_with:account_lines.*.supplier_rows|integer|exists:suppliers,id',
            'account_lines.*.supplier_rows.*.amount'      => 'required_with:account_lines.*.supplier_rows|numeric|min:0',

            // Inventory opening stock
            'inventory_lines'               => 'nullable|array',
            'inventory_lines.*.account_id'  => 'required_with:inventory_lines|integer|exists:accounts,id',
            'inventory_lines.*.item_id'     => 'required_with:inventory_lines|integer|exists:inventory_items,id',
            'inventory_lines.*.qty'         => 'required_with:inventory_lines|numeric|min:0',
            'inventory_lines.*.unit_cost'   => 'required_with:inventory_lines|numeric|min:0',
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE — FORMAT
    // ══════════════════════════════════════════════════════════════

    private function formatEntry(JournalEntry $e): array
    {
        return [
            'id'           => $e->id,
            'date'         => $e->date,
            'reference'    => $e->reference,
            'description'  => $e->description,
            'status'       => $e->status,
            'total_debit'  => $e->total_debit,
            'total_credit' => $e->total_credit,
            'fiscal_year'  => $e->fiscalYear?->only(['id', 'year', 'status']),
            'lines_count'  => $e->lines_count ?? 0,
            'posted_at'    => $e->posted_at,
        ];
    }

    /**
     * Reconstruct wizard form state from a saved journal entry.
     * Groups saved lines back into account_lines[] + inventory_lines[].
     */
    private function formatEntryFull(JournalEntry $e): array
    {
        $base           = $this->formatEntry($e);
        $accountMap     = [];   // keyed by account_id
        $inventoryLines = [];
        $reAccountId    = null;

        foreach ($e->lines as $line) {
            $memo   = $line->memo ?? '';
            $accId  = $line->account_id;
            $acc    = $line->account;
            $amount = $line->debit > 0 ? (float)$line->debit : (float)$line->credit;
            $side   = $line->debit > 0 ? 'dr' : 'cr';

            // Equity plug
            if ($memo === 'Opening Balance — Equity Plug') {
                $reAccountId = $accId;
                continue;
            }

            // Inventory line
            if (str_ends_with($memo, '— Opening Stock')) {
                $inventoryLines[] = [
                    'account_id' => $accId,
                    'item_id'    => null,   // cannot recover from memo — Vue will leave blank
                    'qty'        => null,
                    'unit_cost'  => null,
                    'value'      => $line->debit,
                    '_memo'      => $memo,
                ];
                continue;
            }

            // Initialise account slot
            if (!isset($accountMap[$accId])) {
                $accountMap[$accId] = [
                    'account_id'    => $accId,
                    'amount'        => 0.0,
                    'side'          => $side,
                    'memo'          => null,
                    'customer_rows' => [],
                    'supplier_rows' => [],
                ];
            }

            // Customer/supplier sub-row (detected by memo suffix)
            if (str_ends_with($memo, ' — Opening Balance') && $amount > 0) {
                $name = str_replace(' — Opening Balance', '', $memo);
                $accountMap[$accId]['amount'] += $amount;
                if (in_array($acc?->type, ['asset'])) {
                    $accountMap[$accId]['customer_rows'][] = [
                        'customer_id' => null,
                        'amount'      => $amount,
                        '_name'       => $name,
                    ];
                } else {
                    $accountMap[$accId]['supplier_rows'][] = [
                        'supplier_id' => null,
                        'amount'      => $amount,
                        '_name'       => $name,
                    ];
                }
            } else {
                // Plain line
                $accountMap[$accId]['amount'] += $amount;
                $accountMap[$accId]['memo']    = $memo ?: null;
            }
        }

        return array_merge($base, [
            'notes'                        => $e->notes,
            'fiscal_year_id'               => $e->fiscal_year_id,
            'retained_earnings_account_id' => $reAccountId,
            'account_lines'                => array_values($accountMap),
            'inventory_lines'              => $inventoryLines,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE — DATA HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * All balance-sheet posting accounts grouped by type.
     *
     * Each account carries boolean flags used by Vue to decide
     * which UI treatment to render:
     *   is_ar        → show customer sub-table button
     *   is_nr        → show customer sub-table button
     *   is_ap        → show supplier sub-table button
     *   is_np        → show supplier sub-table button
     *   is_inventory → render inline inventory items grid
     */
    private function balanceSheetAccountsGrouped($authUser, ?int $companyId): array
    {
        $cid = $companyId ?? $authUser->company_id;
        if (!$cid) return ['asset' => [], 'liability' => [], 'equity' => []];

        $accounts = Account::where('company_id', $cid)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type', 'normal_balance'])
            ->map(function ($a) {
                $code = $a->code;
                return [
                    'id'             => $a->id,
                    'code'           => $code,
                    'name_en'        => $a->name_en,
                    'name_ar'        => $a->name_ar,
                    'type'           => $a->type,
                    'normal_balance' => $a->normal_balance,
                    // AR: asset, starts with '16' but NOT '161'
                    'is_ar'          => $a->type === 'asset'
                                        && str_starts_with($code, '16')
                                        && !str_starts_with($code, '161'),
                    // NR: asset, starts with '161'
                    'is_nr'          => $a->type === 'asset'
                                        && str_starts_with($code, '161'),
                    // AP: liability, starts with '221'
                    'is_ap'          => $a->type === 'liability'
                                        && str_starts_with($code, '221'),
                    // NP: liability, starts with '222'
                    'is_np'          => $a->type === 'liability'
                                        && str_starts_with($code, '222'),
                    // Inventory accounts (1310–1350 exact codes)
                    'is_inventory'   => $a->type === 'asset'
                                        && in_array($code, ['1310','1320','1330','1340','1350']),
                ];
            });

        return [
            'asset'     => $accounts->where('type', 'asset')->values(),
            'liability' => $accounts->where('type', 'liability')->values(),
            'equity'    => $accounts->where('type', 'equity')->values(),
        ];
    }

    private function accountsByType($authUser, string $type, ?int $companyId = null): \Illuminate\Support\Collection
    {
        $cid = $companyId ?? $authUser->company_id;
        if (!$cid) return collect();
        return Account::where('company_id', $cid)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->where('type', $type)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar']);
    }

    /**
     * Master inventory items for the inventory opening stock grid.
     * Returns id, name, category, unit, and the current WAC as a
     * suggested unit_cost. Also maps category → inventory account code
     * so Vue can pre-select the correct account (1310–1350).
     */
    private function availableInventoryItems($authUser, ?int $companyId = null): \Illuminate\Support\Collection
    {
        $cid = $companyId ?? $authUser->company_id;
        if (!$cid) return collect();

        return InventoryItem::where('company_id', $cid)
            ->where('is_master', true)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'category', 'unit', 'unit_cost'])
            ->map(fn($item) => [
                'id'             => $item->id,
                'name_en'        => $item->name_en,
                'name_ar'        => $item->name_ar,
                'category'       => $item->category,
                'unit'           => $item->unit,
                'suggested_cost' => (float) $item->unit_cost,
                'account_code'   => match($item->category) {
                    'feed'     => '1320',
                    'medicine' => '1330',
                    'vaccine'  => '1340',
                    default    => '1350',
                },
            ]);
    }

    private function availableFiscalYears($authUser): \Illuminate\Support\Collection
    {
        $query = FiscalYear::orderByDesc('year');
        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }
        return $query->get(['id', 'year', 'start_date', 'end_date', 'status'])
            ->map(fn($fy) => [
                'id'         => $fy->id,
                'year'       => $fy->year,
                'start_date' => $fy->start_date?->format('Y-m-d'),
                'end_date'   => $fy->end_date?->format('Y-m-d'),
                'status'     => $fy->status,
            ]);
    }

    private function availableCustomers($authUser, ?int $companyId = null): \Illuminate\Support\Collection
    {
        $cid = $companyId ?? $authUser->company_id;
        if (!$cid) return collect();
        return Customer::where('company_id', $cid)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'code']);
    }

    private function availableSuppliers($authUser, ?int $companyId = null): \Illuminate\Support\Collection
    {
        $cid = $companyId ?? $authUser->company_id;
        if (!$cid) return collect();
        return Supplier::where('company_id', $cid)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'code']);
    }
}