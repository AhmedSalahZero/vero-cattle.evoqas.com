<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Backfill animal_lot_history for animals received/created after
//  migration 059 (the app did not write history rows until now).
//
//  Only animals that are still on the farm (active / reserved /
//  quarantine / transferred) and sit in a lot, and that have NO
//  history row at all, get one open row. entered_at =
//  purchase_date → dob → created_at date.
//  Sold / slaughtered / dead animals are left alone (legacy costing).
//  Safe to run twice: animals that already have a row are skipped.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        DB::table('animals')
            ->whereNull('deleted_at')
            ->whereNotNull('lot_id')
            ->whereIn('status', ['active', 'reserved', 'quarantine', 'transferred'])
            ->whereNotIn('id', DB::table('animal_lot_history')->select('animal_id'))
            ->orderBy('id')
            ->chunkById(500, function ($animals) use ($now) {
                $rows = [];
                foreach ($animals as $a) {
                    $rows[] = [
                        'animal_id'  => $a->id,
                        'lot_id'     => $a->lot_id,
                        'farm_id'    => $a->farm_id,
                        'company_id' => $a->company_id,
                        'entered_at' => $a->purchase_date ?: ($a->dob ?: substr((string) $a->created_at, 0, 10)),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('animal_lot_history')->insert($rows);
            });
    }

    public function down(): void
    {
        // Nothing to undo: the rows are valid history.
    }
};
