<?php

namespace App\Mail;

use App\Models\Club;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConferenceMemberExportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Club $club,
        public readonly int $memberCount,
        private readonly string $zipContents,
        private readonly string $zipFilename,
        public readonly string $exportType = 'member',
        public readonly string $exportLabel = 'miembros',
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Registro de {$this->exportLabel} - {$this->club->club_name}",
            tags: [$this->exportType === 'staff' ? 'conference_staff_export' : 'conference_member_export'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => $this->exportType === 'staff' ? 'conference_staff_export' : 'conference_member_export',
                'club_id' => (string) $this->club->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conference_member_export',
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
            Attachment::fromData(
                fn () => $this->zipContents,
                $this->zipFilename
            )->withMime('application/zip'),
        ];
    }
}
