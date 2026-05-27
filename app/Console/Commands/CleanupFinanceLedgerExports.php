<?php

namespace App\Console\Commands;

use App\Models\FinanceLedgerExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupFinanceLedgerExports extends Command
{
    protected $signature = 'finance:cleanup-ledger-exports {--days=14 : Delete exports older than this many days}';

    protected $description = 'Delete old generated finance ledger PDFs and export job records.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deletedFiles = 0;
        $deletedRows = 0;

        FinanceLedgerExportJob::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($exports) use (&$deletedFiles, &$deletedRows): void {
                foreach ($exports as $export) {
                    foreach ($this->fileUrls($export->files ?? []) as $url) {
                        $path = $this->publicPathFromUrl($url);
                        if ($path && File::isFile($path)) {
                            File::delete($path);
                            $deletedFiles++;
                        }
                    }

                    $export->delete();
                    $deletedRows++;
                }
            });

        $this->info("Deleted {$deletedRows} finance ledger export records and {$deletedFiles} generated PDF files.");

        return self::SUCCESS;
    }

    private function fileUrls(array $payload): array
    {
        $urls = [];
        if (!empty($payload['url'])) {
            $urls[] = $payload['url'];
        }

        foreach (($payload['files'] ?? []) as $file) {
            if (!empty($file['url'])) {
                $urls[] = $file['url'];
            }
        }

        if (!empty($payload['appendix']['url'])) {
            $urls[] = $payload['appendix']['url'];
        }

        return array_values(array_unique($urls));
    }

    private function publicPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || !str_starts_with($path, '/generated/finance-ledgers/')) {
            return null;
        }

        return public_path(ltrim($path, '/'));
    }
}
