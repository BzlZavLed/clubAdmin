<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'club_id',
        'pay_to',
        'label',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
