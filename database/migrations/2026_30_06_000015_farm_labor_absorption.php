<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO — Migration 066
//  Farm Labor & Overhead Absorption
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48)
//
//  Creates three new tables:
//    1. farm_overhead_types   — company-level overhead category definitions
//    2. farm_cost_allocations — one record per farm per month
//    3. farm_overhead_lines   — itemized overhead amounts per allocation
//
//  Also expands journal_entries.entry_type enum to include:
//    'labor_overhead_accrual'
//    'labor_overhead_settlement'
//
//  Seeds three default overhead types for every existing company:
//    Electricity (2271 / 6321)
//    Water       (2272 / 6322)
//    Fuel        (2273 / 6323)
//
//  NOTE: The 10 new CoA accounts (2260, 2270–2273, 6310, 6320–6323)
//  are seeded separately via ChartOfAccountsSeeder::seedNewAccountsForCompany().
//  Run that seeder after this migration for all existing companies.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. farm_overhead_types ────────────────────────────────
        Schema::create('farm_overhead_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();

            // Maps to the accrued liability account (e.g. 2271, 2272, 2273)
            // Must be under the 2270 group. Validated at application layer.
            $table->foreignId('accrued_account_id')
                  ->constrained('accounts')
                  ->restrictOnDelete();

            // Maps to the variance expense account (e.g. 6321, 6322, 6323)
            // Must be under the 6320 group. Validated at application layer.
            $table->foreignId('variance_account_id')
                  ->constrained('accounts')
                  ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index('company_id');
            $table->index(['company_id', 'is_active']);
        });

        // ── 2. farm_cost_allocations ──────────────────────────────
        // One record per farm per calendar month.
        // Unique constraint: farm_id + year + month
        Schema::create('farm_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('farm_id')->constrained('farms')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->tinyInteger('month')->unsigned();   // 1–12
            $table->smallInteger('year')->unsigned();   // e.g. 2026
            $table->date('month_start');                // YYYY-MM-01
            $table->date('month_end');                  // last day of month

            // ── Labor ────────────────────────────────────────────
            $table->decimal('labor_estimated', 12, 2)->default(0);
            $table->decimal('labor_actual', 12, 2)->nullable();
            // Positive = unfavorable (actual > estimated)
            // Negative = favorable  (actual < estimated)
            $table->decimal('labor_variance', 12, 2)->nullable();

            // Month-end accrual JE: Dr WIP + Dr COGS / Cr Accrued Labor
            $table->foreignId('labor_journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            // Settlement JE: Dr Accrued Labor / Cr Cash + variance
            $table->foreignId('labor_settlement_je_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            // ── Overheads (itemized in farm_overhead_lines) ───────
            // One combined JE covers all overhead types at month end
            $table->foreignId('overhead_journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            $table->foreignId('overhead_settlement_je_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            // ── Computed and stored at post time (audit trail) ────
            // Total animal-days across ALL animals on this farm this month
            $table->unsignedInteger('total_animal_days')->nullable();

            // Labor rate per animal-day = labor_estimated / total_animal_days
            // Stored at scale=8 for audit; bcmath used at all times
            $table->decimal('rate_per_animal_day', 14, 8)->nullable();

            // ── Status ───────────────────────────────────────────
            // draft     → estimates entered, nothing posted
            // posted    → month-end accrual JE posted
            // settled   → actual costs entered, settlement JE posted
            // locked    → fiscal year closed, no further changes
            $table->enum('status', ['draft', 'posted', 'settled', 'locked'])
                  ->default('draft');

            // ── Audit ─────────────────────────────────────────────
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One allocation record per farm per month — enforced at DB level
            $table->unique(['farm_id', 'year', 'month']);
            $table->index(['company_id', 'year', 'month']);
            $table->index(['farm_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies');
        });

        // ── 3. farm_overhead_lines ────────────────────────────────
        // One row per overhead type per allocation month.
        // cascade delete: lines are deleted when allocation is deleted (draft only).
        Schema::create('farm_overhead_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('farm_cost_allocation_id')
                  ->constrained('farm_cost_allocations')
                  ->cascadeOnDelete();

            $table->foreignId('overhead_type_id')
                  ->constrained('farm_overhead_types')
                  ->restrictOnDelete();

            $table->decimal('estimated_amount', 12, 2)->default(0);
            $table->decimal('actual_amount', 12, 2)->nullable();
            // Positive = unfavorable, negative = favorable
            $table->decimal('variance_amount', 12, 2)->nullable();

            $table->timestamps();

            // One line per overhead type per allocation
            // Explicit name — MySQL enforces 64-char limit on index identifiers
            $table->unique(['farm_cost_allocation_id', 'overhead_type_id'], 'fol_allocation_type_unique');
            $table->index('overhead_type_id', 'fol_overhead_type_idx');
        });

        // ── 4. Expand journal_entries.entry_type enum ─────────────
        // Add 'labor_overhead_accrual' and 'labor_overhead_settlement'
        // to the existing enum. MySQL requires rebuilding the column.
        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type ENUM(
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal',
                'procurement',
                'sale',
                'feed_consumption',
                'health',
                'depreciation',
                'payment',
                'labor_overhead_accrual',
                'labor_overhead_settlement'
            ) NOT NULL DEFAULT 'auto'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_overhead_lines');
        Schema::dropIfExists('farm_cost_allocations');
        Schema::dropIfExists('farm_overhead_types');

        // Revert enum — remove the two new values
        DB::statement("
            ALTER TABLE journal_entries
            MODIFY COLUMN entry_type ENUM(
                'auto',
                'manual',
                'opening_balance',
                'closing',
                'reversal',
                'procurement',
                'sale',
                'feed_consumption',
                'health',
                'depreciation',
                'payment'
            ) NOT NULL DEFAULT 'auto'
        ");
    }
};