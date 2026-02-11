<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Member;
use App\Models\Staff;
use App\Models\EventParticipant;
use App\Models\EventVehicle;

class EventDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'type',
        'doc_type',
        'title',
        'path',
        'uploaded_by_user_id',
        'member_id',
        'staff_id',
        'parent_id',
        'driver_participant_id',
        'vehicle_id',
        'status',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function driverParticipant()
    {
        return $this->belongsTo(EventParticipant::class, 'driver_participant_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(EventVehicle::class);
    }
}
