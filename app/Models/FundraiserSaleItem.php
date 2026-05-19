<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundraiserSaleItem extends Model
{
    protected $fillable = [
        'fundraiser_sale_id',
        'fundraiser_product_id',
        'item_name',
        'quantity',
        'unit_price',
        'unit_cost',
        'line_total',
        'line_cost',
        'line_gain',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_cost' => 'decimal:2',
        'line_gain' => 'decimal:2',
    ];

    public function fundraiserSale()
    {
        return $this->belongsTo(FundraiserSale::class);
    }

    public function fundraiserProduct()
    {
        return $this->belongsTo(FundraiserProduct::class);
    }
}
