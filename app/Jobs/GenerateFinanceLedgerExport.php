<?php

namespace App\Jobs;

use App\Models\FinanceLedgerExportJob;
use App\Services\Finance\FinanceLedgerPdfGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateFinanceLedgerExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $exportJobId)
    {
    }

    public function handle(FinanceLedgerPdfGenerator $generator): void
    {
        $export = FinanceLedgerExportJob::query()
            ->with([
                'club' => fn ($query) => $query->withoutGlobalScopes(),
                'user',
            ])
            ->findOrFail($this->exportJobId);

        $export->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            if (!$export->club) {
                throw (new ModelNotFoundException)->setModel(\App\Models\Club::class, [$export->club_id]);
            }

            if (!$export->user) {
                throw (new ModelNotFoundException)->setModel(\App\Models\User::class, [$export->user_id]);
            }

            $payload = $generator->generate($export->club, $export->user, $export->filters ?? []);

            $export->update([
                'status' => 'completed',
                'files' => $payload,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $export->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }
}
