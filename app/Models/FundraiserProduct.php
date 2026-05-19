<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundraiserProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'fundraiser_event_id',
        'name',
        'description',
        'sale_price',
        'unit_cost',
        'investment_amount',
        'investment_expense_id',
        'tracks_inventory',
        'quantity_available',
        'quantity_sold',
        'is_active',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'investment_amount' => 'decimal:2',
        'tracks_inventory' => 'boolean',
        'quantity_available' => 'integer',
        'quantity_sold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function fundraiserEvent()
    {
        return $this->belongsTo(FundraiserEvent::class);
    }

    public function saleItems()
    {
        return $this->hasMany(FundraiserSaleItem::class);
    }

    public function investmentExpense()
    {
        return $this->belongsTo(Expense::class, 'investment_expense_id');
    }
}
