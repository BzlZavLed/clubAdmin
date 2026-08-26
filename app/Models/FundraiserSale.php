<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundraiserSale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'fundraiser_event_id',
        'club_id',
        'payment_id',
        'customer_name',
        'sale_date',
        'payment_type',
        'zelle_phone',
        'total_amount',
        'total_cost',
        'gain_amount',
        'notes',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'reversal_payment_id',
        'kitchen_status',
        'kitchen_completed_at',
        'kitchen_completed_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'total_amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'gain_amount' => 'decimal:2',
        'is_cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
        'kitchen_completed_at' => 'datetime',
    ];

    public function fundraiserEvent()
    {
        return $this->belongsTo(FundraiserEvent::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function receipt()
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function reversalPayment()
    {
        return $this->belongsTo(Payment::class, 'reversal_payment_id');
    }

    public function kitchenCompletedBy()
    {
        return $this->belongsTo(User::class, 'kitchen_completed_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(FundraiserSaleItem::class);
    }
}
