<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberMasterGuide extends Model
{
    protected $table = 'member_master_guides';

    protected $fillable = [
        'club_id',
        'member_id',
        'club_name',
        'director_name',
        'church_name',
        'applicant_name',
        'phone',
        'address',
        'email',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_email',
        'program_year',
        'custom_fields_json',
        'insurance_paid',
        'insurance_paid_at',
        'enrollment_paid',
        'enrollment_paid_at',
        'status',
        'notes_deleted',
    ];

    protected $casts = [
        'custom_fields_json' => 'array',
        'program_year' => 'integer',
        'insurance_paid' => 'boolean',
        'insurance_paid_at' => 'datetime',
        'enrollment_paid' => 'boolean',
        'enrollment_paid_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
