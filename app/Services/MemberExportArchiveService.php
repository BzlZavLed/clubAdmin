<?php

namespace App\Services;

use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\StaffAdventurer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class MemberExportArchiveService
{
    public function __construct(private readonly DocumentExportService $documentExportService)
    {
    }

    public function build(string $type, array $ids, string $clubType = ''): array
    {
        $clubType = strtolower($clubType);
        $model = match ($type) {
            'member' => $clubType === 'pathfinders' ? MemberPathfinder::class : MemberAdventurer::class,
            'staff' => StaffAdventurer::class,
            default => null,
        };

        if (!$model) {
            throw new RuntimeException('Invalid export type.');
        }

        $records = $type === 'member' && $clubType === 'pathfinders'
            ? $model::with('insuranceCard')->whereIn('id', $ids)->get()
            : $model::whereIn('id', $ids)->get();

        if ($records->isEmpty()) {
            throw new RuntimeException('No records found for provided IDs.');
        }

        $tempDir = storage_path('app/temp_export_' . Str::uuid());
        $zipDir = storage_path('app/temp');
        $zipFilename = "{$type}_export_" . now()->format('Ymd_His') . '.zip';
        $zipPath = "{$zipDir}/{$zipFilename}";

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        if (!is_dir($zipDir)) {
            mkdir($zipDir, 0775, true);
        }

        $extension = 'docx';
        foreach ($records as $record) {
            try {
                if ($type === 'member') {
                    if ($clubType === 'pathfinders') {
                        $this->documentExportService->generatePathfinderPdf($record, $tempDir);
                        $extension = 'pdf';
                    } else {
                        $this->documentExportService->generateMemberDoc($record, $tempDir);
                    }
                } else {
                    $this->documentExportService->generateStaffDoc($record, $tempDir);
                }
            } catch (\Throwable $e) {
                Log::error("Failed to generate document for ID {$record->id}: " . $e->getMessage());
            }
        }

        $files = glob("{$tempDir}/*.{$extension}") ?: [];
        if (empty($files)) {
            $this->removeDirectory($tempDir);
            throw new RuntimeException('No export files were generated.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->removeDirectory($tempDir);
            throw new RuntimeException('Could not create export ZIP.');
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        $this->removeDirectory($tempDir);

        return [
            'path' => $zipPath,
            'filename' => $zipFilename,
            'count' => count($files),
            'extension' => $extension,
        ];
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
