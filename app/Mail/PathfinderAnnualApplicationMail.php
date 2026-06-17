<?php

namespace App\Mail;

use App\Models\PathfinderAnnualApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PathfinderAnnualApplicationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly PathfinderAnnualApplication $application,
        private readonly string $pdfContents,
        private readonly string $pdfFilename,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pathfinder Club Yearly Application - {$this->application->club?->club_name} {$this->application->application_year}",
            tags: ['pathfinder_annual_application'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'pathfinder_annual_application',
                'club_id' => (string) $this->application->club_id,
                'application_year' => $this->application->application_year,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pathfinder_annual_application',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: array_filter([
                'X-Club-Portal-Mail-ID' => $this->emailUid,
            ])
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContents, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}
