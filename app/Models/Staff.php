<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $fillable = [
        'type',
        'id_data',
        'club_id',
        'assigned_class',
        'user_id',
        'status',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Classes this staff member is assigned to (many-to-many).
     */
    public function classes()
    {
        return $this->belongsToMany(ClubClass::class, 'class_staff', 'staff_id', 'club_class_id');
    }

    // Backward alias for legacy usage
    public function assignedClasses()
    {
        return $this->classes();
    }
}
