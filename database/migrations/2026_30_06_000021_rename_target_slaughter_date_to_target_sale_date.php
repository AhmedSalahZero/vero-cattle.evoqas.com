<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration 071 — Rename target_slaughter_date → target_sale_date
//
//  Reason:
//  VERO is a buy-fatten-sell-LIVE operation. The primary revenue
//  stream is live cattle sales, not slaughter. The old column name
//  implied the wrong business model. This date is the planned date
//  a fattened batch will be ready for live sale — it drives the
//  30-day advance alert on the dashboard so farm managers can
//  arrange buyer contacts, transport, and weighing in advance.
//
//  Slaughter is an edge case for sick or unfit animals only and
//  is handled by the separate slaughter_records table.
//
//  Affected files updated in the same session (Session 65d):
//    - LotController.php         (validateLot, formatLot)
//    - DashboardService.php      (alerts — slaughter_due_30d →
//                                 lots_due_for_sale_30d)
//    - Lots/CreateEdit.vue       (form field, labels, hints)
//    - Lots/Index.vue            (card display, daysLabel helper)
//    - Dashboard/Index.vue       (alert card key — see note below)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->renameColumn('target_slaughter_date', 'target_sale_date');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->renameColumn('target_sale_date', 'target_slaughter_date');
        });
    }
};
