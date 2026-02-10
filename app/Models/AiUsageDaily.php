<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiUsageDaily extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_daily';

    protected $fillable = [
        'club_id',
        'usage_date',
        'tokens_used',
        'requests_count',
    ];

    protected $casts = [
        'usage_date' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
