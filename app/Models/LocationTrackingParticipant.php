<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationTrackingParticipant extends Model
{
    protected $fillable = [
        'location_tracking_session_id',
        'member_id',
        'location_sharing_consent_id',
        'tracking_status',
        'device_platform',
        'device_label',
        'last_ping_at',
        'last_latitude',
        'last_longitude',
        'last_accuracy_meters',
        'last_battery_percent',
        'metadata',
    ];

    protected $casts = [
        'last_ping_at' => 'datetime',
        'last_latitude' => 'decimal:8',
        'last_longitude' => 'decimal:8',
        'last_accuracy_meters' => 'decimal:2',
        'last_battery_percent' => 'integer',
        'metadata' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(LocationTrackingSession::class, 'location_tracking_session_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function consent()
    {
        return $this->belongsTo(LocationSharingConsent::class, 'location_sharing_consent_id');
    }

    public function pings()
    {
        return $this->hasMany(LocationPing::class);
    }
}
