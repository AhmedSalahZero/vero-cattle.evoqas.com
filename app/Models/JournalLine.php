<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalLine extends Model
{
    use HasFactory, SoftDeletes; 

    protected $fillable = [
        'entry_id',
        'account_id',
        'debit',
        'credit',
        'lot_id',
        'animal_id',
        'farm_id',
        'memo',
        'foreign_currency',   // ← add
        'foreign_amount',     // ← add
        'exchange_rate',      // ← add
    ];

    protected $casts = [
        'debit'           => 'decimal:2',
        'credit'          => 'decimal:2',
        'foreign_amount'  => 'decimal:4',
        'exchange_rate'   => 'decimal:6',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}