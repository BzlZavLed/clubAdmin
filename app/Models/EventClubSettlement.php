<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventClubSettlement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'event_id',
        'club_id',
        'created_by_user_id',
        'organizer_scope_type',
        'organizer_scope_id',
        'amount',
        'breakdown_json',
        'deposited_at',
        'reference',
        'notes',
        'club_code',
        'receipt_year',
        'club_sequence',
        'receipt_number',
        'issued_at',
        'last_downloaded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'breakdown_json' => 'array',
        'deposited_at' => 'datetime',
        'issued_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
        'receipt_year' => 'integer',
        'club_sequence' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
