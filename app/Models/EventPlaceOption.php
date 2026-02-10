<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventPlaceOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'place_id',
        'name',
        'address',
        'phone',
        'rating',
        'user_ratings_total',
        'status',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'rating' => 'decimal:1',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
