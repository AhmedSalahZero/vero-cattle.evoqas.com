<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name_en',
        'name_ar',
        'type',
        'normal_balance',
        'is_system',
        'allow_direct_posting',
        'is_active',
        'currency',
        'notes',
    ];

    protected $casts = [
        'is_system'            => 'boolean',
        'allow_direct_posting' => 'boolean',
        'is_active'            => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    public function bankStatements()
    {
        return $this->hasMany(BankStatement::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePostable($query)
    {
        return $query->where('allow_direct_posting', true)->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}