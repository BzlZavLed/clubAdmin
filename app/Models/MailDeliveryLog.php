<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDeliveryLog extends Model
{
    protected $fillable = [
        'email_uid',
        'provider',
        'provider_message_id',
        'mail_key',
        'mailable',
        'from_email',
        'from_name',
        'recipient_email',
        'recipient_name',
        'subject',
        'source_label',
        'destination_label',
        'status',
        'loggable_type',
        'loggable_id',
        'club_id',
        'user_id',
        'queued_at',
        'sent_at',
        'last_provider_event_at',
        'opened_at',
        'open_count',
        'last_opened_at',
        'last_open_ip',
        'last_open_user_agent',
        'failed_at',
        'error_message',
        'body_html',
        'body_text',
        'metadata',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_provider_event_at' => 'datetime',
        'opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function loggable()
    {
        return $this->morphTo();
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
