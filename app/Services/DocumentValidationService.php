<?php

namespace App\Services;

use App\Models\DocumentValidation;
use App\Models\User;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DocumentValidationService
{
    public function create(
        string $documentType,
        string $title,
        array $snapshot,
        array $metadata = [],
        ?User $generatedBy = null,
        ?Carbon $generatedAt = null,
    ): array {
        $generatedAt ??= now();
        $checksumSource = [
            'document_type' => $documentType,
            'title' => $title,
            'generated_at' => $generatedAt->toISOString(),
            'nonce' => (string) Str::uuid(),
            'snapshot' => $snapshot,
            'metadata' => $metadata,
        ];

        $checksumPayload = json_encode($checksumSource, JSON_PARTIAL_OUTPUT_ON_ERROR);
        $checksum = strtoupper(hash_hmac('sha256', $checksumPayload ?: serialize($checksumSource), config('app.key')));
        $url = route('documents.validate', ['checksum' => $checksum]);

        $validation = DocumentValidation::query()->updateOrCreate(
            ['checksum' => $checksum],
            [
                'document_type' => $documentType,
                'title' => $title,
                'generated_by_user_id' => $generatedBy?->id,
                'metadata' => $metadata,
                'document_snapshot' => $checksumSource,
                'generated_at' => $generatedAt,
            ]
        );

        return [
            'validation' => $validation,
            'checksum' => $checksum,
            'url' => $url,
            'qr_code_data_uri' => $this->qrCodeDataUri($url),
        ];
    }

    public function qrCodeDataUri(string $data): string
    {
        $qrCode = new QrCode(
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 160,
            margin: 8,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new SvgWriter())->write($qrCode)->getDataUri();
    }
}
