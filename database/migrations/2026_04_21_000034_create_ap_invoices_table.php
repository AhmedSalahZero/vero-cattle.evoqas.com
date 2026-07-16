<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — AP Invoices Table
//  Accounts payable invoices — amounts owed to suppliers.
//  Linked to procurement records and inventory purchases.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->onDelete('cascade');

            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->onDelete('set null');

            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('reference')->nullable();
            $table->string('description')->nullable();

            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0)
                ->comment('total - paid, stored for performance');

            $table->string('currency', 3)->default('EGP');

            $table->enum('status', [
                'open',
                'partially_paid',
                'paid',
                'overdue',
                'cancelled',
            ])->default('open');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'due_date']);
            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_invoices');
    }
};