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
        'concept_text',
        'pay_to',
        'account_id',
        'member_id',
        'staff_id',
        'payer_name',
        'amount_paid',
        'expected_amount',
        'payment_date',
        'payment_type',
        'zelle_phone',
        'balance_due_after',
        'check_image_path',
        'received_by_user_id',
        'notes',
        'reversed_payment_id',
        'settles_expense_id',
        'is_cancelled',
        'related_canceled_movement_id',
        'canceling_id',
        'source_type',
        'source_id',
        'source_line_id',
        'custody_status',
        'held_by_user_id',
        'remittance_batch_id',
        'remittance_method',
        'remittance_reference',
        'remittance_notes',
        'remitted_at',
        'custody_validated_by_user_id',
        'custody_validated_at',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'is_cancelled' => 'boolean',
        'remitted_at' => 'datetime',
        'custody_validated_at' => 'datetime',
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
    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function heldBy()
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }

    public function custodyValidatedBy()
    {
        return $this->belongsTo(User::class, 'custody_validated_by_user_id');
    }

    public function receipt()
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function reversedPayment()
    {
        return $this->belongsTo(self::class, 'reversed_payment_id');
    }

    public function reversalPayment()
    {
        return $this->hasOne(self::class, 'reversed_payment_id');
    }

    public function relatedCanceledMovement()
    {
        return $this->belongsTo(self::class, 'related_canceled_movement_id');
    }

    public function cancelingMovement()
    {
        return $this->belongsTo(self::class, 'canceling_id');
    }

    public function settledExpense()
    {
        return $this->belongsTo(Expense::class, 'settles_expense_id');
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return (float) $this->balance_due_after <= 0.0;
    }
}
