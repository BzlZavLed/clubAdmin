<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use App\Support\GeneratedPdfResponse;
use Barryvdh\DomPDF\Facade\Pdf;

class FinanceLedgerPdfGenerator
{
    public function __construct(
        private readonly FinanceEngine $financeEngine,
        private readonly DocumentValidationService $documentValidationService,
        private readonly ClubLogoService $clubLogoService,
    ) {
    }

    public function generate(Club $club, User $user, array $validated): array
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '180');
        @ini_set('zlib.output_compression', '0');

        $includeAnnexes = (bool) ($validated['include_annexes'] ?? false);
        $includeIncomeReceiptAnnexes = (bool) ($validated['include_income_receipt_annexes'] ?? false);

        $report = $this->financeEngine->movementReport($club, [
            ...$validated,
            'limit' => $validated['limit'] ?? 5000,
        ]);

        $receiptAnnexes = $includeAnnexes ? $this->ledgerReceiptAnnexes($report, $includeIncomeReceiptAnnexes) : [];
        $generatedAt = now();

        $validation = $this->documentValidationService->create(
            documentType: 'finance_engine_movements',
            title: 'Libro contable financiero',
            snapshot: [
                'club_id' => $club->id,
                'filters' => $report['filters'] ?? [],
                'summary' => $report['summary'] ?? [],
                'movements' => collect($report['movements'] ?? [])->map(fn (array $movement) => [
                    'movement_id' => $movement['movement_id'] ?? null,
                    'date' => $movement['date'] ?? null,
                    'domain' => $movement['domain'] ?? null,
                    'kind' => $movement['kind'] ?? null,
                    'account' => $movement['account'] ?? null,
                    'concept' => $movement['concept'] ?? null,
                    'display_concept' => $movement['display_concept'] ?? null,
                    'notes' => $movement['notes'] ?? null,
                    'amount' => $movement['amount'] ?? null,
                    'signed_amount' => $movement['signed_amount'] ?? null,
                    'balance_after' => $movement['balance_after'] ?? null,
                    'receipt' => $movement['receipt']['number'] ?? null,
                    'status' => $movement['status'] ?? null,
                ])->all(),
            ],
            metadata: [
                'Club' => $club->club_name,
                'Documento' => 'Libro contable financiero',
                'Movimientos' => (string) count($report['movements'] ?? []),
                'Anexos' => $includeAnnexes ? (string) count($receiptAnnexes) : 'No incluidos',
            ],
            generatedBy: $user,
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $this->clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
            'receiptAnnexes' => [],
            'ledgerOnly' => true,
            'includeIncomeReceiptAnnexes' => false,
        ])->setPaper('a4', 'landscape');

        $payload = GeneratedPdfResponse::store(
            $pdf->output(),
            'generated/finance-ledgers',
            'finance-ledger-' . $club->id,
            'finance-ledger.pdf'
        );
        $payload['files'] = [[
            'label' => 'Libro contable',
            'file_name' => $payload['file_name'],
            'url' => $payload['url'],
            'size' => $payload['size'] ?? null,
        ]];

        if ($includeAnnexes) {
            $appendixPdf = Pdf::loadView('reports.finance_engine_movements', [
                'club' => $club,
                'report' => $report,
                'generatedAt' => $generatedAt,
                'clubLogoDataUri' => $this->clubLogoService->dataUri($club),
                'validationUrl' => $validation['url'],
                'qrCodeDataUri' => $validation['qr_code_data_uri'],
                'receiptAnnexes' => $receiptAnnexes,
                'annexOnly' => true,
                'includeIncomeReceiptAnnexes' => $includeIncomeReceiptAnnexes,
            ])->setPaper('a4', 'portrait');

            $payload['appendix'] = GeneratedPdfResponse::store(
                $appendixPdf->output(),
                'generated/finance-ledgers',
                'finance-ledger-receipts-' . $club->id,
                'finance-ledger-receipts.pdf'
            );
            $payload['files'][] = [
                'label' => 'Recibos y comprobantes',
                'file_name' => $payload['appendix']['file_name'],
                'url' => $payload['appendix']['url'],
                'size' => $payload['appendix']['size'] ?? null,
            ];
        }

        return $payload;
    }

    private function ledgerReceiptAnnexes(array $report, bool $includeIncomeReceipts = false): array
    {
        $annexes = [];
        $seen = [];

        foreach (($report['movements'] ?? []) as $movement) {
            if ($this->isCorrectionMovement($movement)
                || $this->isReimbursementSettlementIncome($movement)
                || $this->isInternalReimbursementBalanceExpense($movement)
            ) {
                continue;
            }

            $receipt = $movement['receipt'] ?? null;
            if (is_array($receipt) && (!empty($receipt['number']) || !empty($receipt['url']))) {
                $isIncomeReceipt = ($movement['domain'] ?? null) === 'income';
                if ($isIncomeReceipt && !$includeIncomeReceipts) {
                    continue;
                }

                if (!$isIncomeReceipt && empty($receipt['url'])) {
                    continue;
                }

                $this->pushLedgerAnnex($annexes, $seen, [
                    'key' => 'receipt:' . ($receipt['url'] ?? $receipt['number']),
                    'reference' => $receipt['number'] ?? $this->movementReference($movement),
                    'title' => 'Recibo ' . ($receipt['number'] ?? $this->movementReference($movement)),
                    'url' => $receipt['url'] ?? null,
                    'document_type' => 'income_receipt',
                    'render_inline_receipt' => $isIncomeReceipt,
                    'receipt' => $isIncomeReceipt ? $this->annexIncomeReceiptPayload($movement, $receipt) : null,
                    'movement' => $this->annexMovementContext($movement),
                ]);
            }

            $proofs = $this->movementProofs($movement);
            $proofTypeTotals = [];
            foreach ($proofs as $proof) {
                $type = $proof['type'] ?? 'proof';
                $proofTypeTotals[$type] = ($proofTypeTotals[$type] ?? 0) + 1;
            }

            $proofTypeCounts = [];
            foreach ($proofs as $proof) {
                $type = $proof['type'] ?? 'proof';
                $proofTypeCounts[$type] = ($proofTypeCounts[$type] ?? 0) + 1;
                $reference = $this->proofReference($movement, $proof);
                if (($proofTypeTotals[$type] ?? 0) > 1) {
                    $reference .= '-' . $proofTypeCounts[$type];
                }

                $path = $proof['path'] ?? null;
                $url = $proof['url'] ?? null;
                if (!$path && !$url) {
                    continue;
                }

                $file = $this->annexFilePayload($path);
                if (empty($file)) {
                    continue;
                }

                $this->pushLedgerAnnex($annexes, $seen, [
                    'key' => 'proof:' . ($path ?: $url ?: $reference),
                    'reference' => $reference,
                    'title' => $this->proofLabel($proof['type'] ?? null) . ' ' . $reference,
                    'url' => $url,
                    'path' => $path,
                    'document_type' => $proof['type'] ?? 'proof',
                    'filename' => $proof['name'] ?? ($path ? basename($path) : null),
                    'data_uri' => $file['data_uri'] ?? null,
                    'mime_type' => $file['mime_type'] ?? null,
                    'movement' => $this->annexMovementContext($movement),
                ]);
            }
        }

        return array_values($annexes);
    }

    private function isCorrectionMovement(array $movement): bool
    {
        $status = $movement['status'] ?? null;
        $kind = (string) ($movement['kind'] ?? '');

        return $status === 'cancellation'
            || str_contains($kind, 'reversal')
            || !empty($movement['canceling_id'])
            || !empty($movement['canceling_movement_key']);
    }

    private function isReimbursementSettlementIncome(array $movement): bool
    {
        if (($movement['domain'] ?? null) !== 'income') {
            return false;
        }

        $account = $movement['account'] ?? $movement['to_account'] ?? null;

        return $account === 'reimbursement_to'
            && (!empty($movement['settles_expense_id']) || !empty($movement['reimbursement_group']));
    }

    private function isInternalReimbursementBalanceExpense(array $movement): bool
    {
        if (($movement['domain'] ?? null) !== 'expense') {
            return false;
        }

        $account = $movement['account'] ?? $movement['from_account'] ?? null;
        $group = $movement['reimbursement_group'] ?? [];
        $hasOriginExpense = !empty($movement['reimbursement_origin_expense_id'])
            || (is_array($group) && !empty($group['origin_expense_id']));

        return $account === 'reimbursement_to'
            && empty($movement['settles_expense_id'])
            && $hasOriginExpense;
    }

    private function pushLedgerAnnex(array &$annexes, array &$seen, array $annex): void
    {
        $keys = $this->ledgerAnnexDedupKeys($annex);
        if (empty($keys)) {
            return;
        }

        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                return;
            }
        }

        foreach ($keys as $key) {
            $seen[$key] = true;
        }

        $annexes[] = $annex;
    }

    private function ledgerAnnexDedupKeys(array $annex): array
    {
        $keys = [];
        foreach (['path', 'url', 'filename', 'reference', 'key'] as $field) {
            $value = $annex[$field] ?? null;
            if (!$value || !is_string($value)) {
                continue;
            }

            $normalized = strtolower(trim($value));
            $keys[] = $field . ':' . $normalized;

            $basename = basename(parse_url($normalized, PHP_URL_PATH) ?: $normalized);
            if ($basename && $basename !== '.' && $basename !== '/') {
                $keys[] = 'basename:' . $basename;
            }
        }

        return array_values(array_unique($keys));
    }

    private function movementProofs(array $movement): array
    {
        if (!empty($movement['proofs']) && is_array($movement['proofs'])) {
            return array_values(array_filter($movement['proofs'], fn ($proof) => is_array($proof)));
        }

        return !empty($movement['proof']) && is_array($movement['proof'])
            ? [$movement['proof']]
            : [];
    }

    private function annexFilePayload(?string $path): array
    {
        if (!$path) {
            return [];
        }

        $fullPath = storage_path('app/public/' . ltrim($path, '/'));
        if (!is_file($fullPath)) {
            return [];
        }

        $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
        if (!str_starts_with($mime, 'image/')) {
            return ['mime_type' => $mime];
        }

        $optimizedDataUri = $this->optimizedAnnexImageDataUri($fullPath);

        return [
            'mime_type' => $optimizedDataUri ? 'image/jpeg' : $mime,
            'data_uri' => $optimizedDataUri ?: 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath)),
        ];
    }

    private function optimizedAnnexImageDataUri(string $fullPath): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $contents = file_get_contents($fullPath);
        if ($contents === false) {
            return null;
        }

        $source = @imagecreatefromstring($contents);
        if (!$source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $maxSide = 1600;
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) {
            imagedestroy($source);

            return null;
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $encoded = imagejpeg($target, null, 72);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (!$encoded || !$jpeg) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function annexMovementContext(array $movement): array
    {
        return [
            'movement_id' => $movement['movement_id'] ?? null,
            'date' => $movement['date'] ?? null,
            'concept' => $movement['display_concept'] ?? $movement['concept'] ?? $movement['reference'] ?? null,
            'original_concept' => $movement['original_concept'] ?? $movement['concept'] ?? null,
            'notes' => $movement['notes'] ?? null,
            'counterparty' => $movement['counterparty'] ?? $movement['created_by'] ?? null,
            'amount' => $movement['amount'] ?? null,
            'signed_amount' => $movement['signed_amount'] ?? null,
            'direction' => $movement['direction'] ?? null,
            'account' => $movement['account_label'] ?? $movement['account'] ?? null,
            'location' => $movement['location'] ?? $movement['from_location'] ?? null,
            'payment_type' => $movement['payment_type'] ?? null,
            'created_by' => $movement['created_by'] ?? null,
            'status' => $movement['status'] ?? null,
            'is_cancelled' => $movement['is_cancelled'] ?? null,
            'related_canceled_movement_key' => $movement['related_canceled_movement_key'] ?? null,
            'canceling_movement_key' => $movement['canceling_movement_key'] ?? null,
        ];
    }

    private function annexIncomeReceiptPayload(array $movement, array $receipt): array
    {
        return [
            'number' => $receipt['number'] ?? $this->movementReference($movement),
            'issued_at' => $receipt['issued_at'] ?? null,
            'issued_to_email' => $receipt['issued_to_email'] ?? null,
            'issued_to_type' => $receipt['issued_to_type'] ?? null,
            'date' => $movement['date'] ?? null,
            'concept' => $movement['display_concept'] ?? $movement['concept'] ?? $movement['reference'] ?? null,
            'original_concept' => $movement['original_concept'] ?? $movement['concept'] ?? null,
            'notes' => $movement['notes'] ?? null,
            'payer' => $movement['counterparty'] ?? null,
            'received_by' => $movement['created_by'] ?? null,
            'account' => $movement['account_label'] ?? $movement['account'] ?? null,
            'location' => $movement['location'] ?? null,
            'payment_type' => $movement['payment_type'] ?? null,
            'amount' => $movement['amount'] ?? null,
            'signed_amount' => $movement['signed_amount'] ?? null,
            'direction' => $movement['direction'] ?? null,
            'status' => $movement['status'] ?? null,
            'movement_id' => $movement['movement_id'] ?? null,
        ];
    }

    private function movementReference(array $movement): string
    {
        $movementId = (string) ($movement['movement_id'] ?? '');
        [$type, $id] = array_pad(explode(':', $movementId, 2), 2, null);

        return match ($type) {
            'payment' => 'PAY-' . $id,
            'expense' => 'EXP-' . $id,
            'treasury' => 'TREAS-' . $id,
            default => strtoupper($movementId ?: 'MOV'),
        };
    }

    private function proofReference(array $movement, array $proof): string
    {
        $movementId = (string) ($movement['movement_id'] ?? '');
        [, $id] = array_pad(explode(':', $movementId, 2), 2, null);

        return match ($proof['type'] ?? null) {
            'check_image' => 'PAY-' . $id,
            'expense_receipt' => 'EXP-' . $id,
            'reimbursement_payment_proof' => 'REIMB-PAY-' . $id,
            'reimbursement_receipt' => 'REIMB-' . $id,
            'fundraiser_investment_receipt' => 'INV-' . $id,
            'treasury_proof' => 'TREAS-' . $id,
            default => $this->movementReference($movement),
        };
    }

    private function proofLabel(?string $type): string
    {
        return match ($type) {
            'check_image' => 'Cheque',
            'expense_receipt' => 'Comprobante de gasto',
            'reimbursement_payment_proof' => 'Comprobante de pago de reembolso',
            'reimbursement_receipt' => 'Comprobante de reembolso',
            'fundraiser_investment_receipt' => 'Comprobante de inversion',
            'treasury_proof' => 'Comprobante de transferencia',
            default => 'Comprobante',
        };
    }
}
