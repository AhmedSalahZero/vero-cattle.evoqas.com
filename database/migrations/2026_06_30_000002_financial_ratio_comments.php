<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 053 — financial_ratio_comments
//
//  Stores management commentary and action items per financial ratio.
//  Scoped to: company_id + fiscal_year_id + ratio_key
//  One row per (company, fiscal_year, ratio_key) — upserted on save.
//
//  Used by: AnalyticsReportController::saveRatioComment()
//  📌 METHODOLOGY REFERENCE: FinancialRatioAnalysis (#32)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ratio_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->cascadeOnDelete();

            // e.g. 'current_ratio', 'gross_margin_pct', 'debt_to_equity'
            $table->string('ratio_key', 60);

            // Management commentary — free text, bilingual (user types in their locale)
            $table->text('comment')->nullable();

            // Action item — what management will do about this ratio
            $table->text('action')->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One comment row per company + fiscal year + ratio
            $table->unique(['company_id', 'fiscal_year_id', 'ratio_key'], 'frc_company_fy_ratio_unique');

            $table->index(['company_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ratio_comments');
    }
};