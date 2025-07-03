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
    public function clubClasses()
    {
        return $this->belongsToMany(
            ClubClass::class,
            'class_member_adventurer',      // Pivot table name
            'members_adventurer_id',        // Foreign key on the pivot table that points to this model
            'club_class_id'                 // Foreign key on the pivot table that points to the related model
        )
            ->withPivot(['role', 'assigned_at', 'finished_at', 'active'])
            ->withTimestamps();
    }
    public function currentClass()
    {
        return $this->clubClasses()->wherePivot('active', true)->first();
    }

    public function classHistory()
    {
        return $this->clubClasses()->withPivot('assigned_at', 'finished_at', 'active')->orderBy('pivot_assigned_at', 'desc');
    }

}
