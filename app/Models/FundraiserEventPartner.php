<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundraiserEventPartner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'fundraiser_event_id',
        'partner_club_id',
        'investment_share_percent',
        'earnings_share_percent',
        'status',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'investment_share_percent' => 'decimal:2',
        'earnings_share_percent' => 'decimal:2',
    ];

    public function fundraiserEvent()
    {
        return $this->belongsTo(FundraiserEvent::class);
    }

    public function partnerClub()
    {
        return $this->belongsTo(Club::class, 'partner_club_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transfers()
    {
        return $this->hasMany(FundraiserPartnerTransfer::class);
    }
}
