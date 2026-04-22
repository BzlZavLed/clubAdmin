<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociationWorkplanEvent extends Model
{
    protected $fillable = [
        'association_id',
        'union_workplan_event_id',
        'year',
        'date',
        'end_date',
        'start_time',
        'end_time',
        'event_type',
        'title',
        'description',
        'location',
        'target_club_types',
        'is_mandatory',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'target_club_types' => 'array',
        'is_mandatory' => 'boolean',
    ];

    public function association()
    {
        return $this->belongsTo(Association::class);
    }

    public function unionEvent()
    {
        return $this->belongsTo(UnionWorkplanEvent::class, 'union_workplan_event_id');
    }
}
