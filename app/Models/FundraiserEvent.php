<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundraiserEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'name',
        'fundraiser_type',
        'event_date',
        'pay_to',
        'accounting_mode',
        'investment_total',
        'investment_expense_id',
        'investment_pay_to',
        'investment_funds_location',
        'planned_units',
        'description',
        'status',
        'accounting_batch_uuid',
        'accounting_posted_at',
        'accounting_posted_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'investment_total' => 'decimal:2',
        'planned_units' => 'integer',
        'accounting_posted_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function products()
    {
        return $this->hasMany(FundraiserProduct::class);
    }

    public function sales()
    {
        return $this->hasMany(FundraiserSale::class);
    }

    public function partners()
    {
        return $this->hasMany(FundraiserEventPartner::class);
    }

    public function investmentExpense()
    {
        return $this->belongsTo(Expense::class, 'investment_expense_id');
    }

    public function investmentReceipts()
    {
        return $this->hasMany(FundraiserInvestmentReceipt::class);
    }
}
