<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentChildLinkRequest extends Model
{
    protected $fillable = [
        'parent_user_id',
        'member_type',
        'id_data',
        'member_id',
        'club_id',
        'status',
        'match_factors',
        'matched_count',
        'identity_snapshot',
        'requested_at',
        'expires_at',
        'decided_at',
        'decided_by_user_id',
        'decision_note',
    ];

    protected $casts = [
        'match_factors' => 'array',
        'identity_snapshot' => 'array',
        'requested_at' => 'datetime',
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
