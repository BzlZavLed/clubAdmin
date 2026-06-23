<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    protected $fillable = [
        'location_tracking_session_id',
        'location_tracking_participant_id',
        'member_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'altitude_meters',
        'speed_mps',
        'heading_degrees',
        'battery_percent',
        'is_background',
        'recorded_at',
        'received_at',
        'metadata',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'accuracy_meters' => 'decimal:2',
        'altitude_meters' => 'decimal:2',
        'speed_mps' => 'decimal:2',
        'heading_degrees' => 'decimal:2',
        'battery_percent' => 'integer',
        'is_background' => 'boolean',
        'recorded_at' => 'datetime',
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(LocationTrackingSession::class, 'location_tracking_session_id');
    }

    public function participant()
    {
        return $this->belongsTo(LocationTrackingParticipant::class, 'location_tracking_participant_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
