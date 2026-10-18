<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
// unit_cost / total_cost / stock_row_id / account_code are SERVER-SET at start.

class FeedMixingLine extends Model
{
    protected $fillable = [
        'batch_id', 'item_id', 'stock_row_id',
        'qty', 'unit_cost', 'total_cost', 'account_code',
    ];

    protected $casts = [
        'qty'        => 'decimal:3',
        'unit_cost'  => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function batch(): BelongsTo { return $this->belongsTo(FeedMixingBatch::class, 'batch_id'); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class, 'item_id'); }
}
