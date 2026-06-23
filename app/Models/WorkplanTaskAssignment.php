<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkplanTaskAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workplan_task_id',
        'member_id',
        'assigned_by_user_id',
        'status',
        'assigned_at',
        'started_at',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'completed_at',
        'attempts_count',
        'metadata',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts_count' => 'integer',
        'metadata' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(WorkplanTask::class, 'workplan_task_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function submissions()
    {
        return $this->hasMany(WorkplanTaskSubmission::class);
    }

    public function latestSubmission()
    {
        return $this->hasOne(WorkplanTaskSubmission::class)->latestOfMany();
    }
}
