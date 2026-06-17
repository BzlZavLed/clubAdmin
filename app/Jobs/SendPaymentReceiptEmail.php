<?php

namespace App\Jobs;

use App\Models\MailDeliveryLog;
use App\Models\PaymentReceipt;
use App\Services\Mail\MailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPaymentReceiptEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $receiptId, public ?int $mailLogId = null)
    {
    }

    public function handle(MailerService $mailer): void
    {
        $receipt = PaymentReceipt::query()->findOrFail($this->receiptId);
        $mailLog = $this->mailLogId
            ? MailDeliveryLog::query()->find($this->mailLogId)
            : MailDeliveryLog::query()
                ->where('mail_key', 'payment_receipt')
                ->where('loggable_type', $receipt->getMorphClass())
                ->where('loggable_id', $receipt->id)
                ->where('status', 'queued')
                ->latest()
                ->first();

        $mailer->sendPaymentReceipt($receipt, $mailLog);
    }
}
