<?php

namespace App\Http\Controllers;

use App\Models\MailDeliveryLog;
use App\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SuperAdminMailLogController extends Controller
{
    public function index(Request $request)
    {
        $this->reconcileQueuedPaymentReceiptLogs();

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month'))
            ? (string) $request->input('month')
            : now()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $monthlyLimit = (int) config('mail.monthly_limit', 3000);

        $query = MailDeliveryLog::query()
            ->with(['club:id,club_name', 'user:id,name,email'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('mail_key')) {
            $query->where('mail_key', $request->string('mail_key'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($builder) use ($search) {
                $builder->where('email_uid', 'like', $search)
                    ->orWhere('provider_message_id', 'like', $search)
                    ->orWhere('recipient_email', 'like', $search)
                    ->orWhere('from_email', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('source_label', 'like', $search)
                    ->orWhere('destination_label', 'like', $search)
                    ->orWhere('body_text', 'like', $search)
                    ->orWhere('error_message', 'like', $search);
            });
        }

        $logs = $query->paginate(30)->withQueryString()
            ->through(fn (MailDeliveryLog $log) => [
                'id' => $log->id,
                'email_uid' => $log->email_uid,
                'provider' => $log->provider,
                'provider_message_id' => $log->provider_message_id,
                'last_provider_event_at' => optional($log->last_provider_event_at)->toDateTimeString(),
                'mail_key' => $log->mail_key,
                'label' => $this->mailLabel($log->mail_key),
                'mailable' => class_basename($log->mailable ?: ''),
                'from_email' => $log->from_email,
                'from_name' => $log->from_name,
                'recipient_email' => $log->recipient_email === 'missing-recipient@internal.local' ? null : $log->recipient_email,
                'subject' => $log->subject,
                'source_label' => $log->source_label,
                'destination_label' => $log->destination_label,
                'status' => $log->status,
                'queued_at' => optional($log->queued_at)->toDateTimeString(),
                'sent_at' => optional($log->sent_at)->toDateTimeString(),
                'opened_at' => optional($log->opened_at)->toDateTimeString(),
                'open_count' => (int) $log->open_count,
                'last_opened_at' => optional($log->last_opened_at)->toDateTimeString(),
                'open_source' => $log->metadata['open_source'] ?? null,
                'last_open_ip' => $log->last_open_ip,
                'last_open_user_agent' => $log->last_open_user_agent,
                'failed_at' => optional($log->failed_at)->toDateTimeString(),
                'created_at' => optional($log->created_at)->toDateTimeString(),
                'error_message' => $log->error_message,
                'body_html' => $log->body_html,
                'body_text' => $log->body_text,
                'metadata' => $log->metadata ?: [],
                'club' => $log->club ? [
                    'id' => $log->club->id,
                    'club_name' => $log->club->club_name,
                ] : null,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'loggable_type' => class_basename($log->loggable_type ?: ''),
                'loggable_id' => $log->loggable_id,
            ]);

        $monthBase = MailDeliveryLog::query()
            ->whereBetween('created_at', [$monthStart, $monthEnd]);

        $sentThisMonth = (clone $monthBase)->where('status', 'sent')->count();
        $failedThisMonth = (clone $monthBase)->where('status', 'failed')->count();
        $manualThisMonth = (clone $monthBase)->where('status', 'manual_required')->count();
        $queuedThisMonth = (clone $monthBase)->where('status', 'queued')->count();
        $billableThisMonth = $sentThisMonth + $queuedThisMonth;

        $byMailType = (clone $monthBase)
            ->select(
                'mail_key',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent"),
                DB::raw("SUM(CASE WHEN status IN ('sent', 'queued') THEN 1 ELSE 0 END) as billable")
            )
            ->groupBy('mail_key')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'mail_key' => $row->mail_key,
                'label' => $this->mailLabel($row->mail_key),
                'total' => (int) $row->total,
                'sent' => (int) $row->sent,
                'billable' => (int) $row->billable,
            ]);

        $mailKeys = MailDeliveryLog::query()
            ->select('mail_key')
            ->distinct()
            ->orderBy('mail_key')
            ->pluck('mail_key')
            ->map(fn ($mailKey) => [
                'value' => $mailKey,
                'label' => $this->mailLabel($mailKey),
            ]);

        return Inertia::render('SuperAdmin/MailLogs', [
            'logs' => $logs,
            'filters' => [
                'status' => $request->input('status', ''),
                'mail_key' => $request->input('mail_key', ''),
                'search' => $request->input('search', ''),
                'month' => $month,
            ],
            'mail_keys' => $mailKeys,
            'summary' => [
                'month' => $month,
                'monthly_limit' => $monthlyLimit,
                'billable_this_month' => $billableThisMonth,
                'sent_this_month' => $sentThisMonth,
                'failed_this_month' => $failedThisMonth,
                'manual_required_this_month' => $manualThisMonth,
                'queued_this_month' => $queuedThisMonth,
                'remaining_this_month' => max($monthlyLimit - $billableThisMonth, 0),
                'usage_percent' => $monthlyLimit > 0 ? min(round(($billableThisMonth / $monthlyLimit) * 100, 1), 100) : 0,
                'by_mail_type' => $byMailType,
            ],
        ]);
    }

    private function mailLabel(?string $mailKey): string
    {
        return match ($mailKey) {
            'payment_receipt' => 'Recibo de pago',
            'parent_payment_submission' => 'Comprobante de padre a club',
            'conference_member_export' => 'Exportacion de miembros a conferencia',
            'conference_staff_export' => 'Exportacion de personal a conferencia',
            default => $mailKey ?: 'Correo',
        };
    }

    private function reconcileQueuedPaymentReceiptLogs(): void
    {
        MailDeliveryLog::query()
            ->where('mail_key', 'payment_receipt')
            ->where('status', 'queued')
            ->where('loggable_type', (new PaymentReceipt())->getMorphClass())
            ->whereNotNull('loggable_id')
            ->limit(100)
            ->get()
            ->each(function (MailDeliveryLog $log): void {
                $receipt = PaymentReceipt::query()->find($log->loggable_id);
                if (!$receipt) {
                    return;
                }

                if ($receipt->delivery_status === 'sent') {
                    $log->forceFill([
                        'status' => 'sent',
                        'sent_at' => $receipt->delivered_at ?: $log->sent_at ?: now(),
                        'failed_at' => null,
                        'error_message' => null,
                    ])->save();
                }

                if ($receipt->delivery_status === 'failed') {
                    $log->forceFill([
                        'status' => 'failed',
                        'failed_at' => $log->failed_at ?: now(),
                    ])->save();
                }

                if ($receipt->delivery_status === 'manual_required') {
                    $log->forceFill([
                        'status' => 'manual_required',
                        'failed_at' => null,
                        'sent_at' => null,
                    ])->save();
                }
            });
    }
}
