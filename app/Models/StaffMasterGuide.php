<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMasterGuide extends Model
{
    protected $table = 'staff_master_guides';

    protected $fillable = [
        'club_id',
        'staff_id',
        'user_id',
        'staff_name',
        'phone',
        'address',
        'email',
        'dob',
        'has_previous_staff_experience',
        'previous_staff_where',
        'is_invested_master_guide',
        'investment_date',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_email',
        'custom_fields_json',
        'status',
    ];

    protected $casts = [
        'custom_fields_json' => 'array',
        'dob' => 'date',
        'has_previous_staff_experience' => 'boolean',
        'is_invested_master_guide' => 'boolean',
        'investment_date' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
