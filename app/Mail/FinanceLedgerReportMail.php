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

class FinanceLedgerReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Club $club,
        public readonly array $files,
        public readonly array $filters = [],
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reporte financiero - {$this->club->club_name}",
            tags: ['finance_ledger_report'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'finance_ledger_report',
                'club_id' => (string) $this->club->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance_ledger_report',
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
        return collect($this->files)
            ->map(fn (array $file) => Attachment::fromData(
                fn () => $file['contents'],
                $file['file_name']
            )->withMime($file['mime_type'] ?? 'application/pdf'))
            ->all();
    }
}
