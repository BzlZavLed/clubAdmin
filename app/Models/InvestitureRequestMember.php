<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestitureRequestMember extends Model
{
    protected $fillable = [
        'investiture_request_id',
        'member_id',
        'member_name',
        'class_name',
        'requirements_count',
        'completed_requirements_count',
        'status',
        'evaluator_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(InvestitureRequest::class, 'investiture_request_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function requirementReviews()
    {
        return $this->hasMany(InvestitureRequirementReview::class);
    }
}
