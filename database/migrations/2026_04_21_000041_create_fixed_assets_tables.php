<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Fixed Assets Migration
//
//  Creates two tables:
//    1. fixed_assets        — the asset register (one row per asset)
//    2. asset_depreciation_lines — one row per period per asset
//
//  Depreciation methods supported:
//    • straight_line      — equal charge every period
//    • declining_balance  — % applied to net book value each period
//
//  Land is never depreciated (useful_life_months = NULL enforced
//  at application layer for category = 'land').
//
//  Each asset links to three Chart-of-Account rows:
//    asset_account_id     → e.g. 1500 Fixed Assets
//    acc_dep_account_id   → e.g. 1590 Accumulated Depreciation
//    dep_expense_account_id → e.g. 6200 Depreciation Expense
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── Table 1: fixed_assets ─────────────────────────────────
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('farm_id')
                  ->nullable()                      // some assets are company-wide
                  ->constrained('farms')
                  ->nullOnDelete();

            // ── Identity ──────────────────────────────────────────
            $table->string('asset_code')->unique();  // e.g. FA-2024-001
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            $table->enum('category', [
                'land',
                'building',
                'vehicle',
                'equipment',
                'furniture',
                'other',
            ])->default('equipment');

            // ── Financial ─────────────────────────────────────────
            $table->date('purchase_date');
            $table->date('in_service_date')->nullable(); // when depreciation starts
            $table->decimal('purchase_cost', 14, 2);
            $table->decimal('salvage_value', 14, 2)->default(0.00);

            // ── Depreciation setup ────────────────────────────────
            $table->enum('depreciation_method', [
                'straight_line',
                'declining_balance',
                'none',                              // land, non-depreciable
            ])->default('straight_line');

            // useful_life_months: NULL only allowed when method = 'none'
            $table->unsignedSmallInteger('useful_life_months')->nullable();

            // declining_balance rate (e.g. 0.20 = 20% per year)
            $table->decimal('db_rate', 5, 4)->nullable();

            // ── Running totals (updated by DepreciationService) ───
            $table->decimal('accumulated_depreciation', 14, 2)->default(0.00);
            $table->decimal('net_book_value', 14, 2)->storedAs(
                'purchase_cost - accumulated_depreciation'
            );

            // ── Chart of Accounts links ───────────────────────────
            $table->foreignId('asset_account_id')
                  ->constrained('accounts');          // Fixed asset account

            $table->foreignId('acc_dep_account_id')
                  ->constrained('accounts');          // Accumulated depreciation account

            $table->foreignId('dep_expense_account_id')
                  ->constrained('accounts');          // Depreciation expense account

            // ── Status & disposal ─────────────────────────────────
            $table->enum('status', [
                'active',
                'fully_depreciated',
                'disposed',
                'impaired',
            ])->default('active');

            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_proceeds', 14, 2)->nullable();
            $table->foreignId('disposal_journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            // ── Purchase journal entry ────────────────────────────
            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category']);
            $table->index('farm_id');
        });

        // ── Table 2: asset_depreciation_lines ────────────────────
        Schema::create('asset_depreciation_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asset_id')
                  ->constrained('fixed_assets')
                  ->cascadeOnDelete();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('fiscal_year_id')
                  ->constrained('fiscal_years')
                  ->cascadeOnDelete();

            // Period tracking (month 1–12 within the fiscal year)
            $table->unsignedTinyInteger('period_month');   // 1–12
            $table->date('depreciation_date');             // last day of the period

            // Amounts
            $table->decimal('amount', 14, 2);              // charge this period
            $table->decimal('accumulated_to_date', 14, 2); // running total after this line

            // Journal entry created when line is posted
            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes & uniqueness ──────────────────────────────
            // One line per asset per fiscal-year-period
            $table->unique(
                ['asset_id', 'fiscal_year_id', 'period_month'],
                'unique_asset_period'
            );
            $table->index(
                ['company_id', 'fiscal_year_id', 'period_month'],
                'adl_company_fy_month_idx'          // explicit short name — MySQL 64-char limit
            );
            $table->index(
                ['asset_id', 'is_posted'],
                'adl_asset_posted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_lines');
        Schema::dropIfExists('fixed_assets');
    }
};