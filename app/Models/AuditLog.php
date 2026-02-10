<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'entity_label',
        'changes',
        'metadata',
        'error_message',
        'error_class',
        'route',
        'method',
        'url',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'metadata' => 'array',
    ];
}
