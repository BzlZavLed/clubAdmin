<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkplanTaskSubmission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workplan_task_assignment_id',
        'workplan_task_id',
        'member_id',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'submitted_via',
        'status',
        'text_response',
        'external_url',
        'form_response_json',
        'metadata',
        'submitted_at',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'form_response_json' => 'array',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(WorkplanTaskAssignment::class, 'workplan_task_assignment_id');
    }

    public function task()
    {
        return $this->belongsTo(WorkplanTask::class, 'workplan_task_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function files()
    {
        return $this->hasMany(WorkplanTaskSubmissionFile::class);
    }
}
