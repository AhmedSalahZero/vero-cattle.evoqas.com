<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Fiscal Year Controller
//  Manages fiscal year records per company. Lifecycle:
//    open → closed → locked
//  Only one fiscal year may be 'open' per company at any time.
//  Closing a year prevents new journal entries for that period.
//  Locking prevents any further edits including re-opening.
//  Financial calculations (trial balance, P&L) are done in
//  ReportController / Services — not here.
// ══════════════════════════════════════════════════════════════════

class FiscalYearController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view fiscal_years'),
            403
        );

        $query = FiscalYear::withCount('journalEntries');

        if (!$authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        $years = $query->orderByDesc('year')->get()
            ->map(fn($y) => $this->formatYear($y));

        // Super admin needs company list to pick which company the FY belongs to
        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        return Inertia::render('Accounting/FiscalYears/Index', [
            'years'     => $years,
            'companies' => $companies,
        ]);
    }

    // ── Store — create a new fiscal year ─────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create fiscal_years'),
            403
        );

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $validated = $request->validate([
            'year'       => [
                'required', 'integer', 'min:2000', 'max:2099',
                Rule::unique('fiscal_years')->where('company_id', $companyId),
            ],
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
        ]);

        // Ensure no date overlap with existing years for this company
        $overlap = FiscalYear::where('company_id', $companyId)
            ->where(fn($q) =>
                $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                  ->orWhereBetween('end_date',   [$validated['start_date'], $validated['end_date']])
                  ->orWhere(fn($q2) =>
                      $q2->where('start_date', '<=', $validated['start_date'])
                         ->where('end_date',   '>=', $validated['end_date'])
                  )
            )->exists();

        abort_if($overlap, 422, __('messages.fiscal_year_date_overlap'));

        FiscalYear::create([
            'company_id' => $companyId,
            'year'       => $validated['year'],
            'start_date' => $validated['start_date'],
            'end_date'   => $validated['end_date'],
            'status'     => 'open',
        ]);

        return redirect()->route('fiscal-years.index')
            ->with('success', __('messages.fiscal_year_created'));
    }

    // ── Close a fiscal year ───────────────────────────────────────
    public function close(Request $request, FiscalYear $fiscalYear)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit fiscal_years') && $authUser->company_id === $fiscalYear->company_id),
            403
        );

        abort_unless($fiscalYear->status === 'open', 422, __('messages.fiscal_year_not_open'));

        // Guard: no draft journal entries may remain open in this year
        $draftCount = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
            ->where('status', 'draft')
            ->count();

        abort_if($draftCount > 0, 422, __('messages.fiscal_year_has_drafts', ['count' => $draftCount]));

        $fiscalYear->update([
            'status'    => 'closed',
            'closed_at' => now(),
            'closed_by' => $authUser->id,
        ]);

        return back()->with('success', __('messages.fiscal_year_closed'));
    }

    // ── Lock a fiscal year (irreversible) ─────────────────────────
    public function lock(FiscalYear $fiscalYear)
    {
        $authUser = Auth::user();

        // Only Farm Owner or Super Admin may lock
        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasRole('Farm Owner') && $authUser->company_id === $fiscalYear->company_id),
            403
        );

        abort_unless($fiscalYear->status === 'closed', 422, __('messages.fiscal_year_not_closed'));

        $fiscalYear->update(['status' => 'locked']);

        return back()->with('success', __('messages.fiscal_year_locked'));
    }

    // ── Reopen (closed → open, NOT locked) ───────────────────────
    public function reopen(FiscalYear $fiscalYear)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasRole('Farm Owner') && $authUser->company_id === $fiscalYear->company_id),
            403
        );

        abort_unless($fiscalYear->status === 'closed', 422, __('messages.fiscal_year_cannot_reopen'));

        $fiscalYear->update([
            'status'    => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return back()->with('success', __('messages.fiscal_year_reopened'));
    }

    // ── Destroy (only if no journal entries) ─────────────────────
    public function destroy(FiscalYear $fiscalYear)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete fiscal_years') && $authUser->company_id === $fiscalYear->company_id),
            403
        );

        abort_if($fiscalYear->status === 'locked', 422, __('messages.fiscal_year_locked_protected'));
        abort_if($fiscalYear->journalEntries()->exists(), 422, __('messages.fiscal_year_has_entries'));

        $fiscalYear->delete();

        return redirect()->route('fiscal-years.index')
            ->with('success', __('messages.fiscal_year_deleted'));
    }

    // ── Private helpers ───────────────────────────────────────────

    private function formatYear(FiscalYear $y): array
    {
        return [
            'id'                    => $y->id,
            'year'                  => $y->year,
            // Cast to plain Y-m-d string — Carbon objects serialize to ISO timestamps in JSON
            'start_date'            => $y->start_date?->format('Y-m-d'),
            'end_date'              => $y->end_date?->format('Y-m-d'),
            'status'                => $y->status,
            'closed_at'             => $y->closed_at?->format('Y-m-d'),
            'journal_entries_count' => $y->journal_entries_count ?? 0,
        ];
    }
}