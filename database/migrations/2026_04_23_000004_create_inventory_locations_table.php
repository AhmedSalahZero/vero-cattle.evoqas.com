<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Inventory Locations
//
//  Physical storage locations within a farm OR at company level.
//  A farm can have many locations (Medicine Store, Feed Warehouse…)
//  Company-level locations have farm_id = NULL (central warehouses).
//
//  Managed by: Company Admin + Accountant roles only.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // NULL = company-level central location (no farm assignment)
            $table->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();

            // Bilingual name — both nullable per §5.3
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();

            $table->enum('type', [
                'warehouse',
                'store_room',
                'cold_storage',
                'cabinet',
                'shed',
                'other',
            ])->default('warehouse');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['company_id', 'is_active']);
            $table->index(['farm_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};
