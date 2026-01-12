<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubIntegrationConfig extends Model
{
    protected $fillable = [
        'club_id',
        'invite_code',
        'status',
        'church_id',
        'church_name',
        'church_slug',
        'departments',
        'objectives',
        'fetched_at',
    ];

    protected $casts = [
        'departments' => 'array',
        'objectives' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
