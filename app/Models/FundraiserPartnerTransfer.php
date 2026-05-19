<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundraiserPartnerTransfer extends Model
{
    public const TYPE_INVESTMENT_CONTRIBUTION = 'investment_contribution';
    public const TYPE_EARNINGS_DISTRIBUTION = 'earnings_distribution';

    protected $fillable = [
        'fundraiser_event_partner_id',
        'transfer_type',
        'from_club_id',
        'to_club_id',
        'from_expense_id',
        'to_payment_id',
        'from_pay_to',
        'to_pay_to',
        'funds_location',
        'payment_type',
        'amount',
        'transfer_date',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
    ];

    public function partner()
    {
        return $this->belongsTo(FundraiserEventPartner::class, 'fundraiser_event_partner_id');
    }

    public function fromClub()
    {
        return $this->belongsTo(Club::class, 'from_club_id');
    }

    public function toClub()
    {
        return $this->belongsTo(Club::class, 'to_club_id');
    }

    public function fromExpense()
    {
        return $this->belongsTo(Expense::class, 'from_expense_id');
    }

    public function toPayment()
    {
        return $this->belongsTo(Payment::class, 'to_payment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
