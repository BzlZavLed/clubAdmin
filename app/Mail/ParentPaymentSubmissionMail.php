<?php

namespace App\Mail;

use App\Models\ParentPaymentSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ParentPaymentSubmissionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ParentPaymentSubmission $submission,
        private readonly string $receiptImageContents,
        private readonly string $receiptImageName,
        private readonly string $receiptImageMime,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Comprobante de pago enviado por padre',
            tags: ['parent_payment_submission'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'parent_payment_submission',
                'submission_id' => (string) $this->submission->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.parent_payment_submission',
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
                fn () => $this->receiptImageContents,
                $this->receiptImageName
            )->withMime($this->receiptImageMime),
        ];
    }
}
