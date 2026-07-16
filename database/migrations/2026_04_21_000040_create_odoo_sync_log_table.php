<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Odoo Sync Log Table
//  Tracks every push/pull between the cattle app and Odoo.
//  Failed syncs can be retried from the Settings panel.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_sync_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->enum('direction', ['push', 'pull'])
                ->comment('push = cattle → Odoo, pull = Odoo → cattle');

            $table->string('model')
                ->comment('Odoo model e.g. account.move, res.partner');

            $table->string('local_model')->nullable()
                ->comment('Local model e.g. JournalEntry, Supplier');

            $table->unsignedBigInteger('local_id')->nullable()
                ->comment('Local record ID');

            $table->unsignedBigInteger('odoo_id')->nullable()
                ->comment('Odoo record ID after successful sync');

            $table->enum('status', [
                'pending',
                'success',
                'failed',
                'skipped',
            ])->default('pending');

            $table->text('payload')->nullable()
                ->comment('JSON payload sent to Odoo');

            $table->text('error')->nullable()
                ->comment('Error message if failed');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'direction']);
            $table->index(['local_model', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_sync_log');
    }
};