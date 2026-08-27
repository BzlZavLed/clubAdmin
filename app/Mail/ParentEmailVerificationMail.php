<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ParentEmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $parent,
        public readonly string $actionUrl,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirma tu correo | Confirm your email',
            tags: ['parent_email_verification'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'parent_email_verification',
                'user_id' => (string) $this->parent->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.parent_email_verification');
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            'X-Club-Portal-Mail-ID' => $this->emailUid,
        ]));
    }
}
