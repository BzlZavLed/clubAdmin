<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberPastoralCare extends Model
{
    protected $table = 'member_pastoral_care';

    protected $fillable = [
        'member_id',
        'district_id',
        'bible_study_active',
        'bible_study_teacher',
        'bible_study_started_at',
        'baptized_at',
        'mentor_member_id',
        'new_believer_until',
        'notes',
        'status',
        'updated_by',
    ];

    protected $casts = [
        'bible_study_active' => 'boolean',
        'bible_study_started_at' => 'date',
        'baptized_at' => 'date',
        'new_believer_until' => 'date',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function mentorMember()
    {
        return $this->belongsTo(Member::class, 'mentor_member_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
