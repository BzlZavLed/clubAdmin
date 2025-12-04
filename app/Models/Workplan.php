<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workplan extends Model
{
    protected $fillable = [
        'club_id',
        'start_date',
        'end_date',
        'default_sabbath_location',
        'default_sunday_location',
        'default_sabbath_start_time',
        'default_sabbath_end_time',
        'default_sunday_start_time',
        'default_sunday_end_time',
        'timezone',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function rules()
    {
        return $this->hasMany(WorkplanRule::class);
    }

    public function events()
    {
        return $this->hasMany(WorkplanEvent::class);
    }
}
