<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationAccessLog extends Model
{
    protected $fillable = [
        'location_tracking_session_id',
        'member_id',
        'viewer_user_id',
        'viewer_role',
        'action',
        'metadata',
        'ip',
        'user_agent',
    ];

    protected $casts = [
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

    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
