<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Migration 054
//
//  FA-S1 FIX: Make fixed_assets.name_en nullable.
//
//  Problem:
//    fixed_assets.name_en was NOT NULL in the schema, but
//    FixedAssetController::validateAsset() declared it nullable.
//    An Arabic-only user who fills only name_ar and leaves name_en
//    empty triggers a MySQL NOT NULL constraint violation — a 500
//    server error instead of a user-friendly validation message.
//
//  Fix:
//    Make name_en nullable at the DB level to match the controller
//    validation rule. The cross-field guard in validateAsset()
//    already ensures at least one of name_en / name_ar is non-null,
//    so data integrity is preserved at the application layer.
//
//  Affected table: fixed_assets
//  Affected column: name_en  VARCHAR(255) NOT NULL → NULL
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            // Change name_en from NOT NULL to NULL.
            // name_ar is already nullable — this makes both columns
            // consistent with the bilingual nullable pattern used
            // across all other bilingual tables in VERO.
            $table->string('name_en', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            // WARNING: reverting this on a table that already has NULL
            // name_en rows will fail. Ensure all rows have a name_en
            // value before rolling back.
            $table->string('name_en', 255)->nullable(false)->change();
        });
    }
};
