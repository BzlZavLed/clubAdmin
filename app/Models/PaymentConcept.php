<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentConcept extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'concept',
        'payment_expected_by',
        'type',
        'pay_to',
        'payee_type',
        'payee_id',
        'created_by',
        'status',
        'club_id',
        'amount'
    ];

    protected $casts = [
        'payment_expected_by' => 'date',
        'amount' => 'decimal:2', // returns string "123.45" in JSON; OK for currency

    ];

    // Relationships
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function scopes()
    {
        return $this->hasMany(PaymentConceptScope::class);
    }

    // Polymorphic payee when pay_to = reimbursement_to
    public function payee()
    {
        return $this->morphTo();
    }
}
