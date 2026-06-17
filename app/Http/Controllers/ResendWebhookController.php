<?php

namespace App\Http\Controllers;

use App\Models\MailDeliveryLog;
use App\Models\ResendWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;

class ResendWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $this->verifySignature($request, $payload);

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $svixId = (string) $request->header('svix-id');
        if ($svixId !== '' && ResendWebhookEvent::query()->where('svix_id', $svixId)->exists()) {
            return response()->json(['message' => 'Duplicate ignored']);
        }

        $type = (string) ($event['type'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $providerMessageId = $this->providerMessageId($data);
        $mailLog = $this->findMailLog($data, $providerMessageId);

        $webhookEvent = ResendWebhookEvent::query()->create([
            'svix_id' => $svixId !== '' ? $svixId : 'local_' . strtolower((string) \Illuminate\Support\Str::ulid()),
            'event_type' => $type ?: null,
            'provider_message_id' => $providerMessageId,
            'mail_delivery_log_id' => $mailLog?->id,
            'payload' => $event,
            'processed_at' => now(),
        ]);

        if ($mailLog) {
            $this->applyEventToMailLog($mailLog, $type, $data, $providerMessageId, $event);
            $webhookEvent->forceFill(['mail_delivery_log_id' => $mailLog->id])->save();
        }

        return response()->json(['message' => 'Webhook processed']);
    }

    private function verifySignature(Request $request, string $payload): void
    {
        $secret = (string) config('services.resend.webhook_secret');

        if ($secret === '') {
            abort_unless(app()->environment(['local', 'testing']), 503, 'Resend webhook secret is not configured.');

            return;
        }

        try {
            WebhookSignature::verify($payload, [
                'svix-id' => (string) $request->header('svix-id'),
                'svix-timestamp' => (string) $request->header('svix-timestamp'),
                'svix-signature' => (string) $request->header('svix-signature'),
            ], $secret);
        } catch (WebhookSignatureVerificationException $exception) {
            abort(401, 'Invalid Resend webhook signature.');
        }
    }

    private function applyEventToMailLog(
        MailDeliveryLog $mailLog,
        string $type,
        array $data,
        ?string $providerMessageId,
        array $payload,
    ): void {
        $now = $this->eventTime($payload);
        $metadata = $mailLog->metadata ?: [];
        $metadata['provider'] = 'resend';
        $metadata['last_provider_event'] = $type;
        $metadata['last_provider_payload'] = [
            'type' => $type,
            'created_at' => $payload['created_at'] ?? null,
            'email_id' => $providerMessageId,
            'to' => $data['to'] ?? null,
            'subject' => $data['subject'] ?? null,
        ];

        $updates = [
            'provider' => 'resend',
            'provider_message_id' => $providerMessageId ?: $mailLog->provider_message_id,
            'last_provider_event_at' => $now,
            'metadata' => $metadata,
        ];

        if (in_array($type, ['email.sent', 'email.delivered'], true)) {
            $updates['status'] = 'sent';
            $updates['sent_at'] = $mailLog->sent_at ?: $now;
            $updates['failed_at'] = null;
            $updates['error_message'] = null;
        }

        if ($type === 'email.opened') {
            $metadata['open_source'] = 'resend_webhook';
            $metadata['last_open_event_at'] = $now->toDateTimeString();
            $updates['metadata'] = $metadata;
            $updates['status'] = 'sent';
            $updates['sent_at'] = $mailLog->sent_at ?: $now;
            $updates['failed_at'] = null;
            $updates['error_message'] = null;
            $updates['opened_at'] = $mailLog->opened_at ?: $now;
            $updates['last_opened_at'] = $now;
            $updates['open_count'] = ((int) $mailLog->open_count) + 1;
        }

        if (in_array($type, ['email.bounced', 'email.complained', 'email.failed'], true)) {
            $updates['status'] = 'failed';
            $updates['failed_at'] = $now;
            $updates['error_message'] = mb_substr($this->failureMessage($type, $data), 0, 2000);
        }

        $mailLog->forceFill($updates)->save();
    }

    private function findMailLog(array $data, ?string $providerMessageId): ?MailDeliveryLog
    {
        if ($providerMessageId) {
            $log = MailDeliveryLog::query()
                ->where('provider', 'resend')
                ->where('provider_message_id', $providerMessageId)
                ->latest()
                ->first();

            if ($log) {
                return $log;
            }
        }

        $emailUid = $this->emailUidFromPayload($data);
        if ($emailUid) {
            $log = MailDeliveryLog::query()
                ->where('email_uid', $emailUid)
                ->latest()
                ->first();

            if ($log) {
                return $log;
            }
        }

        $recipient = $this->firstRecipient($data['to'] ?? null);
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($recipient === '' || $subject === '') {
            return null;
        }

        return MailDeliveryLog::query()
            ->where('recipient_email', $recipient)
            ->where('subject', $subject)
            ->where('created_at', '>=', now()->subDays(7))
            ->latest()
            ->first();
    }

    private function emailUidFromPayload(array $data): ?string
    {
        $tags = $data['tags'] ?? [];
        if (is_array($tags)) {
            $tagValue = $tags['email_uid'] ?? $tags['club_portal_email_uid'] ?? null;
            if (is_string($tagValue) && $tagValue !== '') {
                return $tagValue;
            }
        }

        $headers = $data['headers'] ?? [];
        if (is_array($headers)) {
            $headerValue = $headers['X-Club-Portal-Mail-ID'] ?? $headers['x-club-portal-mail-id'] ?? null;
            if (is_string($headerValue) && $headerValue !== '') {
                return $headerValue;
            }
        }

        return null;
    }

    private function providerMessageId(array $data): ?string
    {
        $emailId = $data['email_id'] ?? $data['id'] ?? null;

        return is_string($emailId) && $emailId !== '' ? $emailId : null;
    }

    private function firstRecipient(mixed $to): string
    {
        if (is_string($to)) {
            return $to;
        }

        if (is_array($to)) {
            $first = reset($to);

            return is_string($first) ? $first : '';
        }

        return '';
    }

    private function eventTime(array $payload): Carbon
    {
        $createdAt = $payload['created_at'] ?? null;

        return is_string($createdAt) && $createdAt !== ''
            ? Carbon::parse($createdAt)
            : now();
    }

    private function failureMessage(string $type, array $data): string
    {
        $message = $data['message'] ?? $data['reason'] ?? null;

        return trim($type . ($message ? ': ' . $message : ''));
    }
}
