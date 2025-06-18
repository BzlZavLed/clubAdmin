<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClubClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'class_order',
        'class_name',
        'assigned_staff_id',
    ];

    /**
     * Relationship: ClubClass belongs to a Club
     */
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Relationship: ClubClass is assigned to a StaffAdventurer
     */
    public function assignedStaff()
    {
        return $this->belongsTo(StaffAdventurer::class, 'assigned_staff_id');
    }
}
