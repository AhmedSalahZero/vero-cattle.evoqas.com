<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  VERO — FarmOverheadLine Model
//
//  📌 METHODOLOGY REFERENCE: FarmLaborOverheadAbsorption (#48) §14
//
//  One row per overhead type per monthly allocation record.
//  Carries estimated, actual, and variance amounts.
//
//  Cascade deletes when the parent farm_cost_allocation is deleted
//  (only possible in draft status — enforced at controller layer).
// ══════════════════════════════════════════════════════════════════

class FarmOverheadLine extends Model
{
    protected $fillable = [
        'farm_cost_allocation_id',
        'overhead_type_id',
        'estimated_amount',
        'actual_amount',
        'variance_amount',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
        'actual_amount'    => 'decimal:2',
        'variance_amount'  => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FarmCostAllocation::class, 'farm_cost_allocation_id');
    }

    public function overheadType(): BelongsTo
    {
        return $this->belongsTo(FarmOverheadType::class, 'overhead_type_id');
    }
}