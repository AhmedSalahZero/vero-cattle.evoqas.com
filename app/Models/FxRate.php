<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    protected $fillable = [
        'company_id',
        'currency',
        'rate_date',
        'rate',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate'      => 'decimal:6',
    ];

    // ── Relationships ─────────────────────────────────────────────
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Get the latest stored rate for a currency in a company.
     * Returns null if no rate has ever been entered.
     */
    public static function latestFor(int $companyId, string $currency): ?self
    {
        return static::where('company_id', $companyId)
            ->where('currency', $currency)
            ->orderByDesc('rate_date')
            ->first();
    }

    /**
     * Get all latest rates for a company (one row per currency).
     * Returns keyed array: ['USD' => FxRate, 'EUR' => FxRate, ...]
     */
    public static function latestAllFor(int $companyId): array
    {
        $currencies = ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'QAR'];
        $result     = [];

        foreach ($currencies as $currency) {
            $row = static::latestFor($companyId, $currency);
            if ($row) {
                $result[$currency] = $row;
            }
        }

        return $result;
    }
}