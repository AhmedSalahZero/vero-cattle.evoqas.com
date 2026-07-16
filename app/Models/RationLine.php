<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RationLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'ration_id',
        'feed_item_id',
        'qty_per_head_kg',
        'cost_per_head',
        'cp_contribution',
        'tdn_contribution',
    ];

    protected $casts = [
        'qty_per_head_kg'  => 'decimal:3',
        'cost_per_head'    => 'decimal:4',
        'cp_contribution'  => 'decimal:2',
        'tdn_contribution' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function ration()
    {
        return $this->belongsTo(Ration::class);
    }

    public function feedItem()
    {
        return $this->belongsTo(FeedItem::class);
    }
}