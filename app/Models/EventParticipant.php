<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventParticipant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'member_id',
        'participant_name',
        'role',
        'status',
        'permission_received',
        'medical_form_received',
        'emergency_contact_json',
    ];

    protected $casts = [
        'permission_received' => 'boolean',
        'medical_form_received' => 'boolean',
        'emergency_contact_json' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
