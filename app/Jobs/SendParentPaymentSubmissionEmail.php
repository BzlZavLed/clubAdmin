<?php

namespace App\Jobs;

use App\Models\ParentPaymentSubmission;
use App\Services\Mail\MailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendParentPaymentSubmissionEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $submissionId)
    {
    }

    public function handle(MailerService $mailer): void
    {
        $submission = ParentPaymentSubmission::query()->findOrFail($this->submissionId);

        $mailer->sendParentPaymentSubmission($submission);
    }
}
