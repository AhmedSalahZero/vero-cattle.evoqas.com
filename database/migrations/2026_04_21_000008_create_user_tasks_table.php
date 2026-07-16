<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — User Tasks Table
//  Tracks planned vs actual performance on tasks:
//    expected_* = planned dates/duration
//    actual_*   = real execution dates/duration
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('set null');

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('status', [
                'not_started',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('not_started');

            // ── Expected (planned) ────────────────────────────────
            $table->date('expected_start_date')->nullable();
            $table->unsignedSmallInteger('expected_duration_days')->nullable();
            $table->date('expected_end_date')->nullable();

            // ── Actual (tracked) ──────────────────────────────────
            $table->date('actual_start_date')->nullable();
            $table->unsignedSmallInteger('actual_duration_days')->nullable();
            $table->date('actual_end_date')->nullable();

            $table->boolean('reminder_enabled')->default(true);
            $table->text('completion_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tasks');
    }
};