<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'type',
        'id_data',
        'club_id',
        'class_id',
        'parent_id',
        'assigned_staff_id',
        'status',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'class_id');
    }
}
