<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassPlan extends Model
{
    protected $fillable = [
        'workplan_event_id',
        'staff_id',
        'class_id',
        'type',
        'requires_approval',
        'status',
        'request_note',
        'authorized_at',
        'title',
        'description',
        'requested_date',
        'location_override',
        'created_by',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'authorized_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(WorkplanEvent::class, 'workplan_event_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'class_id');
    }
}
