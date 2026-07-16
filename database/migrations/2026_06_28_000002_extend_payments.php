<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 050 — Extend payments table
//
//  📌 METHODOLOGY REFERENCE: CheckCollectionLifecycle (#30)
//  📌 METHODOLOGY REFERENCE: NonPartnerPaymentClassification (#31)
//
//  Adds full lifecycle columns for:
//    • Check collection (received → safe → bank → collected/rejected)
//    • Bank transaction details (bank name mandatory for all bank methods)
//    • Non-partner payments (employee custody/loan, shareholder dividend/injection)
//    • Expense payments (linked to GL account directly)
//
//  BACKWARD COMPATIBILITY:
//    All new columns are nullable — existing payment records unaffected.
//    Old type enum ('incoming'/'outgoing') preserved.
//    New payment_category is the primary routing field going forward.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            // ── Payment Category — primary routing ────────────────
            // Replaces the coarse type='incoming'/'outgoing' for new records.
            // Old records keep type; new records set both for compatibility.
            $table->enum('payment_category', [
                'customer_collection',   // incoming from customer (AR)
                'supplier_payment',      // outgoing to supplier (AP)
                'employee_collection',   // money received back from employee
                'employee_payment',      // money paid to employee
                'shareholder_collection',// capital injection / shareholder loan in
                'shareholder_payment',   // dividend / shareholder loan repayment
                'expense_payment',       // direct expense (insurance, rent, etc.)
                'other_collection',      // misc incoming
                'other_payment',         // misc outgoing
            ])->nullable()->after('type');

            // ── Sub-type — for employee and shareholder categories ──
            // employee_*:     custody | loan_repayment | salary_advance | other
            // shareholder_*:  capital_injection | shareholder_loan | dividend_payment | funding
            $table->string('sub_type', 50)->nullable()->after('payment_category');

            // ── Sub-type comment — free text for 'other' subtypes ──
            $table->string('sub_type_comment', 255)->nullable()->after('sub_type');

            // ── Payee / Payer name (non-partner transactions) ──────
            // When no supplier_id/customer_id exists, store name directly.
            $table->string('payee_name', 255)->nullable()->after('sub_type_comment');

            // ── Bank details — REQUIRED for all bank methods ───────
            // bank_name:       the bank institution name (e.g. "CIB", "NBE")
            // bank_account_id: FK → accounts (14xx/15xx) from Chart of Accounts
            $table->string('bank_name', 100)->nullable()->after('method');
            $table->foreignId('bank_account_id')
                  ->nullable()
                  ->after('bank_name')
                  ->constrained('accounts')
                  ->nullOnDelete();

            // ── Check-specific fields ──────────────────────────────
            // check_number:        the check number as printed
            // check_bank:          the bank the check is drawn on (may differ from receiving bank)
            // check_drawn_on_acct: account number printed on check (optional, for tracing)
            $table->string('check_number', 50)->nullable()->after('bank_account_id');
            $table->string('check_bank', 100)->nullable()->after('check_number');
            $table->string('check_drawn_on_acct', 50)->nullable()->after('check_bank');

            // ── Check lifecycle status ─────────────────────────────
            // 📌 CheckCollectionLifecycle:
            //   in_safe          → check physically held in company safe
            //   under_collection → sent to bank, awaiting clearance
            //   collected        → bank confirmed cleared, funds received
            //   rejected         → bank returned check (insufficient funds etc.)
            //   returned_to_customer → rejected check handed back to customer
            $table->enum('check_status', [
                'in_safe',
                'under_collection',
                'collected',
                'rejected',
                'returned_to_customer',
            ])->nullable()->after('check_drawn_on_acct');

            // Dates for check lifecycle events
            $table->date('check_received_date')->nullable()->after('check_status');
            $table->date('check_sent_to_bank_date')->nullable()->after('check_received_date');
            $table->date('check_collection_date')->nullable()->after('check_sent_to_bank_date');
            $table->date('check_rejection_date')->nullable()->after('check_collection_date');

            // ── Collection bank — where rejected check was re-sent ─
            // When a rejected check is re-submitted to a (possibly different) bank
            $table->string('recollection_bank_name', 100)->nullable()->after('check_rejection_date');

            // ── Non-partner GL account link ────────────────────────
            // For expense_payment and non-invoice transactions:
            // directly debit/credit a GL account instead of AP/AR
            $table->foreignId('expense_account_id')
                  ->nullable()
                  ->after('recollection_bank_name')
                  ->constrained('accounts')
                  ->nullOnDelete();

            // ── AR reversal link (rejected check returned) ─────────
            // When a check is returned to customer, we reverse the AR invoice.
            // This stores the reversal journal entry for audit trail.
            $table->foreignId('reversal_journal_entry_id')
                  ->nullable()
                  ->after('expense_account_id')
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'payment_category'], 'payments_company_category_idx');
            $table->index(['company_id', 'check_status'],     'payments_company_check_status_idx');
            $table->index(['company_id', 'check_bank'],       'payments_company_check_bank_idx');
            $table->index(['check_number', 'check_bank'],     'payments_check_register_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('payments_company_category_idx');
            $table->dropIndex('payments_company_check_status_idx');
            $table->dropIndex('payments_company_check_bank_idx');
            $table->dropIndex('payments_check_register_idx');

            // Drop FK columns
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropConstrainedForeignId('reversal_journal_entry_id');

            // Drop remaining columns
            $table->dropColumn([
                'payment_category',
                'sub_type',
                'sub_type_comment',
                'payee_name',
                'bank_name',
                'check_number',
                'check_bank',
                'check_drawn_on_acct',
                'check_status',
                'check_received_date',
                'check_sent_to_bank_date',
                'check_collection_date',
                'check_rejection_date',
                'recollection_bank_name',
            ]);
        });
    }
};
