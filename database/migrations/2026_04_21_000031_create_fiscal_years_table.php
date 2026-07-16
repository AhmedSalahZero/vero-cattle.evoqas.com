<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Fiscal Years Table
//  Defines fiscal years and their 12 monthly periods.
//  Period open/close controls what can be posted.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->unsignedSmallInteger('year')
                ->comment('e.g. 2025, 2026');
            $table->date('start_date');
            $table->date('end_date');

            $table->enum('status', [
                'open',
                'closed',
                'locked',
            ])->default('open');

            $table->timestamp('closed_at')->nullable();

            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();

            $table->unique(['company_id', 'year']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};