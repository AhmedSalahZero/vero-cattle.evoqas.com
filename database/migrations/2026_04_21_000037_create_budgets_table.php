<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Budgets Table
//  Annual financial plan entries per account per month.
//  Used for Budget vs Actual variance reporting.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->onDelete('cascade');

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->onDelete('cascade');

            $table->unsignedTinyInteger('month')
                ->comment('1-12');

            $table->decimal('amount', 14, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'account_id', 'month'],
                'budgets_unique');
            $table->index(['fiscal_year_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};