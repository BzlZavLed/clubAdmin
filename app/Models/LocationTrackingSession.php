<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocationTrackingSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'workplan_event_id',
        'class_plan_id',
        'club_class_id',
        'started_by_user_id',
        'ended_by_user_id',
        'status',
        'scheduled_starts_at',
        'scheduled_ends_at',
        'started_at',
        'ended_at',
        'ended_reason',
        'disclosure_text',
        'settings_json',
    ];

    protected $casts = [
        'scheduled_starts_at' => 'datetime',
        'scheduled_ends_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'settings_json' => 'array',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function event()
    {
        return $this->belongsTo(WorkplanEvent::class, 'workplan_event_id');
    }

    public function classPlan()
    {
        return $this->belongsTo(ClassPlan::class);
    }

    public function clubClass()
    {
        return $this->belongsTo(ClubClass::class);
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function participants()
    {
        return $this->hasMany(LocationTrackingParticipant::class);
    }

    public function pings()
    {
        return $this->hasMany(LocationPing::class);
    }

    public function accessLogs()
    {
        return $this->hasMany(LocationAccessLog::class);
    }
}
