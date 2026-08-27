<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubParentEnrollmentLink extends Model
{
    protected $fillable = [
        'club_id',
        'token',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
