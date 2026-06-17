<?php

namespace App\Mail;

use App\Models\PathfinderMonthlyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PathfinderMonthlyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly PathfinderMonthlyReport $report,
        public readonly array $files,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pathfinder Monthly Report - {$this->report->club?->club_name} {$this->report->report_month} {$this->report->report_year}",
            tags: ['pathfinder_monthly_report'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'pathfinder_monthly_report',
                'club_id' => (string) $this->report->club_id,
                'report_year' => $this->report->report_year,
                'report_month' => $this->report->report_month,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pathfinder_monthly_report',
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
            )->withMime($file['mime_type'] ?? 'application/octet-stream'))
            ->all();
    }
}
