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
     * Staff assigned to this class (many-to-many).
     */
    public function staff()
    {
        return $this->belongsToMany(Staff::class, 'class_staff', 'club_class_id', 'staff_id');
    }
}
