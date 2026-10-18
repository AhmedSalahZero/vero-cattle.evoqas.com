<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Update Users Table
//  Adds cattle-specific fields to the default Laravel users table:
//  - company ownership
//  - profile fields (bilingual name, phone, avatar)
//  - language preference
//  - active/inactive status
//  - super admin flag
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // ── Company Ownership ─────────────────────────────────
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->onDelete('cascade');

            // ── Bilingual Name ────────────────────────────────────
            $table->string('name_en')->nullable()->after('name');
            $table->string('name_ar')->nullable()->after('name_en');

            // ── Profile ───────────────────────────────────────────
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar')->nullable()->after('phone');
            $table->string('job_title_en')->nullable()->after('avatar');
            $table->string('job_title_ar')->nullable()->after('job_title_en');

            // ── Preferences ───────────────────────────────────────
            $table->enum('language', ['en', 'ar'])
                ->default('en')
                ->after('job_title_ar');
            $table->enum('theme', ['light', 'dark'])
                ->default('light')
                ->after('language');

            // ── Status & Access ───────────────────────────────────
            $table->boolean('is_active')
                ->default(true)
                ->after('theme');
            $table->boolean('is_super_admin')
                ->default(false)
                ->after('is_active')
                ->comment('Super admins have access across all companies');

            // ── Timestamps ────────────────────────────────────────
            $table->timestamp('last_login_at')
                ->nullable()
                ->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'company_id',
                'name_en',
                'name_ar',
                'phone',
                'avatar',
                'job_title_en',
                'job_title_ar',
                'language',
                'theme',
                'is_active',
                'is_super_admin',
                'last_login_at',
            ]);
        });
    }
};