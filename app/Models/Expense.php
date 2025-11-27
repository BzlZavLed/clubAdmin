<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'club_id',
        'pay_to',
        'amount',
        'expense_date',
        'description',
        'reimbursed_to',
        'created_by_user_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
