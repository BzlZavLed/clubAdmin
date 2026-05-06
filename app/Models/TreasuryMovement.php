<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TreasuryMovement extends Model
{
    use SoftDeletes;

    public const TYPE_CASH_DEPOSIT = 'cash_deposit';
    public const TYPE_CASH_WITHDRAWAL = 'cash_withdrawal';
    public const TYPE_EVENT_SETTLEMENT = 'event_settlement';

    public const LOCATION_CASH = 'cash';
    public const LOCATION_BANK = 'bank';
    public const LOCATION_EXTERNAL = 'external';

    protected $fillable = [
        'club_id',
        'pay_to',
        'created_by_user_id',
        'movement_type',
        'from_location',
        'to_location',
        'amount',
        'movement_date',
        'reference',
        'notes',
        'proof_path',
        'proof_original_name',
        'event_id',
        'event_club_settlement_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'movement_date' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function eventClubSettlement()
    {
        return $this->belongsTo(EventClubSettlement::class);
    }
}
