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
        'is_sda',
        'baptism_date',
    ];

    protected $casts = [
        'is_sda' => 'boolean',
        'baptism_date' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'class_id');
    }

    public function pastoralCare()
    {
        return $this->hasOne(MemberPastoralCare::class);
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
