<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTaskTemplate extends Model
{
    protected $fillable = [
        'club_id',
        'event_type',
        'title',
        'description',
        'task_key',
        'form_schema_json',
        'is_custom',
        'is_active',
    ];

    protected $casts = [
        'form_schema_json' => 'array',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class)->withoutGlobalScopes();
    }
}
