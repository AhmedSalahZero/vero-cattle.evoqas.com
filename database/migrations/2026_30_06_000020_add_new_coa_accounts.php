<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Session 65c — Add 5 missing CoA accounts to all existing companies
//
//  New accounts:
//    1220  Projects Under Progress      (asset,   posting,  parent: 1200)
//    6900  G&A Expenses                (expense, group,    parent: 6000)
//    6910  General Expenses            (expense, posting,  parent: 6900)
//    6920  Sales Expenses              (expense, posting,  parent: 6900)
//    6930  Marketing Expenses          (expense, posting,  parent: 6900)
//
//  Idempotent — skips any account whose code already exists per company.
//
//  After running this migration, new companies created via CompanyController
//  will get these accounts automatically via the updated ChartOfAccountsSeeder.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')->pluck('id');

        foreach ($companies as $companyId) {
            // Build a code→id map for existing accounts in this company
            $existing = DB::table('accounts')
                ->where('company_id', $companyId)
                ->pluck('id', 'code')
                ->all();

            // ── New accounts in dependency order (parents before children) ──
            $newAccounts = [
                // Fix 5 — Projects Under Progress under Long-term Assets (1200)
                [
                    'code'                 => '1220',
                    'parent_code'          => '1200',
                    'name_en'              => 'Projects Under Progress',
                    'name_ar'              => 'مشاريع تحت التنفيذ',
                    'type'                 => 'asset',
                    'normal_balance'       => 'debit',
                    'allow_direct_posting' => true,
                    'notes'                => 'Accumulates all costs for assets under construction. Reclassify to Fixed Assets when complete.',
                ],
                // Fix 4 — G&A Expenses group under Operating Expenses (6000)
                [
                    'code'                 => '6900',
                    'parent_code'          => '6000',
                    'name_en'              => 'General & Administrative Expenses',
                    'name_ar'              => 'المصروفات العمومية والإدارية',
                    'type'                 => 'expense',
                    'normal_balance'       => 'debit',
                    'allow_direct_posting' => false,   // group header
                    'notes'                => 'Group header for non-farm overhead expenses at company level.',
                ],
                [
                    'code'                 => '6910',
                    'parent_code'          => '6900',
                    'name_en'              => 'General Expenses',
                    'name_ar'              => 'مصروفات عمومية',
                    'type'                 => 'expense',
                    'normal_balance'       => 'debit',
                    'allow_direct_posting' => true,
                    'notes'                => 'Miscellaneous company-level overhead not attributable to farm operations.',
                ],
                [
                    'code'                 => '6920',
                    'parent_code'          => '6900',
                    'name_en'              => 'Sales Expenses',
                    'name_ar'              => 'مصروفات المبيعات',
                    'type'                 => 'expense',
                    'normal_balance'       => 'debit',
                    'allow_direct_posting' => true,
                    'notes'                => 'Direct costs of selling — commissions, transport to buyer, auction fees.',
                ],
                [
                    'code'                 => '6930',
                    'parent_code'          => '6900',
                    'name_en'              => 'Marketing Expenses',
                    'name_ar'              => 'مصروفات التسويق',
                    'type'                 => 'expense',
                    'normal_balance'       => 'debit',
                    'allow_direct_posting' => true,
                    'notes'                => 'Advertising, promotions, trade shows, digital marketing.',
                ],
            ];

            foreach ($newAccounts as $entry) {
                // Skip if this code already exists for this company
                if (isset($existing[$entry['code']])) {
                    continue;
                }

                $parentId = isset($entry['parent_code'])
                    ? ($existing[$entry['parent_code']] ?? null)
                    : null;

                $id = DB::table('accounts')->insertGetId([
                    'company_id'           => $companyId,
                    'parent_id'            => $parentId,
                    'code'                 => $entry['code'],
                    'name_en'              => $entry['name_en'],
                    'name_ar'              => $entry['name_ar'],
                    'type'                 => $entry['type'],
                    'normal_balance'       => $entry['normal_balance'],
                    'is_system'            => true,
                    'allow_direct_posting' => $entry['allow_direct_posting'],
                    'is_active'            => true,
                    'currency'             => null,
                    'notes'                => $entry['notes'],
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                // Add to map so subsequent children can resolve parent_id
                $existing[$entry['code']] = $id;
            }
        }
    }

    public function down(): void
    {
        // Remove only the non-system-critical accounts added by this migration.
        // Safe to drop since they were just created and have no journal lines.
        DB::table('accounts')
            ->whereIn('code', ['1220', '6900', '6910', '6920', '6930'])
            ->where('is_system', true)
            ->delete();
    }
};