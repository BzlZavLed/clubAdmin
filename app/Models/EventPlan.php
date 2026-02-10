<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'schema_version',
        'plan_json',
        'ai_summary',
        'missing_items_json',
        'conversation_json',
        'last_generated_at',
    ];

    protected $casts = [
        'plan_json' => 'array',
        'missing_items_json' => 'array',
        'conversation_json' => 'array',
        'last_generated_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
