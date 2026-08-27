<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'entity_label',
        'changes',
        'metadata',
        'error_message',
        'error_class',
        'route',
        'method',
        'url',
        'ip',
        'user_agent',
        'event_uuid',
        'request_id',
        'integrity_hash',
    ];

    protected $casts = [
        'changes' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $log->event_uuid ??= (string) Str::uuid();
            $payload = collect($log->getAttributes())
                ->except(['integrity_hash', 'created_at', 'updated_at'])
                ->sortKeys()
                ->all();
            $log->integrity_hash = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('audit.integrity_key'));
        });

        static::updating(fn () => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit records are append-only.'));
    }
}
