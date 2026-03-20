<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_id',
        'club_id',
        'member_id',
        'staff_id',
        'parent_user_id',
        'staff_user_id',
        'receipt_number',
        'issued_to_type',
        'issued_to_email',
        'issued_at',
        'delivered_at',
        'delivery_status',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function staffUser()
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
