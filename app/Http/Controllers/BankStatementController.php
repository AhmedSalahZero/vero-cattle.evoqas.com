<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
    private const IMPORT_HEADERS = ['date', 'description', 'debit', 'credit', 'balance'];

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
        // QA-031: the Search box was sent by the page but never used here
        if ($request->filled('search')) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->search));
            $query->where(fn ($q) => $q->where('description', 'like', "%{$term}%")
                                       ->orWhere('reference', 'like', "%{$term}%"));
        }

        $lines = $query->orderByDesc('date')->paginate(100)->withQueryString();

        $summary = $this->computeSummary($companyId ?? $authUser->company_id, $request->account_id ?? null);

        return Inertia::render('Accounting/BankStatements/Index', [
            'lines'             => $lines->through(fn($l) => $this->formatLine($l)),
            'summary'           => $summary,
            'accounts'          => $this->availableBankAccounts($companyId ?? $authUser->company_id),
            'filters'           => $request->only(['account_id', 'is_reconciled', 'is_matched', 'date_from', 'date_to', 'search']),
            'lastUploadErrors'  => $request->session()->get(
                $this->uploadErrorsSessionKey($companyId ?? $authUser->company_id),
                ['errors' => []]
            ),
            'companies'         => $authUser->is_super_admin
                                    ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
                                    : [],
            'selectedCompanyId' => $companyId,
        ]);
    }

    // ── Download import template ─────────────────────────────────
    public function downloadTemplate(Request $request)
    {
        $authUser = Auth::user();
        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create bank_statements'),
            403
        );

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = self::IMPORT_HEADERS;
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $sheet->setCellValue('A2', now()->toDateString());
        $sheet->setCellValue('B2', 'Sample bank transfer');
        $sheet->setCellValue('C2', 0);
        $sheet->setCellValue('D2', 1500.00);
        $sheet->setCellValue('E2', 1500.00);

        $sheet->setCellValue('A3', now()->toDateString());
        $sheet->setCellValue('B3', 'Sample supplier payment');
        $sheet->setCellValue('C3', 375.50);
        $sheet->setCellValue('D3', 0);
        $sheet->setCellValue('E3', 1124.50);

        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'bank-statements-template.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ── Upload bank statement template ───────────────────────────
    public function uploadTemplate(Request $request)
    {
        $authUser  = Auth::user();
        $companyId = $this->resolveBankCompanyId($authUser, $request, true);

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create bank_statements'),
            403
        );

        $validated = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'file'       => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $account = Account::where('id', $validated['account_id'])
            ->where('company_id', $companyId)
            ->whereRaw("LEFT(code,2) IN ('14','15')")
            ->firstOrFail();

        $rows = IOFactory::load($validated['file']->getRealPath())
            ->getActiveSheet()
            ->toArray(null, true, true, true);

        if (empty($rows)) {
            return back()->with('error', 'Uploaded file is empty.');
        }

        $headerRow = array_shift($rows);
        $columnMap = $this->buildTemplateColumnMap($headerRow);
        $missingHeaders = array_values(array_diff(self::IMPORT_HEADERS, array_values($columnMap)));

        if (!empty($missingHeaders)) {
            $errors = collect($missingHeaders)->map(fn($header) => [
                'row'    => 1,
                'column' => $header,
                'error'  => "Missing required column: {$header}",
            ])->all();
            return $this->failUploadWithErrors($request, $companyId, $errors);
        }

        $errors = [];
        $linesToInsert = [];
        $excelRow = 2;
        foreach ($rows as $row) {
            $normalized = $this->normalizeImportedRow($row, $columnMap);
            if ($this->isRowEmpty($normalized)) {
                $excelRow++;
                continue;
            }

            $rowErrors = $this->validateImportedRow($normalized, $excelRow);
            if (!empty($rowErrors)) {
                array_push($errors, ...$rowErrors);
                $excelRow++;
                continue;
            }

            $linesToInsert[] = [
                'account_id'   => $account->id,
                'date'         => $normalized['date'],
                'description'  => $normalized['description'],
                'amount'       => (float) $normalized['credit'] - (float) $normalized['debit'],
                'reference'    => null,
            ];
            $excelRow++;
        }

        if (empty($linesToInsert) && empty($errors)) {
            $errors[] = [
                'row'    => 2,
                'column' => 'date',
                'error'  => 'No valid data rows found in uploaded file.',
            ];
        }

        if (!empty($errors)) {
            return $this->failUploadWithErrors($request, $companyId, $errors);
        }

        DB::transaction(function () use ($linesToInsert, $companyId) {
            foreach ($linesToInsert as $line) {
                $this->createLine($line, $companyId);
            }
        });

        $request->session()->forget($this->uploadErrorsSessionKey($companyId));

        return back()->with('success', "Bank statement uploaded successfully ({$this->formatImportCount(count($linesToInsert))} lines).");
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
                'lines.*.amount'      => $this->amountRules(),
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
            'amount'      => $this->amountRules(),
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
             (int) $authUser->company_id === (int) $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $validated = $request->validate([
            'date'        => 'required|date',
            'description' => 'required|string|max:255',
            'amount'      => $this->amountRules(),
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
             (int) $authUser->company_id === (int) $bankStatement->company_id),
            403
        );

        abort_if($bankStatement->is_reconciled, 422, __('messages.bank_line_reconciled'));

        $request->validate([
            'journal_entry_id' => 'required|integer|exists:journal_entries,id',
        ]);

        $entry = JournalEntry::findOrFail($request->journal_entry_id);
        abort_unless((int) $entry->company_id === (int) $bankStatement->company_id, 403);

        // The entry must be a posted one that really moved THIS bank account,
        // and it may be matched to one statement line only.
        $touchesAccount = $entry->status === 'posted'
            && $entry->lines()->where('account_id', $bankStatement->account_id)->exists();
        $alreadyUsed = BankStatement::where('matched_entry_id', $entry->id)
            ->where('id', '!=', $bankStatement->id)->exists();

        if (!$touchesAccount || $alreadyUsed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'journal_entry_id' => __('messages.bank_match_invalid'),
            ]);
        }

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
             (int) $authUser->company_id === (int) $bankStatement->company_id),
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
             (int) $authUser->company_id === (int) $bankStatement->company_id),
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
             (int) $authUser->company_id === (int) $bankStatement->company_id),
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
    /**
     * QA-032 — an amount must be a real, non-zero number that fits the column (14 digits, 2 decimals).
     * Before, 0 and numbers too large for the database (which ended in a server error) were accepted.
     */
    private function amountRules(): array
    {
        return [
            'required', 'numeric', 'between:-999999999999.99,999999999999.99',
            function ($attribute, $value, $fail) {
                if ((float) $value == 0.0) {
                    $fail(__('The amount cannot be zero.'));
                }
            },
        ];
    }

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
            'date'             => $this->ymd($l->date),   // QA-030
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

    private function uploadErrorsSessionKey(?int $companyId): string
    {
        $suffix = $companyId ?: 'global';
        return "bank_statements_last_upload_errors_{$suffix}";
    }

    private function buildTemplateColumnMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $excelColumn => $headerText) {
            $normalizedHeader = strtolower(trim((string) $headerText));
            if (in_array($normalizedHeader, self::IMPORT_HEADERS, true)) {
                $map[$excelColumn] = $normalizedHeader;
            }
        }
        return $map;
    }

    private function normalizeImportedRow(array $excelRow, array $columnMap): array
    {
        $row = [
            'date'        => null,
            'description' => null,
            'debit'       => null,
            'credit'      => null,
            'balance'     => null,
        ];

        foreach ($columnMap as $excelColumn => $fieldName) {
            $value = $excelRow[$excelColumn] ?? null;
            $row[$fieldName] = $this->normalizeImportCellValue($value, $fieldName);
        }

        return $row;
    }

    private function normalizeImportCellValue($value, string $field)
    {
        if ($value === null) return null;
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '') return null;

        if ($field === 'date') {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }
            try {
                return (new \DateTime((string) $value))->format('Y-m-d');
            } catch (\Throwable $e) {
                return (string) $value;
            }
        }

        if (in_array($field, ['debit', 'credit', 'balance'], true)) {
            if (is_numeric($value)) return (float) $value;
            $normalized = str_replace([',', ' '], '', (string) $value);
            return is_numeric($normalized) ? (float) $normalized : $value;
        }

        return $value;
    }

    private function validateImportedRow(array $row, int $excelRow): array
    {
        $validator = Validator::make($row, [
            'date'        => 'required|date',
            'description' => 'required|string|max:255',
            'debit'       => 'nullable|numeric|min:0',
            'credit'      => 'nullable|numeric|min:0',
            'balance'     => 'required|numeric',
        ]);

        $validator->after(function ($v) use ($row) {
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            if ($debit <= 0 && $credit <= 0) {
                $v->errors()->add('debit', 'Either debit or credit must be greater than zero.');
            }
            if ($debit > 0 && $credit > 0) {
                $v->errors()->add('credit', 'Debit and credit cannot both be greater than zero in the same row.');
            }
        });

        if (!$validator->fails()) return [];

        $errors = [];
        foreach ($validator->errors()->messages() as $column => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'row'    => $excelRow,
                    'column' => $column,
                    'error'  => $message,
                ];
            }
        }
        return $errors;
    }

    private function failUploadWithErrors(Request $request, int $companyId, array $errors)
    {
        $payload = [
            'uploaded_at' => now()->toDateTimeString(),
            'errors'      => $errors,
        ];
        $request->session()->put($this->uploadErrorsSessionKey($companyId), $payload);
        return back()->with('error', 'Upload failed. Please review Last Upload Errors.');
    }

    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }
        return true;
    }

    private function formatImportCount(int $count): string
    {
        return number_format($count);
    }
}