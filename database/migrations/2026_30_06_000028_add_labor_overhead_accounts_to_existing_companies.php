<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Database\Seeders\ChartOfAccountsSeeder;

// ══════════════════════════════════════════════════════════════════
//  Adds the 10 Farm Labor & Overhead accounts (Methodology #48)
//  to every company that already exists:
//    2260, 2270, 2271, 2272, 2273, 6310, 6320, 6321, 6322, 6323
//
//  Safe to run more than once — accounts that already exist are skipped.
//  New companies get these accounts automatically from the seeder.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $seeder = new ChartOfAccountsSeeder();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $seeder->seedNewAccountsForCompany((int) $companyId);
        }
    }

    public function down(): void
    {
        // Nothing to undo — accounts may already hold posted transactions.
    }
};
