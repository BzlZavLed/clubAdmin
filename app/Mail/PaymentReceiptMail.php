<?php

namespace App\Mail;

use App\Models\PaymentReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentReceipt $receipt,
        private readonly string $pdfContents,
        public readonly ?string $trackingPixelUrl = null,
        public readonly ?string $emailUid = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Recibo de pago {$this->receipt->receipt_number}",
            tags: ['payment_receipt'],
            metadata: array_filter([
                'email_uid' => $this->emailUid,
                'mail_key' => 'payment_receipt',
                'receipt_id' => (string) $this->receipt->id,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_receipt',
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
                fn () => $this->pdfContents,
                "{$this->receipt->receipt_number}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}
