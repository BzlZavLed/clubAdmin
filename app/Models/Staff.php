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

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'assigned_class');
    }
}
