<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SlaughterRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'farm_id',
        'animal_id',
        'lot_id',
        'date',
        'abattoir',
        'liveweight_kg',
        'deadweight_kg',
        'dressing_pct',
        'grade',
        'brand_number',
        'slaughter_cost',
        'currency',
        'journal_entry_id',
        'notes',
    ];

    protected $casts = [
        'date'           => 'date',
        'liveweight_kg'  => 'decimal:2',
        'deadweight_kg'  => 'decimal:2',
        'dressing_pct'   => 'decimal:2',
        'slaughter_cost' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function salesLines()
    {
        return $this->hasMany(SalesLine::class, 'slaughter_id');
    }

    /**
     * The journal entry posted when slaughter costs are recorded.
     * Dr Slaughter Expense / Cr Cash or Accounts Payable
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}