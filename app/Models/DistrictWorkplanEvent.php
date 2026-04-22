<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistrictWorkplanEvent extends Model
{
    protected $fillable = [
        'district_id',
        'year',
        'date',
        'end_date',
        'start_time',
        'end_time',
        'event_type',
        'title',
        'description',
        'location',
        'target_club_types',
        'is_mandatory',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'target_club_types' => 'array',
        'is_mandatory' => 'boolean',
    ];

    public function district()
    {
        return $this->belongsTo(District::class);
    }
}
