<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Add subscription_starts_at / subscription_ends_at to companies
//
//  These two nullable date columns enable the
//  CompanySubscriptionService::isExpired() check that was
//  originally commented out in AuthenticatedSessionController.
//
//  NULL  → no subscription enforcement (e.g. internal / dev company)
//  Dates → enforce expiry on login via the middleware
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Placed after 'is_active' — matches logical column grouping
            $table->date('subscription_starts_at')
                  ->nullable()
                  ->after('is_active')
                  ->comment('Start of paid subscription period. NULL = unrestricted.');

            $table->date('subscription_ends_at')
                  ->nullable()
                  ->after('subscription_starts_at')
                  ->comment('Expiry date. If today > this date the login is blocked.');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['subscription_starts_at', 'subscription_ends_at']);
        });
    }
};