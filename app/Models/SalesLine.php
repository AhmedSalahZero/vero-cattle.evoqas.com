<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalesLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'animal_id',
        'slaughter_id',
        'price_per_kg',
        'price_per_head',
        'qty_kg',
        'total',
        'cogs_per_head',
        'gross_margin',
        'notes',
    ];

    protected $casts = [
        'price_per_kg'   => 'decimal:4',
        'price_per_head' => 'decimal:2',
        'qty_kg'         => 'decimal:2',
        'total'          => 'decimal:2',
        'cogs_per_head'  => 'decimal:2',
        'gross_margin'   => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function sale()
    {
        return $this->belongsTo(SalesRecord::class, 'sale_id');
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function slaughterRecord()
    {
        return $this->belongsTo(SlaughterRecord::class, 'slaughter_id');
    }
}