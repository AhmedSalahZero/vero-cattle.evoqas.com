<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  FeedMixingBatch
//  📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
//
//  Status flow:  draft → in_progress → completed
//                          └────────→ cancelled
//  Only a draft can be edited or deleted.
// ══════════════════════════════════════════════════════════════════

class FeedMixingBatch extends Model
{
    protected $fillable = [
        'company_id', 'farm_id', 'location_id', 'output_item_id',
        'reference', 'status', 'start_date', 'finish_date',
        'total_cost', 'total_ingredient_kg', 'finished_qty_kg', 'cost_per_kg',
        'start_journal_entry_id', 'finish_journal_entry_id', 'cancel_journal_entry_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'finish_date'         => 'date',
        'total_cost'          => 'decimal:2',
        'total_ingredient_kg' => 'decimal:3',
        'finished_qty_kg'     => 'decimal:3',
        'cost_per_kg'         => 'decimal:4',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function farm(): BelongsTo { return $this->belongsTo(Farm::class); }
    public function location(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
    public function outputItem(): BelongsTo { return $this->belongsTo(InventoryItem::class, 'output_item_id'); }
    public function lines(): HasMany { return $this->hasMany(FeedMixingLine::class, 'batch_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function startJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'start_journal_entry_id'); }
    public function finishJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'finish_journal_entry_id'); }
    public function cancelJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'cancel_journal_entry_id'); }
}
