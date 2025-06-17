<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberAdventurer extends Model
{
    protected $table = 'members_adventurers';

    protected $fillable = [
        'club_id',
        'club_name',
        'director_name',
        'church_name',
        'applicant_name',
        'birthdate',
        'age',
        'grade',
        'mailing_address',
        'cell_number',
        'emergency_contact',
        'investiture_classes',
        'allergies',
        'physical_restrictions',
        'health_history',
        'parent_name',
        'parent_cell',
        'home_address',
        'email_address',
        'signature',
        'status',
        'notes_deleted'
    ];

    protected $casts = [
        'investiture_classes' => 'array',
        'birthdate' => 'date',
    ];


    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }
    public function staff()
    {
        return $this->belongsTo(StaffAdventurer::class, 'staff_id');
    }
}
