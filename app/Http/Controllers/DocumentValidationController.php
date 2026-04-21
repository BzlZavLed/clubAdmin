<?php

namespace App\Http\Controllers;

use App\Models\DocumentValidation;

class DocumentValidationController extends Controller
{
    public function show(string $checksum)
    {
        $normalizedChecksum = strtoupper(trim($checksum));
        $validation = DocumentValidation::query()
            ->where('checksum', $normalizedChecksum)
            ->first();

        if ($validation) {
            $validation->forceFill([
                'last_validated_at' => now(),
                'validation_count' => ((int) $validation->validation_count) + 1,
            ])->save();
        }

        return response()->view('public.document_validation', [
            'checksum' => $normalizedChecksum,
            'validation' => $validation,
            'isValid' => (bool) $validation,
            'metadata' => $validation?->metadata ?? [],
        ]);
    }
}
