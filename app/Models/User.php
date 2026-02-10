<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_type',
        'sub_role',
        'church_name',
        'church_id',
        'club_id',
        'status'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    public function clubs()
    {
        return $this->belongsToMany(Club::class)
            ->withPivot('status')
            ->withTimestamps();
    }
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function staff()
    {
        return $this->hasOne(Staff::class);
    }

    public function staffClass()
    {
        return $this->hasOneThrough(
            ClubClass::class,
            Staff::class,
            'user_id',      // Staff.user_id
            'id',           // ClubClass primary key
            'id',           // User primary key
            'assigned_class' // Staff.assigned_class -> ClubClass.id
        );
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function createdEvents()
    {
        return $this->hasMany(Event::class, 'created_by_user_id');
    }

}
