<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankInfo extends Model
{
    protected $fillable = [
        'bankable_type',
        'bankable_id',
        'pay_to',
        'label',
        'bank_name',
        'account_holder',
        'account_type',
        'account_number',
        'routing_number',
        'zelle_email',
        'zelle_phone',
        'deposit_instructions',
        'is_active',
        'accepts_parent_deposits',
        'accepts_event_deposits',
        'requires_receipt_upload',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'routing_number' => 'encrypted',
        'is_active' => 'boolean',
        'accepts_parent_deposits' => 'boolean',
        'accepts_event_deposits' => 'boolean',
        'requires_receipt_upload' => 'boolean',
    ];

    public function bankable(): MorphTo
    {
        return $this->morphTo();
    }
}
