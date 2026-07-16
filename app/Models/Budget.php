<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'account_id',
        'month',
        'amount',
        'notes',
    ];

    protected $casts = [
        'month'  => 'integer',
        'amount' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}