<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Migration — add account 1360 "Feed Mixing WIP" to every company
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//
//  Parent: 1300 Current Assets.  Idempotent — skips companies that
//  already have code 1360. New companies get it from the seeder.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {

            $exists = DB::table('accounts')
                ->where('company_id', $companyId)->where('code', '1360')->exists();
            if ($exists) continue;

            $parentId = DB::table('accounts')
                ->where('company_id', $companyId)->where('code', '1300')->value('id');

            DB::table('accounts')->insert([
                'company_id'           => $companyId,
                'parent_id'            => $parentId,
                'code'                 => '1360',
                'name_en'              => 'Feed Mixing WIP',
                'name_ar'              => 'تصنيع الأعلاف قيد التشغيل',
                'type'                 => 'asset',
                'normal_balance'       => 'debit',
                'is_system'            => true,
                'allow_direct_posting' => true,
                'is_active'            => true,
                'currency'             => null,
                'notes'                => 'Ingredient cost of feed batches that are being mixed. Cleared to Feed Inventory (1320) when the batch is finished.',
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only removes the account if nothing has been posted to it
        $ids = DB::table('accounts')->where('code', '1360')->where('is_system', true)->pluck('id');
        foreach ($ids as $id) {
            if (!DB::table('journal_lines')->where('account_id', $id)->exists()) {
                DB::table('accounts')->where('id', $id)->delete();
            }
        }
    }
};
