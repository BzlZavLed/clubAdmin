<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassMemberPathfinder extends Model
{
    protected $table = 'class_member_pathfinder';

    protected $fillable = [
        'member_id',
        'club_class_id',
        'role',
        'assigned_at',
        'finished_at',
        'active',
        'undone_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'assigned_at' => 'date',
        'finished_at' => 'date',
        'undone_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function clubClass()
    {
        return $this->belongsTo(ClubClass::class, 'club_class_id');
    }
}

