<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\TaskFormResponse;

class EventTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'title',
        'description',
        'assigned_to_user_id',
        'due_at',
        'status',
        'checklist_json',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'checklist_json' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function formResponse()
    {
        return $this->hasOne(TaskFormResponse::class, 'event_task_id');
    }
}
