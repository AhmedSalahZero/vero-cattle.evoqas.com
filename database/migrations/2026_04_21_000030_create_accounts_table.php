<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Accounts Table
//  Chart of accounts for standalone accounting mode.
//  Pre-seeded with livestock farming accounts.
//  Supports parent/child hierarchy.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->onDelete('set null');

            $table->string('code')
                ->comment('Account code e.g. 1100, 5200');
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            $table->enum('type', [
                'asset',
                'liability',
                'equity',
                'revenue',
                'cogs',
                'expense',
            ]);

            $table->enum('normal_balance', ['debit', 'credit'])
                ->comment('debit = asset/expense, credit = liability/equity/revenue');

            $table->boolean('is_system')
                ->default(false)
                ->comment('System accounts cannot be deleted');

            $table->boolean('allow_direct_posting')
                ->default(true)
                ->comment('False for header/group accounts');

            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};