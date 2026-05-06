<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventFeeComponent extends Model
{
    protected $fillable = [
        'event_id',
        'label',
        'amount',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function paymentConcepts()
    {
        return $this->hasMany(PaymentConcept::class, 'event_fee_component_id');
    }
}
