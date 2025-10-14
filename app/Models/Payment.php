<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'payment_concept_id',
        'member_adventurer_id',
        'staff_adventurer_id',
        'amount_paid',
        'expected_amount',
        'payment_date',
        'payment_type',
        'zelle_phone',
        'balance_due_after',
        'check_image_path',
        'received_by_user_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
        'expected_amount' => 'decimal:2',
    ];

    // Relations
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
        return $this->belongsTo(MemberAdventurer::class, 'member_adventurer_id');
    }
    public function staff()
    {
        return $this->belongsTo(StaffAdventurer::class, 'staff_adventurer_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
    public function getIsFullyPaidAttribute(): bool
    {
        return (float) $this->balance_due_after <= 0.0;
    }
}
