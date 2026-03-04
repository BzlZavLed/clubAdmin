<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SerpApiUsageMonthly extends Model
{
    use HasFactory;

    protected $table = 'serpapi_usage_monthly';

    protected $fillable = [
        'usage_month',
        'calls_count',
        'success_count',
        'failed_count',
        'last_called_at',
    ];

    protected $casts = [
        'usage_month' => 'date',
        'last_called_at' => 'datetime',
    ];
}
