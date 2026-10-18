<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Cattle PO breed/weight estimate line.
// One row per breed/sex/weight category per PO.
// These are optional reference rows — NOT individual animals.
// Animals are created via AnimalReceiptBatch → Animal chain.
//
// 📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)

class ProcurementLine extends Model
{
    use HasFactory;

    protected $table = 'procurement_lines';

    protected $fillable = [
        'procurement_id',
        'animal_id',
        'weight_kg',
        'price_per_head',
        'price_per_kg',
        'freight_allocated',
        'total_cost',
        'notes',
        'breed',
        'sex',
        'estimated_weight_kg',
    ];

    protected $casts = [
        'weight_kg'           => 'decimal:2',
        'price_per_head'      => 'decimal:2',
        'price_per_kg'        => 'decimal:4',
        'freight_allocated'   => 'decimal:2',
        'total_cost'          => 'decimal:2',
        'estimated_weight_kg' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRecord::class, 'procurement_id');
    }

    /**
     * The specific animal linked to this line (if any).
     * NULL for pre-receipt estimate rows.
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}