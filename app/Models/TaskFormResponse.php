<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskFormResponse extends Model
{
    protected $fillable = [
        'event_task_id',
        'event_task_assignment_id',
        'schema_key',
        'data_json',
    ];

    protected $casts = [
        'data_json' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(EventTask::class, 'event_task_id');
    }

    public function assignment()
    {
        return $this->belongsTo(EventTaskAssignment::class, 'event_task_assignment_id');
    }
}
