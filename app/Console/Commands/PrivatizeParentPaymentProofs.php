<?php

namespace App\Console\Commands;

use App\Models\ParentPaymentSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizeParentPaymentProofs extends Command
{
    protected $signature = 'parent-payments:privatize-proofs
        {--keep-public : Keep the legacy public copy after a successful private copy.}';

    protected $description = 'Move legacy parent payment proof images from public storage into private storage.';

    public function handle(): int
    {
        $stats = ['moved' => 0, 'already_private' => 0, 'missing' => 0, 'failed' => 0];
        $keepPublic = (bool) $this->option('keep-public');
        $public = Storage::disk('public');
        $private = Storage::disk('local');

        ParentPaymentSubmission::query()
            ->whereNotNull('receipt_image_path')
            ->orderBy('id')
            ->chunkById(100, function ($submissions) use (&$stats, $keepPublic, $public, $private): void {
                foreach ($submissions as $submission) {
                    $path = $submission->receipt_image_path;

                    if ($private->exists($path)) {
                        $submission->forceFill(['receipt_image_disk' => 'local'])->saveQuietly();
                        if (! $keepPublic) {
                            $public->delete($path);
                        }
                        $stats['already_private']++;

                        continue;
                    }

                    if (! $public->exists($path)) {
                        $stats['missing']++;
                        $this->warn("Missing proof for submission {$submission->id}: {$path}");

                        continue;
                    }

                    $stream = $public->readStream($path);
                    if (! is_resource($stream)) {
                        $stats['failed']++;

                        continue;
                    }

                    try {
                        $written = $private->writeStream($path, $stream);
                    } finally {
                        fclose($stream);
                    }

                    if (! $written) {
                        $stats['failed']++;

                        continue;
                    }

                    $submission->forceFill(['receipt_image_disk' => 'local'])->saveQuietly();
                    if (! $keepPublic) {
                        $public->delete($path);
                    }
                    $stats['moved']++;
                }
            });

        $this->table(
            ['Moved', 'Already private', 'Missing', 'Failed'],
            [[$stats['moved'], $stats['already_private'], $stats['missing'], $stats['failed']]],
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
