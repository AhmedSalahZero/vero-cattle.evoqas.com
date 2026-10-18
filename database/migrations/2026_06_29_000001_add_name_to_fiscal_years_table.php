<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->string('name')->nullable()->after('company_id');
        });

        // Update the existing fiscal year record to have a name
        DB::table('fiscal_years')->whereNull('name')->update([
            'name' => DB::raw("CONCAT('FY ', year)")
        ]);
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};