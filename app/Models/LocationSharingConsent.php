<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationSharingConsent extends Model
{
    protected $fillable = [
        'club_id',
        'member_id',
        'parent_user_id',
        'workplan_event_id',
        'class_plan_id',
        'status',
        'consent_source',
        'terms_version',
        'disclosure_text',
        'granted_at',
        'revoked_at',
        'expires_at',
        'ip',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function event()
    {
        return $this->belongsTo(WorkplanEvent::class, 'workplan_event_id');
    }

    public function classPlan()
    {
        return $this->belongsTo(ClassPlan::class);
    }
}
