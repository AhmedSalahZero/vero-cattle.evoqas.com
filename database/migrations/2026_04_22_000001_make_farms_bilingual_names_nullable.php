<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration
//
//  WHY: The farms table was created with name_en and name_ar both
//  as NOT NULL. This breaks the bilingual rule:
//
//    EN mode user → fills name_en, sends name_ar = null → DB rejects null → 500
//    AR mode user → fills name_ar, sends name_en = null → DB rejects null → 500
//
//  FIX: Make both columns nullable. The application-layer cross-field
//  guard in FarmController::validateFarm() ensures at least one name
//  is always present before the INSERT/UPDATE reaches the DB.
//
//  The same pattern is already used correctly in other bilingual tables:
//    companies.name_ar   → NOT NULL (companies require both — intentional)
//    suppliers.name_ar   → NULL     ✅ already nullable
//    vaccines.name_ar    → NULL     ✅ already nullable
//    accounts.name_ar    → NULL     ✅ already nullable
//
//  After this migration farms.name_en and farms.name_ar both allow NULL,
//  matching the supplier/vaccine/account pattern.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            // Allow null so that English-only users don't break on name_ar
            // and Arabic-only users don't break on name_en.
            // The FarmController cross-field guard ensures at least one
            // is always filled before this reaches the database.
            $table->string('name_en')->nullable()->change();
            $table->string('name_ar')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Re-applying NOT NULL requires all existing rows to have a value.
        // If you have farms with null name_en or name_ar, this down() will
        // fail. Back-fill nulls first if rolling back:
        //   UPDATE farms SET name_en = name_ar WHERE name_en IS NULL;
        //   UPDATE farms SET name_ar = name_en WHERE name_ar IS NULL;
        Schema::table('farms', function (Blueprint $table) {
            $table->string('name_en')->nullable(false)->change();
            $table->string('name_ar')->nullable(false)->change();
        });
    }
};