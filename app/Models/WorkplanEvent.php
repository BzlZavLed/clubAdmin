<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkplanEvent extends Model
{
    protected $fillable = [
        'workplan_id',
        'generated_from_rule_id',
        'date',
        'start_time',
        'end_time',
        'meeting_type',
        'title',
        'description',
        'location',
        'department_id',
        'objective_id',
        'is_generated',
        'is_edited',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'is_generated' => 'boolean',
        'is_edited' => 'boolean',
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
}
