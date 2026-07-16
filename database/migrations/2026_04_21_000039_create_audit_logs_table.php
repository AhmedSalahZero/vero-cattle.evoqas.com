<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Audit Logs Table
//  Tracks every create, update, delete action by every user.
//  Complements Spatie Activity Log with cattle-specific fields.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->onDelete('set null');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->string('action')
                ->comment('created, updated, deleted, login, logout, etc.');

            $table->string('model')->nullable()
                ->comment('Model class name e.g. Animal, Lot');

            $table->unsignedBigInteger('model_id')->nullable()
                ->comment('ID of the affected record');

            $table->json('old_values')->nullable()
                ->comment('State before change');

            $table->json('new_values')->nullable()
                ->comment('State after change');

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('url')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['model', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};