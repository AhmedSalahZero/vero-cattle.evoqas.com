<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  vaccination_records.lot_id
//
//  VaccinationController (batch vaccination, the lot filter, the
//  journal entry) and the VaccinationRecord model all use lot_id,
//  but no migration ever created the column — batch vaccination
//  would fail with "Unknown column 'lot_id'". Found by the new
//  VaccinationEditTest.
//
//  Safe to run on a database that already has the column.
//  Existing rows are filled from the animal's current lot.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vaccination_records', 'lot_id')) {
            Schema::table('vaccination_records', function (Blueprint $table) {
                $table->foreignId('lot_id')->nullable()->after('animal_id')
                      ->constrained('lots')->nullOnDelete();
            });

            DB::statement(
                'UPDATE vaccination_records vr
                 JOIN animals a ON a.id = vr.animal_id
                 SET vr.lot_id = a.lot_id
                 WHERE vr.lot_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vaccination_records', 'lot_id')) {
            Schema::table('vaccination_records', function (Blueprint $table) {
                $table->dropConstrainedForeignId('lot_id');
            });
        }
    }
};
