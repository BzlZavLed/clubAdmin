<?php

// app/Models/ClassMemberAdventurer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassMemberAdventurer extends Model
{
    protected $table = 'class_member_adventurer';

    protected $fillable = [
        'members_adventurer_id',
        'club_class_id',
        'role',
        'assigned_at',
        'finished_at',
        'active',
    ];

    public function member()
    {
        return $this->belongsTo(MemberAdventurer::class, 'members_adventurer_id');
    }

    public function clubClass()
    {
        return $this->belongsTo(ClubClass::class, 'club_class_id');
    }
    public function classAssignments()
    {
        return $this->hasMany(ClassMemberAdventurer::class, 'members_adventurer_id')->orderBy('assigned_at', 'desc');;
    }
}
