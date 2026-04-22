<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;
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
        'evaluation_system',
        'status',
        'church_id',
        'district_id',
        'enrollment_payment_amount',
        'logo_path',
    ];

    protected $casts = [
        'enrollment_payment_amount' => 'decimal:2',
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

    public function pathfinderMembers()
    {
        return $this->hasMany(MemberPathfinder::class, 'club_id');
    }
    public function staffAdventurers()
    {
        return $this->hasMany(StaffAdventurer::class);
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function clubClasses()
    {
        return $this->hasMany(ClubClass::class);
    }

    public function investitureRequests()
    {
        return $this->hasMany(InvestitureRequest::class);
    }

    public function carpetaClassActivations()
    {
        return $this->hasMany(ClubCarpetaClassActivation::class);
    }

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function localObjectives()
    {
        return $this->hasMany(ClubObjective::class)->orderBy('name');
    }

    public function reportsAssistance()
    {
        return $this->hasMany(RepAssistanceAdv::class, 'club_id');
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

}
