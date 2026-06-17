<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResendWebhookEvent extends Model
{
    protected $fillable = [
        'svix_id',
        'event_type',
        'provider_message_id',
        'mail_delivery_log_id',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function mailDeliveryLog()
    {
        return $this->belongsTo(MailDeliveryLog::class);
    }
}
