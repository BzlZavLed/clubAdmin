<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StaffAdventurer extends Model
{
    use HasFactory;

    protected $fillable = [
        'date_of_record',
        'name',
        'dob',
        'address',
        'city',
        'state',
        'zip',
        'cell_phone',
        'church_name',
        'club_name',
        'email',
        'assigned_class',
        'club_id',
        'has_health_limitation',
        'health_limitation_description',

        'experiences',
        'award_instruction_abilities',

        'unlawful_sexual_conduct',
        'unlawful_sexual_conduct_records',

        'sterling_volunteer_completed',

        'reference_pastor',
        'reference_elder',
        'reference_other',

        'applicant_signature',
        'application_signed_date',
        'status'
    ];

    protected $casts = [
        'date_of_record' => 'date',
        'dob' => 'date',
        'application_signed_date' => 'date',

        'has_health_limitation' => 'boolean',
        'sterling_volunteer_completed' => 'boolean',
        'unlawful_sexual_conduct' => 'string',

        'experiences' => 'array',
        'award_instruction_abilities' => 'array',
        'unlawful_sexual_conduct_records' => 'array',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function members()
    {
        return $this->hasMany(MemberAdventurer::class, 'staff_id');
    }

    public function assignedClasses()
    {
        return $this->hasMany(ClubClass::class, 'assigned_staff_id');
    }
}
