<?php

namespace App\Mail;

use App\Models\AdventurerYearlyApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class AdventurerYearlyApplicationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AdventurerYearlyApplication $application,
        private readonly string $docxPath,
        private readonly string $docxFilename,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Adventurer Club Yearly Application - {$this->application->club_name} {$this->application->application_year}",
            tags: ['adventurer_yearly_application'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'adventurer_yearly_application',
                'club_id' => (string) $this->application->club_id,
                'application_year' => $this->application->application_year,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.adventurer_yearly_application');
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
