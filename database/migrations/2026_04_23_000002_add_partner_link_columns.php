<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Partner Link Migration
//
//  Purpose:
//    Implements the "also a customer / also a supplier" pattern.
//    A supplier who is also a customer gets linked via:
//      suppliers.is_customer  = true
//      customers.is_supplier  = true
//      customers.supplier_id  = FK → suppliers.id
//
//  Phase 5 note:
//    In Odoo both map to res.partner with supplier_rank / customer_rank.
//    The supplier_id FK makes the sync trivial — one Odoo partner record
//    can represent both sides.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── Add is_customer flag to suppliers ─────────────────────
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_customer')->default(false)->after('is_active');
        });

        // ── Add is_supplier + supplier_id link to customers ───────
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_supplier')->default(false)->after('is_active');
            $table->foreignId('supplier_id')
                  ->nullable()
                  ->after('is_supplier')
                  ->constrained('suppliers')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['is_supplier', 'supplier_id']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_customer');
        });
    }
};