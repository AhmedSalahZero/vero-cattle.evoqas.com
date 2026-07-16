<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OdooSyncLog extends Model
{
    use HasFactory;

    protected $table = 'odoo_sync_log';

    protected $fillable = [
        'company_id',
        'direction',
        'model',
        'local_model',
        'local_id',
        'odoo_id',
        'status',
        'payload',
        'error',
        'attempts',
        'last_attempted_at',
        'synced_at',
    ];

    protected $casts = [
        'attempts'          => 'integer',
        'last_attempted_at' => 'datetime',
        'synced_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForLocalRecord($query, string $localModel, int $localId)
    {
        return $query->where('local_model', $localModel)->where('local_id', $localId);
    }
}