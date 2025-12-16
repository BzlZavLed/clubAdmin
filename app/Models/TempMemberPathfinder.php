<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TempMemberPathfinder extends Model
{
    use HasFactory;

    protected $table = 'temp_member_pathfinder';

    protected $fillable = [
        'club_id',
        'member_id',
        'nombre',
        'dob',
        'phone',
        'email',
        'father_name',
        'father_phone',
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
