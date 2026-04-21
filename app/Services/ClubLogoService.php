<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Storage;

class ClubLogoService
{
    public function url(?Club $club): ?string
    {
        $logoPath = $this->resolveLogoPath($club);

        if (!$logoPath) {
            return null;
        }

        return url('/storage/' . ltrim($logoPath, '/'));
    }

    public function dataUri(?Club $club): ?string
    {
        $logoPath = $this->resolveLogoPath($club);

        if (!$logoPath) {
            return null;
        }

        $path = Storage::disk('public')->path($logoPath);
        if (!is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function resolveLogoPath(?Club $club): ?string
    {
        if (!$club) {
            return null;
        }

        if ($club->logo_path) {
            return $club->logo_path;
        }

        if (!$club->church_id) {
            return null;
        }

        return Club::withoutGlobalScopes()
            ->where('church_id', $club->church_id)
            ->whereNotNull('logo_path')
            ->where('logo_path', '!=', '')
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$club->id])
            ->orderBy('club_name')
            ->value('logo_path');
    }
}
