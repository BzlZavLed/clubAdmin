<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistrictWorkplanPublication extends Model
{
    protected $fillable = [
        'district_id',
        'year',
        'status',
        'published_at',
        'unpublished_at',
        'published_by',
    ];

    public function district()
    {
        return $this->belongsTo(District::class);
    }
}
