<?php

namespace App\Mail;

use App\Models\AdventurerInductionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class AdventurerInductionRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AdventurerInductionRequest $inductionRequest,
        private readonly string $docxPath,
        private readonly string $docxFilename,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Adventurer Induction Attendance Request - {$this->inductionRequest->club_name}",
            tags: ['adventurer_induction_request'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'adventurer_induction_request',
                'club_id' => (string) $this->inductionRequest->club_id,
                'induction_request_id' => (string) $this->inductionRequest->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.adventurer_induction_request');
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            'X-Club-Portal-Mail-ID' => $this->emailUid,
        ]));
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('public', $this->docxPath)
                ->as($this->docxFilename)
                ->withMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ];
    }
}
