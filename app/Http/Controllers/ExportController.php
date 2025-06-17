<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
use Str;
use Log;
use App\Services\DocumentExportService;
class ExportController extends Controller
{
    public function exportZip(Request $request, string $type, DocumentExportService $exportService)
    {
        $ids = match ($type) {
            'member' => $request->input('member_ids', []),
            'staff' => $request->input('staff_adventurer_ids', []),
            default => [],
        };

        if (!is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'No IDs provided.'], 400);
        }
        

        $model = match ($type) {
            'member' => MemberAdventurer::class,
            'staff' => StaffAdventurer::class,
            default => null,
        };
        
        if (!$model) {
            return response()->json(['error' => 'Invalid export type.'], 400);
        }

        $records = $model::whereIn('id', $ids)->get();
        if ($records->isEmpty()) {
            return response()->json(['error' => 'No records found for provided IDs.'], 404);
        }
        $tempDir = storage_path('app/temp_export_' . Str::uuid());
        $zipPath = storage_path("app/temp/{$type}_export_" . time() . ".zip");

        mkdir($tempDir, 0775, true);

        foreach ($records as $record) {
            try {
                if ($type === 'member') {
                    $exportService->generateMemberDoc($record, $tempDir);
                } else {
                    $exportService->generateStaffDoc($record, $tempDir);
                }
            } catch (\Throwable $e) {
                Log::error("Failed to generate document for ID {$record->id}: " . $e->getMessage());
            }
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            foreach (glob("$tempDir/*.docx") as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        }

        foreach (glob("$tempDir/*.docx") as $file) {
            unlink($file);
        }
        rmdir($tempDir);

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
