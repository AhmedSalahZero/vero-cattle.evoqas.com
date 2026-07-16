<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Chart of Accounts Seeder
//
//  Seeds the standard chart of accounts for a given company.
//  Called from CompanyController::store() after company creation.
//
//  Rules:
//    • All seeded accounts have is_system = true (protected from delete)
//    • Group/header accounts have allow_direct_posting = false
//    • Leaf/posting accounts have allow_direct_posting = true
//    • Repeater groups (Banks, Safes, Loans, etc.) seed the GROUP HEADER
//      only — the user adds sub-accounts via the repeater UI
//    • Codes exactly match the methodology in Account_Chart_Methodology.md
//
//  Structure seeded:
//
//  ASSETS
//    1100  Fixed Assets (group, no posting)
//    1110    Land & Buildings
//    1120    Machinery & Equipment
//    1130    Accumulated Depreciation
//    1200  Long-term Assets (group)
//    1210    Long-term Deposits
//    1220    Projects Under Progress           ← NEW (Session 65c)
//    1300  Current Assets (group)
//    1310    Livestock WIP Inventory          ← THE KEY ACCOUNT
//    1320    Feed Inventory
//    1330    Medicine Inventory
//    1340    Vaccine Inventory
//    1350    Other Inventory
//    1400  Cash — Safe Accounts (group, repeater header)
//    1500  Banks (group, repeater header)
//    1600  Accounts Receivable
//    1610  Notes Receivable
//    1700  Other Debtors (group, repeater header)
//
//  LIABILITIES
//    2100  Long-term Liabilities (group)
//    2110    Long-term Loans (group, repeater header)
//    2200  Current Liabilities (group)
//    2210    Accounts Payable
//    2220    Notes Payable
//    2230    AP Accruals
//    2240    Short-term Banking Facilities (group, repeater header)
//    2250    Other Creditors (group, repeater header)
//
//  EQUITY
//    3000  Equity (group)
//    3100    Paid-in Capital
//    3200    Legal Reserve
//    3300    General Reserve
//    3400    Retained Earnings
//
//  REVENUE
//    4000  Revenue (group)
//    4100    Revenue — Animal Sales (Live)
//    4200    Revenue — Meat / Carcass Sales
//    4300    Other Revenue
//
//  COGS
//    5000  COGS (group)
//    5100    COGS — Animal Cost of Sales
//    5200    Purchase Price Variance          ← accrual vs invoice difference
//
//  OPERATING EXPENSES
//    6000  Operating Expenses (group)
//    6100    Loss on Livestock Mortality
//    6200    Veterinary Consultancy (unallocated)
//    6300    Farm Labour — General
//    6400    Depreciation
//    6500   Utilities
//    6600    Bank Charges
//    6700    Interest Expense
//    6800    Gain / Loss on Asset Disposal
//    6900    G&A Expenses (group)             ← NEW (Session 65c)
//    6910      General Expenses
//    6920      Sales Expenses
//    6930      Marketing Expenses
// ══════════════════════════════════════════════════════════════════

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed the standard chart of accounts for the given company.
     * Called as: (new ChartOfAccountsSeeder)->seedForCompany($companyId)
     */
    public function seedForCompany(int $companyId): void
    {
        // Idempotent — do nothing if this company already has accounts
        if (Account::where('company_id', $companyId)->exists()) {
            return;
        }

        // Build all accounts, tracking code → id for parent assignment
        $idMap = [];

        foreach ($this->accountTree() as $entry) {
            $parentId = isset($entry['parent_code'])
                ? ($idMap[$entry['parent_code']] ?? null)
                : null;

            $account = Account::create([
                'company_id'           => $companyId,
                'parent_id'            => $parentId,
                'code'                 => $entry['code'],
                'name_en'              => $entry['name_en'],
                'name_ar'              => $entry['name_ar'],
                'type'                 => $entry['type'],
                'normal_balance'       => $entry['normal_balance'],
                'is_system'            => true,
                'allow_direct_posting' => $entry['posting'] ?? false,
                'is_active'            => true,
                'notes'                => $entry['notes'] ?? null,
            ]);

            $idMap[$entry['code']] = $account->id;
        }
    }

    /**
     * Laravel seeder run() method — seeds for company 1 only (dev/demo use).
     * Production use goes through seedForCompany().
     */
    public function run(): void
    {
        $this->seedForCompany(1);
    }

    // ── Account tree definition ───────────────────────────────────
    // Order matters: parents must come before their children.
    // 'posting' => true  = allow_direct_posting (leaf accounts)
    // 'posting' => false = group header, cannot receive journal entries
    // ─────────────────────────────────────────────────────────────
    private function accountTree(): array
    {
        return [

            // ── ASSETS ───────────────────────────────────────────
            [
                'code' => '1100', 'name_en' => 'Fixed Assets', 'name_ar' => 'الأصول الثابتة',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
            ],
            [
                'code' => '1110', 'parent_code' => '1100',
                'name_en' => 'Land', 'name_ar' => 'الأراضي ',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1120', 'parent_code' => '1100',
                'name_en' => 'Buildings', 'name_ar' => 'المباني',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1130', 'parent_code' => '1100',
                'name_en' => 'Machinery & Equipment', 'name_ar' => 'الآلات والمعدات',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1140', 'parent_code' => '1100',
                'name_en' => 'Accumulated Depreciation', 'name_ar' => 'مجمع الاهتلاك',
                'type' => 'asset', 'normal_balance' => 'credit', 'posting' => true,
                'notes' => 'Contra asset — normal balance is Credit',
            ],

            [
                'code' => '1200', 'name_en' => 'Long-term Assets', 'name_ar' => 'الأصول طويلة الأجل',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
            ],
            [
                'code' => '1210', 'parent_code' => '1200',
                'name_en' => 'Long-term Deposits', 'name_ar' => 'الودائع طويلة الأجل',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            // FIX 5: Projects Under Progress — accumulates construction/development
            // costs until the asset is ready for use, then reclassified to Fixed Assets.
            [
                'code' => '1220', 'parent_code' => '1200',
                'name_en' => 'Projects Under Progress', 'name_ar' => 'مشاريع تحت التنفيذ',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Accumulates all costs for assets under construction (buildings, ' .
                           'facilities, infrastructure). Reclassify to the appropriate Fixed ' .
                           'Asset account (1120/1130) when construction is complete and the ' .
                           'asset is ready for its intended use.',
            ],

            [
                'code' => '1300', 'name_en' => 'Current Assets', 'name_ar' => 'الأصول المتداولة',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
            ],
            [
                'code' => '1310', 'parent_code' => '1300',
                'name_en' => 'Livestock WIP Inventory', 'name_ar' => 'مخزون الماشية قيد التشغيل',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Carrying value of all live animals. Debited on purchase/cost accumulation, credited on sale/mortality.',
            ],
            [
                'code' => '1320', 'parent_code' => '1300',
                'name_en' => 'Feed Inventory', 'name_ar' => 'مخزون الأعلاف',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1330', 'parent_code' => '1300',
                'name_en' => 'Medicine Inventory', 'name_ar' => 'مخزون الأدوية',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1340', 'parent_code' => '1300',
                'name_en' => 'Vaccine Inventory', 'name_ar' => 'مخزون اللقاحات',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1350', 'parent_code' => '1300',
                'name_en' => 'Other Inventory', 'name_ar' => 'مخزون أخرى',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],

            // Repeater group headers — user adds sub-accounts via UI
            [
                'code' => '1400', 'name_en' => 'Cash — Safe Accounts', 'name_ar' => 'النقدية — حسابات الخزينة',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-account per safe or currency (1401, 1402…)',
            ],
            [
                'code' => '1500', 'name_en' => 'Banks', 'name_ar' => 'البنوك',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-group per bank (1510, 1520…) then current/cover/deposit accounts beneath.',
            ],
            [
                'code' => '1600', 'name_en' => 'Accounts Receivable', 'name_ar' => 'حسابات القبض',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1610', 'name_en' => 'Notes Receivable', 'name_ar' => 'أوراق القبض',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '1700', 'name_en' => 'Other Debtors', 'name_ar' => 'مدينون آخرون',
                'type' => 'asset', 'normal_balance' => 'debit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-account per debtor.',
            ],

            // ── LIABILITIES ───────────────────────────────────────
            [
                'code' => '2100', 'name_en' => 'Long-term Liabilities', 'name_ar' => 'الالتزامات طويلة الأجل',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => false,
            ],
            [
                'code' => '2110', 'parent_code' => '2100',
                'name_en' => 'Long-term Loans', 'name_ar' => 'القروض طويلة الأجل',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-account per loan facility.',
            ],

            [
                'code' => '2200', 'name_en' => 'Current Liabilities', 'name_ar' => 'الالتزامات المتداولة',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => false,
            ],
            [
                'code' => '2210', 'parent_code' => '2200',
                'name_en' => 'Accounts Payable', 'name_ar' => 'حسابات الدفع',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '2220', 'parent_code' => '2200',
                'name_en' => 'Notes Payable', 'name_ar' => 'أوراق الدفع',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '2230', 'parent_code' => '2200',
                'name_en' => 'AP Accruals', 'name_ar' => 'مستحقات الموردين',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => true,
                'notes' => 'Goods received but invoice not yet processed. See Methodology #22 InventoryReceiptAccrual.',
            ],
            [
                'code' => '2240', 'parent_code' => '2200',
                'name_en' => 'Short-term Banking Facilities', 'name_ar' => 'تسهيلات بنكية قصيرة الأجل',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-account per banking facility.',
            ],
            [
                'code' => '2250', 'parent_code' => '2200',
                'name_en' => 'Other Creditors', 'name_ar' => 'دائنون آخرون',
                'type' => 'liability', 'normal_balance' => 'credit', 'posting' => false,
                'notes' => 'Repeater group. Add one sub-account per creditor.',
            ],

            // ── EQUITY ────────────────────────────────────────────
            [
                'code' => '3000', 'name_en' => 'Equity', 'name_ar' => 'حقوق الملكية',
                'type' => 'equity', 'normal_balance' => 'credit', 'posting' => false,
            ],
            [
                'code' => '3100', 'parent_code' => '3000',
                'name_en' => 'Paid-in Capital', 'name_ar' => 'رأس المال المدفوع',
                'type' => 'equity', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '3200', 'parent_code' => '3000',
                'name_en' => 'Legal Reserve', 'name_ar' => 'الاحتياطي القانوني',
                'type' => 'equity', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '3300', 'parent_code' => '3000',
                'name_en' => 'General Reserve', 'name_ar' => 'الاحتياطي العام',
                'type' => 'equity', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '3400', 'parent_code' => '3000',
                'name_en' => 'Retained Earnings', 'name_ar' => 'الأرباح المحتجزة',
                'type' => 'equity', 'normal_balance' => 'credit', 'posting' => true,
                'notes' => 'Target account for Opening Balance equity plug. See Methodology #21 OpeningBalanceEquityPlug.',
            ],

            // ── REVENUE ───────────────────────────────────────────
            [
                'code' => '4000', 'name_en' => 'Revenue', 'name_ar' => 'الإيرادات',
                'type' => 'revenue', 'normal_balance' => 'credit', 'posting' => false,
            ],
            [
                'code' => '4100', 'parent_code' => '4000',
                'name_en' => 'Revenue — Animal Sales (Live)', 'name_ar' => 'إيرادات — مبيعات الماشية الحية',
                'type' => 'revenue', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '4200', 'parent_code' => '4000',
                'name_en' => 'Revenue — Meat / Carcass Sales', 'name_ar' => 'إيرادات — مبيعات اللحوم والذبائح',
                'type' => 'revenue', 'normal_balance' => 'credit', 'posting' => true,
            ],
            [
                'code' => '4300', 'parent_code' => '4000',
                'name_en' => 'Other Revenue', 'name_ar' => 'إيرادات أخرى',
                'type' => 'revenue', 'normal_balance' => 'credit', 'posting' => true,
            ],

            // ── COGS ──────────────────────────────────────────────
            [
                'code' => '5000', 'name_en' => 'Cost of Goods Sold', 'name_ar' => 'تكلفة البضاعة المباعة',
                'type' => 'cogs', 'normal_balance' => 'debit', 'posting' => false,
            ],
            [
                'code' => '5100', 'parent_code' => '5000',
                'name_en' => 'COGS — Animal Cost of Sales', 'name_ar' => 'تكلفة المبيعات — تكلفة الماشية المباعة',
                'type' => 'cogs', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Credited from Livestock WIP (1310) when animal is sold or slaughtered.',
            ],
            [
                'code' => '5200', 'parent_code' => '5000',
                'name_en' => 'Purchase Price Variance', 'name_ar' => 'فروق أسعار الشراء',
                'type' => 'cogs', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Difference between accrued receipt value and actual supplier invoice. Dr = invoice exceeds accrual (unfavourable). Cr = accrual exceeds invoice (favourable). See Methodology #29 ApAccrualInvoiceMatching.',
            ],

            // ── OPERATING EXPENSES ────────────────────────────────
            [
                'code' => '6000', 'name_en' => 'Operating Expenses', 'name_ar' => 'المصروفات التشغيلية',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => false,
            ],
            [
                'code' => '6100', 'parent_code' => '6000',
                'name_en' => 'Loss on Livestock Mortality', 'name_ar' => 'خسارة نفوق الماشية',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'NOT COGS. Period expense when animal dies — carrying value written off from WIP (1310).',
            ],
            [
                'code' => '6200', 'parent_code' => '6000',
                'name_en' => 'Veterinary Consultancy (unallocated)', 'name_ar' => 'استشارات بيطرية (غير مخصصة)',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Only if the vet cost cannot be traced to a specific animal/lot.',
            ],
            [
                'code' => '6300', 'parent_code' => '6000',
                'name_en' => 'Farm Labour — General', 'name_ar' => 'عمالة المزرعة — عامة',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '6400', 'parent_code' => '6000',
                'name_en' => 'Depreciation', 'name_ar' => 'الاهتلاك',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '6500', 'parent_code' => '6000',
                'name_en' => 'Utilities', 'name_ar' => 'المرافق',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '6600', 'parent_code' => '6000',
                'name_en' => 'Bank Charges', 'name_ar' => 'مصاريف بنكية',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '6700', 'parent_code' => '6000',
                'name_en' => 'Interest Expense', 'name_ar' => 'مصروف الفوائد',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
            ],
            [
                'code' => '6800', 'parent_code' => '6000',
                'name_en' => 'Gain / Loss on Asset Disposal', 'name_ar' => 'ربح / خسارة بيع الأصول',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Can be debit (loss) or credit (gain) depending on disposal price vs book value.',
            ],

            // FIX 4: Missing expense categories requested in Session 65c review.
            // General / Sales / Marketing expenses are separate from farm operating
            // expenses (6000 group) — they represent SG&A costs at company level.
            [
                'code' => '6900', 'parent_code' => '6000',
                'name_en' => 'General & Administrative Expenses', 'name_ar' => 'المصروفات العمومية والإدارية',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => false,
                'notes' => 'Group header for non-farm overhead expenses at company level.',
            ],
            [
                'code' => '6910', 'parent_code' => '6900',
                'name_en' => 'General Expenses', 'name_ar' => 'مصروفات عمومية',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Miscellaneous company-level overhead not attributable to farm operations.',
            ],
            [
                'code' => '6920', 'parent_code' => '6900',
                'name_en' => 'Sales Expenses', 'name_ar' => 'مصروفات المبيعات',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Direct costs of selling — commissions, transport to buyer, auction fees.',
            ],
            [
                'code' => '6930', 'parent_code' => '6900',
                'name_en' => 'Marketing Expenses', 'name_ar' => 'مصروفات التسويق',
                'type' => 'expense', 'normal_balance' => 'debit', 'posting' => true,
                'notes' => 'Advertising, promotions, trade shows, digital marketing.',
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
//  VERO — ChartOfAccountsSeeder ADDITION
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §13
//
//  ADD this method to the existing ChartOfAccountsSeeder class.
//  It seeds 10 new accounts required by Methodology #48.
//
//  Idempotent — skips accounts that already exist by code.
//
//  For existing companies, run in tinker:
//    foreach (\App\Models\Company::all() as $c) {
//        (new \Database\Seeders\ChartOfAccountsSeeder)->seedNewAccountsForCompany($c->id);
//        echo 'Done: ' . $c->id . ' - ' . $c->name_en . PHP_EOL;
//    }
//
//  For NEW companies: call seedNewAccountsForCompany() from
//  CompanyController::store() AFTER the existing seedForCompany() call,
//  or merge these accounts directly into accountTree() in the seeder.
// ══════════════════════════════════════════════════════════════════
 
// ── Paste this method inside ChartOfAccountsSeeder class ──────────
 
    /**
     * Seed the 10 new accounts required by Methodology #48.
     *
     * Idempotent — safe to run multiple times on the same company.
     * Skips any account whose code already exists for this company.
     *
     * New accounts:
     *   LIABILITIES:
     *     2260  Accrued Labor Cost              (posting)
     *     2270  Accrued Farm Overheads          (group header — no posting)
     *     2271    Accrued Electricity           (posting)
     *     2272    Accrued Water                 (posting)
     *     2273    Accrued Fuel                  (posting)
     *
     *   EXPENSES:
     *     6310  Labor Cost Variance             (posting)
     *     6320  Farm Overhead Variance          (group header — no posting)
     *     6321    Electricity Variance          (posting)
     *     6322    Water Variance                (posting)
     *     6323    Fuel Variance                 (posting)
     */
    public function seedNewAccountsForCompany(int $companyId): void
    {
        // Build an id-map of all existing accounts for this company
        // so we can resolve parent_id from parent code dynamically
        $existingMap = \App\Models\Account::where('company_id', $companyId)
            ->pluck('id', 'code')
            ->all();
 
        $newAccounts = $this->newAccountTree();
 
        foreach ($newAccounts as $entry) {
            // Skip if already exists
            if (isset($existingMap[$entry['code']])) {
                continue;
            }
 
            $parentId = isset($entry['parent_code'])
                ? ($existingMap[$entry['parent_code']] ?? null)
                : null;
 
            $account = \App\Models\Account::create([
                'company_id'           => $companyId,
                'parent_id'            => $parentId,
                'code'                 => $entry['code'],
                'name_en'              => $entry['name_en'],
                'name_ar'              => $entry['name_ar'],
                'type'                 => $entry['type'],
                'normal_balance'       => $entry['normal_balance'],
                'is_system'            => true,
                'allow_direct_posting' => $entry['posting'] ?? false,
                'is_active'            => true,
                'notes'                => $entry['notes'] ?? null,
            ]);
 
            // Add to map so child accounts can resolve parent_id
            $existingMap[$entry['code']] = $account->id;
        }
    }
 
    /**
     * The 10 new accounts for Methodology #48.
     * Order matters: parents before children.
     */
    private function newAccountTree(): array
    {
        return [
 
            // ── LIABILITIES — Accrued Labor ───────────────────────
            [
                'code'    => '2260',
                'parent_code' => '2200',      // ← add this line
                'name_en' => 'Accrued Labor Cost',
                'name_ar' => 'مستحقات تكلفة العمالة',
                'type'    => 'liability',
                'normal_balance' => 'credit',
                'posting' => true,
                'notes'   => 'Monthly accrual for farm labor cost. ' .
                             'Cr on month-end accrual JE, Dr on settlement. ' .
                             'See Methodology #48 FarmLaborOverheadAbsorption.',
            ],
 
            // ── LIABILITIES — Accrued Farm Overheads (group) ─────
            [
                'code'    => '2270',
                'parent_code' => '2200',      // ← add this line
                'name_en' => 'Accrued Farm Overheads',
                'name_ar' => 'مستحقات تكاليف المزرعة',
                'type'    => 'liability',
                'normal_balance' => 'credit',
                'posting' => false,   // group header — no direct posting
                'notes'   => 'Group header for farm overhead accrual accounts. ' .
                             'Sub-accounts: 2271 Electricity, 2272 Water, 2273 Fuel. ' .
                             'Users add custom types under this group.',
            ],
            [
                'code'        => '2271',
                'parent_code' => '2270',
                'name_en'     => 'Accrued Electricity',
                'name_ar'     => 'مستحقات الكهرباء',
                'type'        => 'liability',
                'normal_balance' => 'credit',
                'posting'     => true,
                'notes'       => 'Monthly electricity cost accrual per farm.',
            ],
            [
                'code'        => '2272',
                'parent_code' => '2270',
                'name_en'     => 'Accrued Water',
                'name_ar'     => 'مستحقات المياه',
                'type'        => 'liability',
                'normal_balance' => 'credit',
                'posting'     => true,
                'notes'       => 'Monthly water cost accrual per farm.',
            ],
            [
                'code'        => '2273',
                'parent_code' => '2270',
                'name_en'     => 'Accrued Fuel',
                'name_ar'     => 'مستحقات الوقود',
                'type'        => 'liability',
                'normal_balance' => 'credit',
                'posting'     => true,
                'notes'       => 'Monthly fuel cost accrual per farm.',
            ],
 
            // ── EXPENSES — Labor Variance ─────────────────────────
            [
                'code'    => '6310',
                'parent_code' => '6000',      // ← add this line
                'name_en' => 'Labor Cost Variance',
                'name_ar' => 'فروق تكلفة العمالة',
                'type'    => 'expense',
                'normal_balance' => 'debit',
                'posting' => true,
                'notes'   => 'Difference between estimated and actual labor cost. ' .
                             'Debit = unfavorable (actual > estimated). ' .
                             'Credit = favorable (actual < estimated). ' .
                             'Period cost — does not flow back to sold animals.',
            ],
 
            // ── EXPENSES — Farm Overhead Variance (group) ─────────
            [
                'code'    => '6320',
                'parent_code' => '6000',      // ← add this line
                'name_en' => 'Farm Overhead Variance',
                'name_ar' => 'فروق تكاليف المزرعة',
                'type'    => 'expense',
                'normal_balance' => 'debit',
                'posting' => false,   // group header
                'notes'   => 'Group header for farm overhead variance accounts. ' .
                             'Sub-accounts: 6321 Electricity, 6322 Water, 6323 Fuel.',
            ],
            [
                'code'        => '6321',
                'parent_code' => '6320',
                'name_en'     => 'Electricity Variance',
                'name_ar'     => 'فروق الكهرباء',
                'type'        => 'expense',
                'normal_balance' => 'debit',
                'posting'     => true,
                'notes'       => 'Electricity actual vs estimated variance.',
            ],
            [
                'code'        => '6322',
                'parent_code' => '6320',
                'name_en'     => 'Water Variance',
                'name_ar'     => 'فروق المياه',
                'type'        => 'expense',
                'normal_balance' => 'debit',
                'posting'     => true,
                'notes'       => 'Water actual vs estimated variance.',
            ],
            [
                'code'        => '6323',
                'parent_code' => '6320',
                'name_en'     => 'Fuel Variance',
                'name_ar'     => 'فروق الوقود',
                'type'        => 'expense',
                'normal_balance' => 'debit',
                'posting'     => true,
                'notes'       => 'Fuel actual vs estimated variance.',
            ],
        ];

   
   }
    
 
}