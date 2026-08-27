<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PrivacyConsent extends Model
{
    protected $fillable = [
        'user_id',
        'notice_version',
        'notice_hash',
        'subject_email_hash',
        'source',
        'locale',
        'ip',
        'user_agent',
        'consented_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Privacy consent records are append-only.'));
        static::deleting(fn () => throw new LogicException('Privacy consent records are append-only.'));
    }
}
