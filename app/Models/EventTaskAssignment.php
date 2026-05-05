<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventTaskAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_task_id',
        'scope_type',
        'scope_id',
        'status',
        'completed_at',
        'completed_by_user_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(EventTask::class, 'event_task_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function formResponse()
    {
        return $this->hasOne(TaskFormResponse::class, 'event_task_assignment_id');
    }
}
