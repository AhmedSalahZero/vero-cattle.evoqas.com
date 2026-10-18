<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: add_max_users_to_companies_table
//
//  Adds a single nullable column `max_users` to the companies table.
//
//  Business rule:
//    • null  → unlimited (no cap set by super admin)
//    • 1–999 → hard ceiling on how many users the company admin
//              may create under that company
//
//  Enforcement happens in UserController@store (PHP) and is
//  surfaced visually in Users/Index.vue (quota bar) and
//  Users/CreateEdit.vue (blocked state when quota is full).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Place it right after commercial_register for logical grouping
            // smallintUnsigned supports 0–65535 which is more than enough
            $table->unsignedSmallInteger('max_users')
                  ->nullable()
                  ->default(null)
                  ->after('commercial_register')
                  ->comment('Max users allowed for this company. NULL = unlimited.');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('max_users');
        });
    }
};