<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PublicMemberEvidenceAccessCode extends Model
{
    protected $fillable = [
        'member_id',
        'club_id',
        'code_hash',
        'code_encrypted',
        'label',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'last_used_ip',
        'last_used_user_agent',
        'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function makePlainCode(): string
    {
        return Str::random(48);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at) {
            return false;
        }

        return !$this->expires_at || $this->expires_at->isFuture();
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
