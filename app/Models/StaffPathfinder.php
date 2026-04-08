<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffPathfinder extends Model
{
    use HasFactory;

    protected $table = 'staff_pathfinders';

    protected $fillable = [
        'club_id',
        'staff_id',
        'user_id',
        'source_temp_staff_pathfinder_id',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
