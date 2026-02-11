<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'driver_id',
        'vin',
        'plate',
        'make',
        'model',
        'year',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function driver()
    {
        return $this->belongsTo(EventDriver::class, 'driver_id');
    }
}
