<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    protected $fillable = [
        'user_id',
        'club_name',
        'church_name',
        'director_name',
        'creation_date',
        'pastor_name',
        'conference_name',
        'conference_region',
        'club_type',
        'status',
        'church_id'
    ];
    protected static function booted(): void
    {
        static::addGlobalScope('active', function ($query) {
            $query->where('clubs.status', 'active');
        });
    }
    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function adventurerMembers()
    {
        return $this->hasMany(MemberAdventurer::class, 'club_id');
    }
    public function staffAdventurers()
    {
        return $this->hasMany(StaffAdventurer::class);
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function clubClasses()
    {
        return $this->hasMany(ClubClass::class);
    }

}