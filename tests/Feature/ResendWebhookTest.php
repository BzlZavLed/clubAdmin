<?php

namespace Tests\Feature;

use App\Models\MailDeliveryLog;
use App\Models\ResendWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_opened_webhook_marks_mail_log_as_opened(): void
    {
        $mailLog = MailDeliveryLog::query()->create([
            'email_uid' => 'mail_test_123',
            'provider' => 'resend',
            'provider_message_id' => 'resend_email_123',
            'mail_key' => 'payment_receipt',
            'mailable' => 'App\\Mail\\PaymentReceiptMail',
            'from_email' => 'receipts@example.com',
            'recipient_email' => 'payer@example.com',
            'subject' => 'Recibo de pago RCPT-1',
            'status' => 'queued',
            'queued_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson(route('webhooks.resend'), [
            'type' => 'email.opened',
            'created_at' => '2026-06-17T12:00:00.000Z',
            'data' => [
                'email_id' => 'resend_email_123',
                'from' => 'receipts@example.com',
                'to' => ['payer@example.com'],
                'subject' => 'Recibo de pago RCPT-1',
            ],
        ], [
            'svix-id' => 'msg_test_123',
        ])->assertOk();

        $mailLog->refresh();

        $this->assertSame('sent', $mailLog->status);
        $this->assertSame(1, $mailLog->open_count);
        $this->assertNotNull($mailLog->opened_at);
        $this->assertSame('resend_webhook', $mailLog->metadata['open_source']);
        $this->assertSame('email.opened', $mailLog->metadata['last_provider_event']);
        $this->assertDatabaseHas('resend_webhook_events', [
            'svix_id' => 'msg_test_123',
            'event_type' => 'email.opened',
            'mail_delivery_log_id' => $mailLog->id,
        ]);
    }

    public function test_resend_webhook_deduplicates_svix_delivery_id(): void
    {
        $mailLog = MailDeliveryLog::query()->create([
            'email_uid' => 'mail_test_456',
            'provider' => 'resend',
            'provider_message_id' => 'resend_email_456',
            'mail_key' => 'payment_receipt',
            'recipient_email' => 'payer@example.com',
            'subject' => 'Recibo de pago RCPT-2',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => [],
        ]);

        $payload = [
            'type' => 'email.opened',
            'created_at' => '2026-06-17T12:00:00.000Z',
            'data' => [
                'email_id' => 'resend_email_456',
                'to' => ['payer@example.com'],
                'subject' => 'Recibo de pago RCPT-2',
            ],
        ];

        $this->postJson(route('webhooks.resend'), $payload, ['svix-id' => 'msg_test_456'])->assertOk();
        $this->postJson(route('webhooks.resend'), $payload, ['svix-id' => 'msg_test_456'])->assertOk();

        $this->assertSame(1, $mailLog->refresh()->open_count);
        $this->assertSame(1, ResendWebhookEvent::query()->where('svix_id', 'msg_test_456')->count());
    }
}
