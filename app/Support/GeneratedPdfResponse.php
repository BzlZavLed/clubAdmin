<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GeneratedPdfResponse
{
    public static function fromDomPdf($pdf, string $publicDirectory, string $filePrefix, string $downloadName, Request $request)
    {
        $payload = self::store($pdf->output(), $publicDirectory, $filePrefix, $downloadName);

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()->away($payload['url']);
    }

    public static function store(string $contents, string $publicDirectory, string $filePrefix, string $downloadName): array
    {
        $directory = trim($publicDirectory, '/');
        $absoluteDirectory = public_path($directory);

        if (!is_dir($absoluteDirectory)) {
            mkdir($absoluteDirectory, 0755, true);
        }

        $safePrefix = Str::slug($filePrefix) ?: 'document';
        $fileName = $safePrefix . '-' . now()->format('YmdHis') . '-' . uniqid() . '.pdf';
        $path = $absoluteDirectory . DIRECTORY_SEPARATOR . $fileName;

        file_put_contents($path, $contents);

        return [
            'success' => true,
            'file_name' => $downloadName,
            'url' => asset($directory . '/' . $fileName),
            'size' => filesize($path),
        ];
    }
}
