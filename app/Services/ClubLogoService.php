<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Storage;

class ClubLogoService
{
    public function url(?Club $club): ?string
    {
        $logoPath = $this->resolveLogoPath($club);

        if ($logoPath) {
            return url('/storage/' . ltrim($logoPath, '/'));
        }

        return $club ? $this->avatarDataUri($club) : null;
    }

    public function dataUri(?Club $club): ?string
    {
        $logoPath = $this->resolveLogoPath($club);

        if ($logoPath) {
            $path = Storage::disk('public')->path($logoPath);
            $mime = mime_content_type($path) ?: 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
        }

        return $club ? $this->avatarDataUri($club) : null;
    }

    public function initials(?Club $club): string
    {
        $name = trim((string) $club?->club_name);
        $name = preg_replace('/\s+/u', ' ', $name) ?: '';
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return 'CL';
    }

    private function resolveLogoPath(?Club $club): ?string
    {
        if (!$club) {
            return null;
        }

        if ($club->logo_path && Storage::disk('public')->exists($club->logo_path)) {
            return $club->logo_path;
        }

        return null;
    }

    private function avatarDataUri(Club $club): string
    {
        $size = 512;
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }

        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
        imagefill($image, 0, 0, $transparent);

        $palette = [
            [30, 64, 175],
            [3, 105, 161],
            [4, 120, 87],
            [180, 83, 9],
            [190, 24, 93],
            [109, 40, 217],
        ];
        $colorIndex = (int) (sprintf('%u', crc32(mb_strtolower((string) $club->club_name))) % count($palette));
        [$red, $green, $blue] = $palette[$colorIndex];
        $background = imagecolorallocate($image, $red, $green, $blue);
        imagefilledellipse($image, $size / 2, $size / 2, $size - 24, $size - 24, $background);

        $initials = $this->initials($club);
        $fontPath = base_path('vendor/endroid/qr-code/assets/open_sans.ttf');
        $fontSize = mb_strlen($initials) === 1 ? 230 : 190;
        $box = imagettfbbox($fontSize, 0, $fontPath, $initials);
        $textWidth = is_array($box) ? $box[2] - $box[0] : 0;
        $textHeight = is_array($box) ? $box[1] - $box[7] : 0;
        $white = imagecolorallocate($image, 255, 255, 255);
        imagettftext(
            $image,
            $fontSize,
            0,
            (int) (($size - $textWidth) / 2),
            (int) (($size + $textHeight) / 2),
            $white,
            $fontPath,
            $initials,
        );

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png ?: '');
    }
}
