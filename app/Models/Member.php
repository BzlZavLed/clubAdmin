<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'type',
        'id_data',
        'club_id',
        'class_id',
        'parent_id',
        'assigned_staff_id',
        'status',
        'secure_enrollment_link_id',
        'enrollment_confirmed_at',
        'enrollment_confirmed_by',
        'is_sda',
        'baptism_date',
    ];

    protected $casts = [
        'is_sda' => 'boolean',
        'baptism_date' => 'date',
        'enrollment_confirmed_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function secureEnrollmentLink()
    {
        return $this->belongsTo(ClubParentEnrollmentLink::class, 'secure_enrollment_link_id');
    }

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'class_id');
    }

    public function pastoralCare()
    {
        return $this->hasOne(MemberPastoralCare::class);
    }

    public function masterGuide()
    {
        return $this->hasOne(MemberMasterGuide::class);
    }

    public function notes()
    {
        return $this->hasMany(MemberNote::class)->latest();
    }

    public function mentoredPastoralCare()
    {
        return $this->hasMany(MemberPastoralCare::class, 'mentor_member_id');
    }
}
