<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkplanTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'workplan_event_id',
        'class_plan_id',
        'club_class_id',
        'task_form_schema_id',
        'union_carpeta_requirement_id',
        'class_investiture_requirement_id',
        'created_by_user_id',
        'created_by_staff_id',
        'title',
        'description',
        'task_type',
        'assignment_scope',
        'review_mode',
        'allowed_evidence_types',
        'instructions_json',
        'points',
        'opens_at',
        'due_at',
        'closes_at',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'allowed_evidence_types' => 'array',
        'instructions_json' => 'array',
        'points' => 'integer',
        'opens_at' => 'datetime',
        'due_at' => 'datetime',
        'closes_at' => 'datetime',
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

    public function formSchema()
    {
        return $this->belongsTo(TaskFormSchema::class, 'task_form_schema_id');
    }

    public function carpetaRequirement()
    {
        return $this->belongsTo(UnionCarpetaRequirement::class, 'union_carpeta_requirement_id');
    }

    public function investitureRequirement()
    {
        return $this->belongsTo(ClassInvestitureRequirement::class, 'class_investiture_requirement_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function createdByStaff()
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    public function assignments()
    {
        return $this->hasMany(WorkplanTaskAssignment::class);
    }

    public function submissions()
    {
        return $this->hasMany(WorkplanTaskSubmission::class);
    }
}
