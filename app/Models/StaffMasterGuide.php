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
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_email',
        'custom_fields_json',
        'status',
    ];

    protected $casts = [
        'custom_fields_json' => 'array',
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
