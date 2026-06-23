<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkplanEvent extends Model
{
    protected $fillable = [
        'workplan_id',
        'generated_from_rule_id',
        'date',
        'end_date',
        'start_time',
        'end_time',
        'meeting_type',
        'title',
        'description',
        'location',
        'is_offsite',
        'location_tracking_allowed',
        'location_tracking_requires_parent_consent',
        'location_tracking_disclosure',
        'department_id',
        'objective_id',
        'local_objective_id',
        'is_generated',
        'is_edited',
        'status',
        'created_by',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'date' => 'date',
        'end_date' => 'date',
        'is_generated' => 'boolean',
        'is_edited' => 'boolean',
        'is_offsite' => 'boolean',
        'location_tracking_allowed' => 'boolean',
        'location_tracking_requires_parent_consent' => 'boolean',
    ];

    public function workplan()
    {
        return $this->belongsTo(Workplan::class);
    }

    public function rule()
    {
        return $this->belongsTo(WorkplanRule::class, 'generated_from_rule_id');
    }

    public function classPlans()
    {
        return $this->hasMany(ClassPlan::class);
    }

    public function tasks()
    {
        return $this->hasMany(WorkplanTask::class);
    }

    public function locationTrackingSessions()
    {
        return $this->hasMany(LocationTrackingSession::class);
    }

    public function localObjective()
    {
        return $this->belongsTo(ClubObjective::class, 'local_objective_id');
    }
}
