<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiRequestLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'club_id',
        'user_id',
        'provider',
        'model',
        'request_json',
        'response_json',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'latency_ms',
        'status',
        'error_message',
    ];

    protected $casts = [
        'request_json' => 'array',
        'response_json' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
