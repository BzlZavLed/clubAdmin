<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Storage;

class ClubLogoService
{
    public function url(?Club $club): ?string
    {
        if (!$club?->logo_path) {
            return null;
        }

        return url('/storage/' . ltrim($club->logo_path, '/'));
    }

    public function dataUri(?Club $club): ?string
    {
        if (!$club?->logo_path) {
            return null;
        }

        $path = Storage::disk('public')->path($club->logo_path);
        if (!is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
}
