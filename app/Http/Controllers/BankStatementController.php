<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Bank Statement Controller
//
//  Bank reconciliation module. Flow:
//    1. Manually enter bank statement lines
//    2. Match each line to an existing journal entry (optional)
//    3. Mark as reconciled once matched and confirmed
//
//  No financial calculations in this controller.
//  Reconciliation % is a simple count: reconciled_count / total_count.
//
//  Account scope — STRICTLY 14xx (Cash Safes) and 15xx (Banks) only.
//  Any other asset account (inventory, AR, fixed assets) must NEVER
//  appear here. Use the Reports → Ledger tab for those.
//
//  BUG FIXED: availableBankAccounts() was returning ALL asset accounts
//  (type='asset'). Now restricted to LEFT(code,2) IN ('14','15').
//  Same pattern already used correctly in PaymentController.
// ══════════════════════════════════════════════════════════════════

class BankStatementController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolveBankCompanyId($authUser, $request);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view bank_statements'),
            403
        );

        $query = BankStatement::with('account:id,code,name_en,name_ar,currency');

        if ($companyId) {
            $query->where('company_id', $companyId);
        } elseif (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('account_id'))    $query->where('account_id',    $request->account_id);
        if ($request->filled('is_reconciled')) $query->where('is_reconciled', (bool) $request->is_reconciled);
        if ($request->filled('is_matched'))    $query->where('is_matched',    (bool) $request->is_matched);
        if ($request->filled('date_from'))     $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))       $query->whereDate('date', '<=', $request->date_to);

        $lines = $query->orderByDesc('date')->paginate(100)->withQueryString();

        $summary = $this->computeSummary($companyId ?? $authUser->company_id, $request->account_id ?? null);

        return Inertia::render('Accounting/BankStatements/Index', [
            'lines'             => $lines->through(fn($l) => $this->formatLine($l)),
            'summary'           => $summary,
            'accounts'          => $this->availableBankAccounts($companyId ?? $authUser->company_id),
            'filters'           => $request->only(['account_id', 'is_reconciled', 'is_matched', 'date_from', 'date_to']),
            'companies'         => $authUser->is_super_admin
                                    ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                                    : [],
            'selectedCompanyId' => $companyId,
        ]);
    }

    // ── Store — add bank statement line(s) ────────────────────────
    public function store(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolveBankCompanyId($authUser, $request, true);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create bank_statements'),
            403
        );

        // Support single-line or bulk array
        $isBulk = is_array($request->input('lines'));

        if ($isBulk) {
            $request->validate([
                'lines'               => 'required|array|min:1',
                'lines.*.account_id'  => 'required|integer|exists:accounts,id',
                'lines.*.date'        => 'required|date',
                'lines.*.description' => 'required|string|max:255',
                'lines.*.amount'      => 'required|numeric',
                'lines.*.reference'   => 'nullable|string|max:100',
            ]);

            DB::transaction(function () use ($request, $companyId) {
                foreach ($request->lines as $line) {
                    $this->createLine($line, $companyId);
                }
            });

            return back()->with('success', __('messages.bank_lines_imported', ['count' => count($request->lines)]));
        }

        // Single line
        $validated = $request->validate([
            'account_id'  => 'required|integer|exists:accounts,id',
            'date'        => 'required|date',
            'description' => 'required|string|max:255',
            'amount'      => 'required|numeric',
            'reference'   => 'nullable|string|max:100',
        ]);

        $this->createLine($validated, $companyId);

        return back()->with('success', __('messages.bank_line_created'));
    }

    // ── Update — edit description/date/amount (unreconciled only) ─
    public function update(Request $request, BankStatement $bankStatement)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit bank_statements') &&
             $authUser->company_id === $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $validated = $request->validate([
            'date'        => 'required|date',
            'description' => 'required|string|max:255',
            'amount'      => 'required|numeric',
            'reference'   => 'nullable|string|max:100',
        ]);

        $bankStatement->update($validated);

        return back()->with('success', __('messages.bank_line_updated'));
    }

    // ── Match — link a bank line to a journal entry ───────────────
    public function match(Request $request, BankStatement $bankStatement)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit bank_statements') &&
             $authUser->company_id === $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $request->validate([
            'journal_entry_id' => 'required|integer|exists:journal_entries,id',
        ]);

        $entry = JournalEntry::findOrFail($request->journal_entry_id);
        abort_unless($entry->company_id === $bankStatement->company_id, 403);

        $bankStatement->update([
            'matched_entry_id' => $entry->id,
            'is_matched'       => true,
        ]);

        return back()->with('success', __('messages.bank_line_matched'));
    }

    // ── Unmatch ───────────────────────────────────────────────────
    public function unmatch(BankStatement $bankStatement)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit bank_statements') &&
             $authUser->company_id === $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $bankStatement->update([
            'matched_entry_id' => null,
            'is_matched'       => false,
        ]);

        return back()->with('success', __('messages.bank_line_unmatched'));
    }

    // ── Reconcile — mark line as confirmed ────────────────────────
    public function reconcile(BankStatement $bankStatement)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit bank_statements') &&
             $authUser->company_id === $bankStatement->company_id),
            403
        );

        abort_unless($bankStatement->is_matched, 422, __('messages.bank_line_not_matched'));

        $bankStatement->update(['is_reconciled' => true]);

        return back()->with('success', __('messages.bank_line_reconciled_ok'));
    }

    // ── Destroy (unreconciled only) ───────────────────────────────
    public function destroy(BankStatement $bankStatement)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete bank_statements') &&
             $authUser->company_id === $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $bankStatement->delete();

        return back()->with('success', __('messages.bank_line_deleted'));
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Create a single bank statement line.
     * Guard: account must belong to same company AND be a 14xx/15xx account.
     */
    private function createLine(array $data, int $companyId): void
    {
        $account = Account::where('id', $data['account_id'])
            ->where('company_id', $companyId)
            ->whereRaw("LEFT(code,2) IN ('14','15')")
            ->firstOrFail();

        BankStatement::create([
            'company_id'    => $companyId,
            'account_id'    => $account->id,
            'date'          => $data['date'],
            'description'   => $data['description'],
            'amount'        => $data['amount'],
            'reference'     => $data['reference'] ?? null,
            'is_matched'    => false,
            'is_reconciled' => false,
        ]);
    }

    /**
     * Summary stats: totals, reconciled %, unmatched count.
     * Purely presentational — no financial methodology required.
     */
    private function computeSummary(?int $companyId, ?int $accountId): array
    {
        $query = BankStatement::query();
        if ($companyId) $query->where('company_id', $companyId);
        if ($accountId) $query->where('account_id', $accountId);

        $all        = $query->get(['amount', 'is_matched', 'is_reconciled']);
        $total      = $all->count();
        $reconciled = $all->where('is_reconciled', true)->count();
        $unmatched  = $all->where('is_matched', false)->count();
        $totalIn    = $all->where('amount', '>', 0)->sum('amount');
        $totalOut   = $all->where('amount', '<', 0)->sum('amount');

        return [
            'total_lines'      => $total,
            'reconciled_lines' => $reconciled,
            'unmatched_lines'  => $unmatched,
            'reconciled_pct'   => $total > 0 ? round($reconciled / $total * 100, 1) : 0,
            'total_in'         => round($totalIn, 2),
            'total_out'        => round(abs($totalOut), 2),
        ];
    }

    private function formatLine(BankStatement $l): array
    {
        return [
            'id'               => $l->id,
            'account'          => $l->account
                                    ? $l->account->only(['id', 'code', 'name_en', 'name_ar', 'currency'])
                                    : null,
            'date'             => $l->date,
            'description'      => $l->description,
            'amount'           => $l->amount,
            'reference'        => $l->reference,
            'is_matched'       => (bool) $l->is_matched,
            'is_reconciled'    => (bool) $l->is_reconciled,
            'matched_entry_id' => $l->matched_entry_id,
        ];
    }

    /**
     * BUG FIX — previously used type='asset' which returned ALL asset accounts
     * including inventory, AR, fixed assets, livestock WIP etc.
     *
     * Correct scope: 14xx = Cash Safe accounts, 15xx = Bank accounts ONLY.
     * Matches the same pattern used in PaymentController::availableBankAccounts().
     */
    private function availableBankAccounts(?int $companyId): \Illuminate\Support\Collection
    {
        if (!$companyId) return collect();

        return Account::where('company_id', $companyId)
            ->where('allow_direct_posting', true)
            ->where('is_active', true)
            ->whereRaw("LEFT(code,2) IN ('14','15')")   // 14xx = Safes, 15xx = Banks
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'currency']);
    }

    /**
     * Resolve company_id safely.
     * Super Admin: reads from request (required when $mustExist = true).
     * Regular user: always from auth user record — never from request.
     */
    private function resolveBankCompanyId($authUser, Request $request, bool $mustExist = false): ?int
    {
        if ($authUser->is_super_admin) {
            // §6.5 — session-based company resolution for Super Admin
            $id = (int) $request->session()->get('selected_company_id', 0);
            if ($mustExist) {
                abort_unless($id > 0, 422, __('messages.company_required_for_super_admin'));
            }
            return $id > 0 ? $id : null;
        }
        return $authUser->company_id;
    }
}