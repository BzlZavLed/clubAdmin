<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FinanceLedgerExportJob extends Model
{
    protected $fillable = [
        'uuid',
        'club_id',
        'user_id',
        'status',
        'filters',
        'files',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'files' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $export): void {
            $export->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
