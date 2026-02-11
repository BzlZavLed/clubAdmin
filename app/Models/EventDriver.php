<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventDriver extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'participant_id',
        'license_number',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function participant()
    {
        return $this->belongsTo(EventParticipant::class, 'participant_id');
    }

    public function vehicles()
    {
        return $this->hasMany(EventVehicle::class, 'driver_id');
    }
}
