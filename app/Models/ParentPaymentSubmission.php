<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentPaymentSubmission extends Model
{
    protected $fillable = [
        'club_id',
        'payment_concept_id',
        'member_id',
        'parent_user_id',
        'event_id',
        'concept_text',
        'pay_to',
        'expected_amount',
        'amount',
        'payment_date',
        'payment_type',
        'reference',
        'receipt_image_path',
        'notes',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'approved_payment_id',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function concept()
    {
        return $this->belongsTo(PaymentConcept::class, 'payment_concept_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function approvedPayment()
    {
        return $this->belongsTo(Payment::class, 'approved_payment_id');
    }
}
