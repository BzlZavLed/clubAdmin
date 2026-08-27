<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\SuperAdminClubDataDeletionService;
use App\Services\SuperAdminClubFinancialArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SuperAdminClubDeletionController extends Controller
{
    public function archive(
        Request $request,
        int $club,
        SuperAdminClubFinancialArchiveService $archiveService,
    ): BinaryFileResponse {
        $club = Club::query()->withoutGlobalScopes()->findOrFail($club);
        $archive = $archiveService->build($club);

        $request->session()->put($this->sessionKey($club), [
            'financial_fingerprint' => $archiveService->fingerprint($club),
            'archived_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(30)->timestamp,
            'cleaned_at' => null,
        ]);

        return response()->download($archive['path'], $archive['filename'], [
            'X-Club-Financial-Archive' => 'generated',
        ])->deleteFileAfterSend(true);
    }

    public function clean(
        Request $request,
        int $club,
        SuperAdminClubDataDeletionService $deletionService,
        SuperAdminClubFinancialArchiveService $archiveService,
    ): JsonResponse {
        $club = Club::query()->withoutGlobalScopes()->findOrFail($club);
        $authorization = $this->validateArchiveAuthorization($request, $club);

        if (! hash_equals(
            (string) ($authorization['financial_fingerprint'] ?? ''),
            $archiveService->fingerprint($club)
        )) {
            throw ValidationException::withMessages([
                'archive' => 'Financial data changed after the archive was generated. Download a new archive before continuing.',
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:DELETE CLUB DATA '.$club->club_name],
        ]);

        $summary = $deletionService->clean($club, $request->user());
        $authorization['cleaned_at'] = now()->timestamp;
        $authorization['delete_expires_at'] = now()->addMinutes(15)->timestamp;
        $request->session()->put($this->sessionKey($club), $authorization);

        return response()->json([
            'message' => 'The club data was permanently erased.',
            'summary' => $summary,
        ]);
    }

    public function destroy(
        Request $request,
        int $club,
        SuperAdminClubDataDeletionService $deletionService,
    ): JsonResponse {
        $club = Club::query()->withoutGlobalScopes()->findOrFail($club);
        $authorization = $request->session()->get($this->sessionKey($club));

        if (! is_array($authorization)
            || empty($authorization['cleaned_at'])
            || ($authorization['delete_expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'deletion' => 'The cleanup confirmation expired. Generate a new archive and clean the club again.',
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:DELETE CLUB '.$club->club_name],
        ]);

        $deletionService->deleteClub($club, $request->user());
        $request->session()->forget($this->sessionKey($club));
        if ((int) $request->session()->get('superadmin_context.club_id') === (int) $club->id) {
            $request->session()->forget('superadmin_context');
        }

        return response()->json([
            'message' => 'The club was permanently deleted.',
            'redirect_url' => route('superadmin.clubs.manage'),
        ]);
    }

    private function validateArchiveAuthorization(Request $request, Club $club): array
    {
        $authorization = $request->session()->get($this->sessionKey($club));
        if (! is_array($authorization) || ($authorization['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'archive' => 'Download a new financial archive before cleaning this club.',
            ]);
        }

        return $authorization;
    }

    private function sessionKey(Club $club): string
    {
        return 'superadmin_club_deletion.'.$club->id;
    }
}
