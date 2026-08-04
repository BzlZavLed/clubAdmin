<?php

namespace App\Mail;

use App\Models\AdventurerYearlyApplicationSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class AdventurerYearlyApplicationSignatureRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AdventurerYearlyApplicationSignature $signature,
        public readonly string $signatureUrl,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Signature requested: Adventurer Club Yearly Application',
            tags: ['adventurer_yearly_application_signature_request'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'adventurer_yearly_application_signature_request',
                'club_id' => (string) $this->signature->application?->club_id,
                'application_year' => $this->signature->application?->application_year,
                'signature_role' => $this->signature->role,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.adventurer_yearly_application_signature_request');
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            'X-Club-Portal-Mail-ID' => $this->emailUid,
        ]));
    }
}
