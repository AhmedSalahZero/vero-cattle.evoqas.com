<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Journal Entry Controller
//
//  📌 METHODOLOGY REFERENCE: JournalBalanceValidation (#1)
//  Every journal entry MUST satisfy: Σ Debit = Σ Credit
//  Formula: |Σdebit − Σcredit| ≤ 0.005  (float-safe tolerance)
//  Enforced in validateBalance() before any DB write.
//
//  📌 METHODOLOGY REFERENCE: JournalEntryAutoRef (#27)
//  Format: JE/[CO3]/YYYY/MM/NNN
//    CO3  = first 3 uppercase letters of company name_en (no spaces)
//    YYYY = 4-digit year from entry date
//    MM   = 2-digit zero-padded month from entry date
//    NNN  = 3-digit zero-padded counter scoped to company+year+month
//  Counter counts all non-reversed entries for company in that period.
//  Example: JE/VER/2026/04/001
//  Applied in store() when reference is left blank by the user.
//  Auto-entries from system events always receive this reference.
//
//  Entry lifecycle: draft → posted → reversed
//  Auto entries (source_type set) are read-only in status/lines.
//  Manual entries (entry_type = 'manual') are fully editable while draft.
//  Reversal creates a mirror entry with all debits/credits swapped.
// ══════════════════════════════════════════════════════════════════

class JournalEntryController extends Controller
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

        $query = JournalEntry::with([
            'fiscalYear:id,year',
            'postedBy:id,name',
        ])->withCount('lines');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        if ($request->filled('status'))          $query->where('status', $request->status);
        if ($request->filled('entry_type'))      $query->where('entry_type', $request->entry_type);
        if ($request->filled('fiscal_year_id'))  $query->where('fiscal_year_id', $request->fiscal_year_id);
        if ($request->filled('date_from'))       $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))         $query->whereDate('date', '<=', $request->date_to);
        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->reference . '%');
        }

        $entries = $query->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->appends($request->query());

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        return Inertia::render('Accounting/JournalEntries/Index', [
            'entries'     => $entries->through(fn($e) => $this->formatEntry($e)),
            'fiscalYears' => $this->availableFiscalYears($authUser),
            'companies'   => $companies,
            'filters'     => $request->only(['status', 'entry_type', 'fiscal_year_id', 'date_from', 'date_to', 'reference']),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────
    public function show(JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('view journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),
            403
        );

        $journalEntry->load([
            'fiscalYear:id,year',
            'postedBy:id,name',
            'lines.account:id,code,name_en,name_ar',
            'lines.lot:id,name',
            'lines.animal:id,tag',
            'lines.farm:id,name_en,name_ar',
        ]);

        return Inertia::render('Accounting/JournalEntries/Show', [
            'entry' => $this->formatEntryFull($journalEntry),
        ]);
    }

    // ── Create (manual entry only) ────────────────────────────────
    public function create(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        // QA-011 (option A): no company dropdown. A Super Admin works inside the company they "Entered".
        $formCompanyId = $authUser->is_super_admin
            ? (int) session('selected_company_id', 0)
            : (int) $authUser->company_id;
        if ($formCompanyId <= 0) {
            return redirect()->route('companies.index')
                ->with('error', 'No company selected. Click Enter on a company first.');
        }

        return Inertia::render('Accounting/JournalEntries/CreateEdit', [
            'accounts'    => $this->availableAccounts($authUser, $formCompanyId),
            'fiscalYears' => $this->availableFiscalYears($authUser, $formCompanyId),
            'companies'   => collect(),
            'mode'        => 'create',
        ]);
    }

    // ── Store (manual entry) ──────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create journal_entries'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        abort_unless($companyId > 0, 422, 'company_id is required.');

        $validated = $this->validateEntryRequest($request);
        $lines     = $this->validateLines($request);

        // 📌 JournalBalanceValidation — Σ debit = Σ credit
        $this->assertBalanced($lines);

        // QA-006/007: lines must use this company's accounts and one side per line
        $this->assertLinesWellFormed($lines, (int) $companyId);

        // 📌 AccountBalanceSufficiencyCheck (#32)
        // Block if any credit line on a 14xx/15xx account exceeds that account's posted balance.
        $this->assertSufficientBalancesForLines($lines, $companyId);

        // The entry must sit in an OPEN fiscal year of this company that covers its date.
        // When none is chosen the year covering the date is used (never "no year").
        $validated['fiscal_year_id'] = $this->resolveOpenFiscalYear(
            (int) $companyId, $validated['fiscal_year_id'] ?? null, $validated['date']
        )->id;

        // ── FIX 5: Auto-generate reference if left blank ──────────
        // 📌 METHODOLOGY REFERENCE: JournalEntryAutoRef (#27)
        $reference = !empty($validated['reference'])
            ? $validated['reference']
            : $this->generateReference($companyId, $validated['date']);

        // QA-005: "Save & Post" — save and post in ONE transaction, so a refusal leaves no stray draft.
        $postNow = $request->boolean('post_immediately');
        DB::transaction(function () use ($validated, $lines, $companyId, $reference, $postNow) {
            $totals = $this->lineTotals($lines);

            $entry = JournalEntry::create([
                'company_id'     => $companyId,
                'fiscal_year_id' => $validated['fiscal_year_id'] ?? null,
                'date'           => $validated['date'],
                'reference'      => $reference,
                'description'    => $validated['description'],
                'period'         => (int) date('n', strtotime($validated['date'])),
                'status'         => 'draft',
                'entry_type'     => 'manual',
                'total_debit'    => $totals['debit'],
                'total_credit'   => $totals['credit'],
                'notes'          => $validated['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit']     ?? 0,
                    'credit'     => $line['credit']    ?? 0,
                    'lot_id'     => $line['lot_id']    ?? null,
                    'animal_id'  => $line['animal_id'] ?? null,
                    'farm_id'    => $line['farm_id']   ?? null,
                    'memo'       => $line['memo']      ?? null,
                    'foreign_currency' => $line['foreign_currency'] ?? null,
                    'foreign_amount'   => $line['foreign_amount']   ?? null,
                    'exchange_rate'    => $line['exchange_rate']     ?? null,
                ]);
            }

            if ($postNow) {
                $this->post($entry->fresh());
            }
        });

        return redirect()->route('journal-entries.index')
            ->with('success', $postNow ? __('messages.journal_entry_posted') : __('messages.journal_entry_created'));
    }

    // ── Edit (draft manual entries only) ─────────────────────────
    public function edit(JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),
            403
        );

        abort_unless($journalEntry->status === 'draft', 422, __('messages.journal_entry_not_draft'));
        abort_unless($journalEntry->entry_type === 'manual', 422, __('messages.journal_entry_auto_locked'));

        $journalEntry->load('lines.account:id,code,name_en,name_ar');

        return Inertia::render('Accounting/JournalEntries/CreateEdit', [
            'entry'       => $this->formatEntryFull($journalEntry),
            'accounts'    => $this->availableAccounts($authUser, $journalEntry->company_id),
            'fiscalYears' => $this->availableFiscalYears($authUser, $journalEntry->company_id),
            'mode'        => 'edit',
        ]);
    }

    // ── Update (draft manual only) ────────────────────────────────
    public function update(Request $request, JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),
            403
        );

        abort_unless($journalEntry->status === 'draft', 422, __('messages.journal_entry_not_draft'));
        abort_unless($journalEntry->entry_type === 'manual', 422, __('messages.journal_entry_auto_locked'));

        $validated = $this->validateEntryRequest($request);
        $lines     = $this->validateLines($request);
        $this->assertBalanced($lines); // 📌 JournalBalanceValidation

        // QA-006/007: lines must use this company's accounts and one side per line
        $this->assertLinesWellFormed($lines, (int) $journalEntry->company_id);

        // 📌 AccountBalanceSufficiencyCheck (#32)
        // Exclude the current entry's own lines so we don't double-count the
        // credits that are being replaced by this update.
        $this->assertSufficientBalancesForLines($lines, $journalEntry->company_id, $journalEntry->id);

        $validated['fiscal_year_id'] = $this->resolveOpenFiscalYear(
            (int) $journalEntry->company_id, $validated['fiscal_year_id'] ?? null, $validated['date']
        )->id;

        $postNow = $request->boolean('post_immediately');
        DB::transaction(function () use ($journalEntry, $validated, $lines, $postNow) {
            $totals = $this->lineTotals($lines);

            $journalEntry->update([
                'fiscal_year_id' => $validated['fiscal_year_id'],
                'date'           => $validated['date'],
                'reference'      => $validated['reference'] ?? $journalEntry->reference,
                'description'    => $validated['description'],
                'period'         => (int) date('n', strtotime($validated['date'])),
                'total_debit'    => $totals['debit'],
                'total_credit'   => $totals['credit'],
                'notes'          => $validated['notes'] ?? null,
            ]);

            $journalEntry->lines()->delete();
            foreach ($lines as $line) {
                $journalEntry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit'      => $line['debit']     ?? 0,
                    'credit'     => $line['credit']    ?? 0,
                    'lot_id'     => $line['lot_id']    ?? null,
                    'animal_id'  => $line['animal_id'] ?? null,
                    'farm_id'    => $line['farm_id']   ?? null,
                    'memo'       => $line['memo']      ?? null,
                    'foreign_currency' => $line['foreign_currency'] ?? null,
                    'foreign_amount'   => $line['foreign_amount']   ?? null,
                    'exchange_rate'    => $line['exchange_rate']     ?? null,
                ]);
            }

            if ($postNow) {
                $this->post($journalEntry->fresh());
            }
        });

        return redirect()->route('journal-entries.show', $journalEntry)
            ->with('success', $postNow ? __('messages.journal_entry_posted') : __('messages.journal_entry_updated'));
    }

    // ── Post (draft → posted) ─────────────────────────────────────
    public function post(JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),   // 'post journal_entries' was never seeded → Spatie threw PermissionDoesNotExist (500) for every non-super-admin
            403
        );

        abort_unless($journalEntry->status === 'draft', 422, __('messages.journal_entry_not_draft'));

        // ✅ BUG-T5 FIX: Re-check fiscal year is still open at post time.
        // A draft entry can be created in an open FY that is later closed.
        // store() checked on creation — post() must check again independently.
        // Re-checked at post time: the year must still be open and must cover the
        // entry date (an entry saved without a year is attached to the right one here).
        $postFy = $this->resolveOpenFiscalYear(
            (int) $journalEntry->company_id,
            $journalEntry->fiscal_year_id,
            $journalEntry->date->toDateString()
        );

        // ✅ RISK-F3 FIX: Wrap in DB::transaction so the balance re-check
        // and the status update are atomic. Without this, a crash between the
        // two operations leaves the entry with an inconsistent state.
        // Phase 5: event dispatch goes INSIDE this transaction.
        DB::transaction(function () use ($journalEntry, $authUser, $postFy) {
            // Lock lines before re-reading — prevents a concurrent update()
            // from modifying a line between our balance check and the status write.
            $lines = $journalEntry->lines()->lockForUpdate()->get()->map(fn($l) => [
                'debit'  => (string) $l->debit,
                'credit' => (string) $l->credit,
            ])->all();

            // 📌 JournalBalanceValidation + BcmathMonetaryPrecision
            $this->assertBalanced($lines);

            // QA-007: never post an entry with no amounts
            $postTotals = $this->lineTotals($lines);
            abort_unless($postTotals['debit'] > 0, 422, 'A journal entry with no amounts cannot be posted.');

            $journalEntry->update([
                'fiscal_year_id' => $postFy->id,
                'status'    => 'posted',
                'posted_by' => $authUser->id,
                'posted_at' => now(),
            ]);

            // Phase 5 hook — fires after status is committed:
            // event(new \App\Events\JournalEntryPosted($journalEntry));
        });

        return back()->with('success', __('messages.journal_entry_posted'));
    }

    // ── Reverse (posted → reversed) ───────────────────────────────
    public function reverse(Request $request, JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),   // 'post journal_entries' was never seeded → Spatie threw PermissionDoesNotExist (500) for every non-super-admin
            403
        );

        abort_unless($journalEntry->status === 'posted', 422, __('messages.journal_entry_not_posted'));

        $validated = $request->validate([
            'date'        => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);

        // The reversal is a new posting: it needs an OPEN year covering its own date
        // (the original may sit in a closed year — the correction goes into an open one).
        $revFy = $this->resolveOpenFiscalYear((int) $journalEntry->company_id, null, $validated['date']);

        DB::transaction(function () use ($journalEntry, $validated, $authUser, $revFy) {
            $reversal = JournalEntry::create([
                'company_id'      => $journalEntry->company_id,
                'reversal_of_id'  => $journalEntry->id,
                'fiscal_year_id'  => $revFy->id,
                'date'            => $validated['date'],
                // 📌 JournalEntryAutoRef: reversal reference prefixed with REV/
                'reference'       => 'REV/' . $journalEntry->reference,
                'description'     => $validated['description'] ?? 'Reversal of ' . $journalEntry->reference,
                'period'          => (int) date('n', strtotime($validated['date'])),
                'status'          => 'posted',
                'entry_type'      => 'reversal',
                'total_debit'     => $journalEntry->total_credit,
                'total_credit'    => $journalEntry->total_debit,
                'posted_by'       => $authUser->id,
                'posted_at'       => now(),
                'notes'           => 'Reversal of entry #' . $journalEntry->id,
            ]);

            // Mirror lines with debits ↔ credits swapped
            foreach ($journalEntry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit'      => $line->credit,
                    'credit'     => $line->debit,
                    'lot_id'     => $line->lot_id,
                    'animal_id'  => $line->animal_id,
                    'farm_id'    => $line->farm_id,
                    'memo'       => 'Reversal: ' . $line->memo,
                    'foreign_currency' => $line->foreign_currency,
                    'foreign_amount'   => $line->foreign_amount,
                    'exchange_rate'    => $line->exchange_rate,
                ]);
            }

            $journalEntry->update(['status' => 'reversed']);
        });

        return back()->with('success', __('messages.journal_entry_reversed'));
    }

    // ── Delete (draft manual only) ────────────────────────────────
    public function destroy(JournalEntry $journalEntry)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete journal_entries') && (int) $authUser->company_id === (int) $journalEntry->company_id),
            403
        );

        abort_unless($journalEntry->status === 'draft', 422, __('messages.journal_entry_not_draft'));
        abort_unless($journalEntry->entry_type === 'manual', 422, __('messages.journal_entry_auto_locked'));

        DB::transaction(function () use ($journalEntry) {
            $journalEntry->lines()->delete();
            $journalEntry->delete();
        });

        return redirect()->route('journal-entries.index')
            ->with('success', __('messages.journal_entry_deleted'));
    }

    // ── SA cascade endpoint: company → fiscal years + accounts ────
    public function dataForCompany(Request $request)
    {
        $authUser = Auth::user();
        abort_unless($authUser->is_super_admin, 403);

        $companyId = (int) $request->input('company_id');
        abort_unless($companyId > 0, 422);

        return response()->json([
            'fiscal_years' => $this->availableFiscalYears($authUser, $companyId),
            'accounts'     => $this->availableAccounts($authUser, $companyId),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * FIX 5 — Auto-generate journal entry reference.
     * 📌 METHODOLOGY REFERENCE: JournalEntryAutoRef (#27)
     *
     * Format: JE/[CO3]/YYYY/MM/NNN
     *   CO3  = first 3 uppercase letters of company name_en (no spaces)
     *   YYYY = 4-digit year from entry date
     *   MM   = 2-digit zero-padded month
     *   NNN  = 3-digit zero-padded counter scoped to company+year+month
     *
     * Counter: COUNT of non-reversed journal entries for this company
     * in the same year+month + 1. Padded to 3 digits; extends naturally if > 999.
     *
     * Examples:
     *   JE/VER/2026/04/001   → first manual entry in April 2026 for VERO company
     *   JE/NAF/2026/04/047   → 47th entry for NAFIA company in April 2026
     *
     * This method is public so it can be called from other controllers
     * (e.g. when auto-posting procurement, slaughter, sale entries in Phase 5).
     */
    public function generateReference(int $companyId, string $date): string
    {
        $company = Company::find($companyId);
        $co3     = strtoupper(substr(preg_replace('/\s+/', '', $company?->name_en ?? 'CO'), 0, 3));

        $year  = date('Y', strtotime($date));
        $month = date('m', strtotime($date));

        $count = JournalEntry::where('company_id', $companyId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('status', '!=', 'reversed')
            ->count();

        $counter = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return "JE/{$co3}/{$year}/{$month}/{$counter}";
    }

    // 📌 AccountBalanceSufficiencyCheck (#32)
    /**
     * For each line that credits a cash/bank account (14xx or 15xx),
     * verify the account's posted balance can cover the credit.
     *
     * Multiple lines on the same account in one entry are summed together
     * before comparing — the entry is treated as an atomic unit.
     *
     * @param array    $lines            Validated lines array from the request
     * @param int      $companyId        Scopes the balance query
     * @param int|null $excludeEntryId   Pass the current entry ID on update() so its
     *                                   existing lines are excluded from the balance,
     *                                   giving us "balance before this edit".
     */
    private function assertSufficientBalancesForLines(array $lines, int $companyId, ?int $excludeEntryId = null): void
    {
        // Group total credits by account_id across all lines in this entry
        // 📌 BcmathMonetaryPrecision — accumulate credits with bcadd, not float addition
        $creditsByAccount = [];
        foreach ($lines as $line) {
            $credit = (string)($line['credit'] ?? 0);
            if (bccomp($credit, '0', 4) <= 0) continue;
            $accountId = (int)$line['account_id'];
            $creditsByAccount[$accountId] = bcadd(
                $creditsByAccount[$accountId] ?? '0',
                $credit,
                4
            );
        }

        if (empty($creditsByAccount)) return;

        // Fetch only 14xx / 15xx accounts from the credited set
        $accounts = Account::whereIn('id', array_keys($creditsByAccount))
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->filter(fn($a) => in_array(substr($a->code, 0, 2), ['14', '15']))
            ->keyBy('id');

        if ($accounts->isEmpty()) return;

        // Build balance query — exclude current entry on update to avoid double-counting
        $balances = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->whereIn('jl.account_id', $accounts->keys()->toArray())
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.company_id', $companyId)
            ->when($excludeEntryId, fn($q) => $q->where('je.id', '!=', $excludeEntryId))
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as balance')
            ->groupBy('jl.account_id')
            ->get()
            ->pluck('balance', 'account_id');

        foreach ($accounts as $accountId => $account) {
            // 📌 BcmathMonetaryPrecision — compare with bccomp, not float subtraction
            // balance < creditAmount - 0.005  →  balance + 0.005 < creditAmount
            $balance      = (string)($balances[$accountId] ?? '0');
            $creditAmount = $creditsByAccount[$accountId]; // already a bcmath string from accumulation above
            $threshold    = bcadd($balance, '0.0050', 4);

            if (bccomp($threshold, $creditAmount, 4) < 0) {
                $name = $account->name_en ?: $account->name_ar ?: $account->code;
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lines' => __(
                        'messages.insufficient_account_balance',
                        [
                            'account'  => $name,
                            'available'=> number_format((float)$balance, 2),
                            'required' => number_format((float)$creditAmount, 2),
                        ]
                    ),
                ]);
            }
        }
    }

    // 📌 JournalBalanceValidation — |Σdebit − Σcredit| ≤ 0.005
    // 📌 BcmathMonetaryPrecision — difference computed with bcsub at scale=4
    /**
     * QA-006 / QA-007 — server-side enforcement of what the form assumes:
     *  - every line's account belongs to THIS company
     *  - a line is either a debit or a credit, never both
     */
    private function assertLinesWellFormed(array $lines, int $companyId): void
    {
        $ids = collect($lines)->pluck('account_id')->filter()->unique()->values();
        $own = Account::where('company_id', $companyId)->whereIn('id', $ids)->count();
        if ($own !== $ids->count()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => __('One or more accounts do not belong to this company.'),
            ]);
        }
        foreach ($lines as $i => $line) {
            if (bccomp((string) ($line['debit'] ?? 0), '0', 4) > 0 && bccomp((string) ($line['credit'] ?? 0), '0', 4) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "lines.$i.debit" => __('A line cannot have both a debit and a credit amount.'),
                ]);
            }
        }
    }

    private function assertBalanced(array $lines): void
    {
        $totals = $this->lineTotals($lines);
        // Use bcmath for the final difference — float subtraction of two already-rounded
        // values is safe here, but bcsub makes the tolerance check exact.
        $diff = bcsub((string)$totals['debit'], (string)$totals['credit'], 4);
        // abs() on a bcmath string: strip leading minus
        $absDiff = ltrim($diff, '-');
        abort_unless(
            bccomp($absDiff, '0.0050', 4) <= 0,
            422,
            __('messages.journal_entry_unbalanced', [
                'debit'  => $totals['debit'],
                'credit' => $totals['credit'],
            ])
        );
    }

    /**
     * Sum debit and credit columns from a lines array using bcmath.
     * 📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision
     *
     * Why: PHP float (IEEE 754) cannot represent most decimal fractions exactly.
     * array_sum() accumulates this error across many lines. bcadd() with scale=4
     * uses string-based arithmetic and is exact for all practical monetary values.
     *
     * Pattern:
     *   - Accumulate at scale=4 (intermediate precision)
     *   - Return as PHP float rounded to 2 decimal places for Eloquent storage
     *   - Never cast DB values to float before entering bcmath
     */
    private function lineTotals(array $lines): array
    {
        // 📌 BcmathMonetaryPrecision — accumulate at scale=4, never use array_sum()
        $debit  = '0';
        $credit = '0';
        foreach ($lines as $line) {
            $debit  = bcadd($debit,  (string)($line['debit']  ?? 0), 4);
            $credit = bcadd($credit, (string)($line['credit'] ?? 0), 4);
        }
        return [
            'debit'  => (float) \App\Support\Money::round($debit, 2),
            'credit' => (float) \App\Support\Money::round($credit, 2),
        ];
    }

    /**
     * The open fiscal year an entry dated $date may be posted into.
     * A given year must belong to the company, be open and cover the date;
     * without one, the open year covering the date is used. Otherwise 422.
     */
    private function resolveOpenFiscalYear(int $companyId, ?int $fiscalYearId, string $date): FiscalYear
    {
        $query = FiscalYear::where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);

        if ($fiscalYearId) {
            $given = FiscalYear::find($fiscalYearId);
            abort_unless($given && (int) $given->company_id === $companyId, 403);
            $query->where('id', $fiscalYearId);
        }

        $fy = $query->first();

        if (!$fy || $fy->status !== 'open') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date' => __('messages.fiscal_year_not_open'),
            ]);
        }

        return $fy;
    }

    private function validateEntryRequest(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => 'nullable|exists:fiscal_years,id',
            'date'           => 'required|date',
            'reference'      => 'nullable|string|max:100',
            'description'    => 'required|string|max:255',
            'notes'          => 'nullable|string|max:1000',
        ]);
    }

    private function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                    => 'required|array|min:2',
            'lines.*.account_id'       => 'required|exists:accounts,id',
            'lines.*.debit'            => 'required|numeric|min:0',
            'lines.*.credit'           => 'required|numeric|min:0',
            'lines.*.lot_id'           => 'nullable|exists:lots,id',
            'lines.*.animal_id'        => 'nullable|exists:animals,id',
            'lines.*.farm_id'          => 'nullable|exists:farms,id',
            'lines.*.memo'             => 'nullable|string|max:255',
            'lines.*.foreign_currency' => 'nullable|string|size:3',
            'lines.*.foreign_amount'   => 'nullable|numeric|min:0',
            'lines.*.exchange_rate'    => 'nullable|numeric|min:0.000001',
        ]);

        return $request->input('lines', []);
    }

    private function formatEntry(JournalEntry $e): array
    {
        return [
            'id'             => $e->id,
            'company_id'     => $e->company_id,
            'fiscal_year_id' => $e->fiscal_year_id,
            'fiscal_year'    => $e->fiscalYear?->only(['id', 'year']),
            'date'           => $e->date instanceof \Carbon\Carbon ? $e->date->toDateString() : $e->date,
            'reference'      => $e->reference,
            'description'    => $e->description,
            'period'         => $e->period,
            'status'         => $e->status,
            'entry_type'     => $e->entry_type,
            'total_debit'    => $e->total_debit,
            'total_credit'   => $e->total_credit,
            'is_balanced'    => bccomp(
                                    ltrim(bcsub((string)$e->total_debit, (string)$e->total_credit, 4), '-'),
                                    '0.0050', 4
                                ) <= 0,  // 📌 BcmathMonetaryPrecision
            'lines_count'    => $e->lines_count ?? 0,
            'posted_by'      => $e->postedBy?->only(['id', 'name']),
            'posted_at'      => $e->posted_at?->format('d M Y H:i'),
            'notes'          => $e->notes,
            'created_at'     => $e->created_at?->format('d M Y'),
        ];
    }

    private function formatEntryFull(JournalEntry $e): array
    {
        return array_merge($this->formatEntry($e), [
            'lines' => $e->lines ? $e->lines->map(fn($l) => [
                'id'               => $l->id,
                'account_id'       => $l->account_id,
                'account'          => $l->account?->only(['id', 'code', 'name_en', 'name_ar', 'currency']),
                'debit'            => $l->debit,
                'credit'           => $l->credit,
                'lot_id'           => $l->lot_id,
                'lot'              => $l->lot?->only(['id', 'name']),
                'animal_id'        => $l->animal_id,
                'animal'           => $l->animal?->only(['id', 'tag']),
                'farm_id'          => $l->farm_id,
                'farm'             => $l->farm?->only(['id', 'name_en', 'name_ar']),
                'memo'             => $l->memo,
                'foreign_currency' => $l->foreign_currency,
                'foreign_amount'   => $l->foreign_amount,
                'exchange_rate'    => $l->exchange_rate,
            ]) : [],
        ]);
    }

    // ── Journal Book Report ───────────────────────────────────────
    // 📌 METHODOLOGY REFERENCE: JournalBookReport (#52)
    // Fetches all posted journal_entries + lines between two dates.
    // Ordered by date ASC, id ASC.
    // Grand totals via bcmath. Both manual and auto entries included.
    public function journalReport(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view journal_entries'),
            403
        );

        $companyId = $this->resolveCompanyId($request);
        abort_unless((int)$companyId > 0, 422, 'Company required');

        // Vue sends date_from / date_to (matches reportForm keys in Index.vue)
        $fromDate = $request->input('date_from');
        $toDate   = $request->input('date_to');

        // Vue prop names (must match Report.vue defineProps exactly):
        //   report      — array of entries
        //   grandDebit  — bcmath string
        //   grandCredit — bcmath string
        //   filters     — { date_from, date_to }
        //   entryCount  — integer
        //   line keys   — account_code, account_en, account_ar, memo, debit, credit

        // If no dates provided, render empty report page
        if (!$fromDate || !$toDate) {
            return Inertia::render('Accounting/JournalEntries/Report', [
                'report'      => [],
                'grandDebit'  => '0.00',
                'grandCredit' => '0.00',
                'filters'     => ['date_from' => $fromDate, 'date_to' => $toDate],
                'entryCount'  => 0,
            ]);
        }

        $entries = JournalEntry::with(['lines.account:id,code,name_en,name_ar'])
            ->where('company_id', $companyId)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function ($entry) {
                return [
                    'id'           => $entry->id,
                    'reference'    => $entry->reference,
                    'date'         => $entry->date instanceof \Carbon\Carbon
                        ? $entry->date->format('Y-m-d')
                        : $entry->date,
                    'entry_type'   => $entry->entry_type,
                    'description'  => $entry->description,
                    'total_debit'  => (float) $entry->total_debit,
                    'total_credit' => (float) $entry->total_credit,
                    'lines'        => $entry->lines->map(fn($l) => [
                        'id'           => $l->id,
                        'account_code' => $l->account?->code,
                        'account_en'   => $l->account?->name_en,   // matches line.account_en in Vue
                        'account_ar'   => $l->account?->name_ar,   // matches line.account_ar in Vue
                        'memo'         => $l->memo,
                        'debit'        => (float) $l->debit,
                        'credit'       => (float) $l->credit,
                    ])->all(),
                ];
            });

        // Grand totals via bcmath (#38)
        $totalDebit  = '0';
        $totalCredit = '0';
        foreach ($entries as $entry) {
            foreach ($entry['lines'] as $line) {
                $totalDebit  = bcadd($totalDebit,  (string) $line['debit'],  2);
                $totalCredit = bcadd($totalCredit, (string) $line['credit'], 2);
            }
        }

        return Inertia::render('Accounting/JournalEntries/Report', [
            'report'      => $entries->values()->all(),
            'grandDebit'  => $totalDebit,
            'grandCredit' => $totalCredit,
            'filters'     => ['date_from' => $fromDate, 'date_to' => $toDate],
            'entryCount'  => $entries->count(),
        ]);
    }

    private function availableFiscalYears($authUser, ?int $companyId = null): array
    {
        $cId = $companyId ?? ($authUser->is_super_admin
        ? DB::table('companies')->orderBy('id')->value('id')
        : $authUser->company_id);
        if (!$cId) return [];

        return FiscalYear::where('company_id', $cId)
            ->where('status', 'open')
            ->orderByDesc('year')
            ->get(['id', 'year', 'start_date', 'end_date', 'status'])
            ->map(fn($fy) => [
                'id'         => $fy->id,
                'year'       => $fy->year,
                'start_date' => $fy->start_date instanceof \Carbon\Carbon
                    ? $fy->start_date->format('Y-m-d')
                    : $fy->start_date,
                'end_date'   => $fy->end_date instanceof \Carbon\Carbon
                    ? $fy->end_date->format('Y-m-d')
                    : $fy->end_date,
                'status'     => $fy->status,
            ])
            ->all();
    }

    private function availableAccounts($authUser, ?int $companyId = null): array
    {
        $cId = $companyId ?? ($authUser->is_super_admin ? null : $authUser->company_id);
        if (!$cId) return [];

        return Account::where('company_id', $cId)
            ->where('is_active', true)
            ->where('allow_direct_posting', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'type', 'normal_balance', 'currency'])
            ->toArray();
    }
}