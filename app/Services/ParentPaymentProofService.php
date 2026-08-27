<?php

namespace App\Services;

use App\Models\ParentPaymentSubmission;
use App\Models\User;
use App\Support\ClubHelper;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParentPaymentProofService
{
    public function authorize(User $user, ParentPaymentSubmission $submission): void
    {
        if ($user->status !== null && $user->status !== 'active') {
            abort(403, 'Not allowed to view this payment proof.');
        }

        if ($user->profile_type === 'superadmin') {
            return;
        }

        if (
            $user->profile_type === 'parent'
            && $user->canAccessParentPortal()
            && (int) $submission->parent_user_id === (int) $user->id
        ) {
            return;
        }

        if (
            in_array($user->profile_type, ['club_director', 'club_personal', 'treasurer'], true)
            && $user->hasVerifiedEmail()
            && ClubHelper::clubIdsForUser($user)->contains((int) $submission->club_id)
        ) {
            return;
        }

        abort(403, 'Not allowed to view this payment proof.');
    }

    public function response(ParentPaymentSubmission $submission): StreamedResponse
    {
        $disk = $this->diskFor($submission);
        $path = $submission->receipt_image_path;

        abort_unless($path && $disk->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = 'payment-proof-'.$submission->id.($extension ? '.'.$extension : '');

        return $disk->response($path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function contents(ParentPaymentSubmission $submission): string
    {
        $disk = $this->diskFor($submission);
        $path = $submission->receipt_image_path;

        if (! $path || ! $disk->exists($path)) {
            throw new \RuntimeException('Parent payment proof image not found.');
        }

        return $disk->get($path);
    }

    public function mimeType(ParentPaymentSubmission $submission): string
    {
        $disk = $this->diskFor($submission);

        return $disk->mimeType($submission->receipt_image_path) ?: 'application/octet-stream';
    }

    public function diskName(ParentPaymentSubmission $submission): string
    {
        if (in_array($submission->receipt_image_disk, ['local', 'public'], true)) {
            return $submission->receipt_image_disk;
        }

        if ($submission->receipt_image_path && Storage::disk('local')->exists($submission->receipt_image_path)) {
            return 'local';
        }

        return 'public';
    }

    private function diskFor(ParentPaymentSubmission $submission): FilesystemAdapter
    {
        return Storage::disk($this->diskName($submission));
    }
}
