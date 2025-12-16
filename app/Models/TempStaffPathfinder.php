<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TempStaffPathfinder extends Model
{
    use HasFactory;

    protected $table = 'temp_staff_pathfinder';

    protected $fillable = [
        'club_id',
        'staff_id',
        'user_id',
        'staff_name',
        'staff_dob',
        'staff_age',
        'staff_email',
        'staff_phone',
    ];

    protected $casts = [
        'staff_dob' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
