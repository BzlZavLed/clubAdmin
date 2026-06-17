<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Mail\MailerService;
use App\Services\MemberExportArchiveService;

class ExportController extends Controller
{
    public function exportZip(Request $request, MemberExportArchiveService $archiveService)
    {
        $payload = $this->validatedExportPayload($request);

        try {
            $archive = $archiveService->build($payload['type'], $payload['ids'], $payload['club_type']);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        return response()->download($archive['path'], $archive['filename'])->deleteFileAfterSend(true);
    }

    public function sendMemberZipToConference(
        Request $request,
        MemberExportArchiveService $archiveService,
        MailerService $mailerService,
    ) {
        $payload = $this->validatedExportPayload($request);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
        ]);

        try {
            $archive = $archiveService->build($payload['type'], $payload['ids'], $payload['club_type']);
            $mailLog = $mailerService->sendConferenceMemberExport(
                clubId: (int) $validated['club_id'],
                recipientEmail: $validated['email'],
                zipPath: $archive['path'],
                zipFilename: $archive['filename'],
                memberCount: (int) $archive['count'],
                userId: $request->user()?->id,
                exportType: $payload['type'],
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } finally {
            if (!empty($archive['path']) && is_file($archive['path'])) {
                unlink($archive['path']);
            }
        }

        return response()->json([
            'message' => 'Member export sent to conference.',
            'mail_log_id' => $mailLog->id,
            'email_uid' => $mailLog->email_uid,
        ]);
    }

    private function validatedExportPayload(Request $request): array
    {
        $type = $request->route('type') ?? $request->input('type', 'member');
        $ids = match ($type) {
            'member' => $request->input('member_ids', []),
            'staff' => $request->input('staff_adventurer_ids', []),
            default => [],
        };

        if (!is_array($ids) || empty($ids)) {
            abort(response()->json(['error' => 'No IDs provided.'], 400));
        }

        if (!in_array($type, ['member', 'staff'], true)) {
            abort(response()->json(['error' => 'Invalid export type.'], 400));
        }

        return [
            'type' => $type,
            'ids' => array_values(array_filter($ids)),
            'club_type' => strtolower((string) $request->input('club_type', '')),
        ];
    }
}
