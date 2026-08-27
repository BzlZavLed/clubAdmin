<?php

namespace Tests\Unit;

use App\Jobs\SendParentPaymentSubmissionEmail;
use App\Jobs\SendPaymentReceiptEmail;
use Tests\TestCase;

class PaymentEmailQueueConfigurationTest extends TestCase
{
    public function test_async_queue_retry_windows_are_standardized_to_180_seconds(): void
    {
        $this->assertSame(180, config('queue.connections.database.retry_after'));
        $this->assertSame(180, config('queue.connections.beanstalkd.retry_after'));
        $this->assertSame(180, config('queue.connections.redis.retry_after'));
    }

    public function test_payment_email_jobs_timeout_before_the_queue_can_retry_them(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');
        $jobs = [
            new SendParentPaymentSubmissionEmail(1),
            new SendPaymentReceiptEmail(1),
        ];

        foreach ($jobs as $job) {
            $this->assertLessThan(
                $retryAfter,
                $job->timeout,
                sprintf('%s timeout must remain below the database queue retry window.', $job::class)
            );
        }
    }
}
