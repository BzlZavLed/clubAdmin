<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'payment_concept_id',
        'event_fee_component_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function concept()
    {
        return $this->belongsTo(PaymentConcept::class, 'payment_concept_id');
    }

    public function eventFeeComponent()
    {
        return $this->belongsTo(EventFeeComponent::class);
    }
}
