<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Expense;
use App\Services\DocumentValidationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReimbursementReceiptController extends Controller
{
    public function show(Request $request, Expense $expense, string $token): Response
    {
        $expense = $this->validatedReceipt($expense, $token);
        if ($expense->reimbursement_receipt_signed_at) {
            $this->ensurePdfReceipt($expense);
            $expense = $this->validatedReceipt($expense->refresh(), $token);
        }

        return Inertia::render('Public/ReimbursementReceipt', [
            'receipt' => $this->receiptPayload($expense),
            'submit_url' => route('reimbursement-receipts.signature', [
                'expense' => $expense,
                'token' => $expense->reimbursement_receipt_token,
            ]),
        ]);
    }

    public function sign(Request $request, Expense $expense, string $token): JsonResponse
    {
        $expense = $this->validatedReceipt($expense, $token);

        if ($expense->reimbursement_receipt_signed_at) {
            return response()->json([
                'message' => 'Este recibo ya fue firmado.',
                'data' => $this->receiptPayload($expense),
            ], 422);
        }

        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:1500000'],
            'acknowledged' => ['accepted'],
        ]);

        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $validated['signature_data'], $matches)) {
            return response()->json([
                'message' => 'La firma debe enviarse como imagen PNG.',
                'errors' => ['signature_data' => ['La firma debe enviarse como imagen PNG.']],
            ], 422);
        }

        $image = base64_decode($matches[1], true);
        if (!$image || strlen($image) > 1024 * 1024) {
            return response()->json([
                'message' => 'La firma no es valida o es demasiado grande.',
                'errors' => ['signature_data' => ['La firma no es valida o es demasiado grande.']],
            ], 422);
        }

        $signaturePath = 'reimbursement-signatures/reimbursement-' . $expense->id . '-' . $expense->reimbursement_receipt_token . '.png';
        Storage::disk('public')->put($signaturePath, $image);

        $expense->update([
            'reimbursement_receipt_signed_at' => now(),
            'reimbursement_receipt_signature_path' => $signaturePath,
            'reimbursement_receipt_signer_name' => trim($validated['signer_name']),
            'reimbursement_receipt_acknowledged' => true,
            'reimbursement_receipt_ip' => $request->ip(),
            'reimbursement_receipt_user_agent' => substr((string) $request->userAgent(), 0, 2000),
        ]);

        $this->ensurePdfReceipt($expense->refresh(), force: true);

        return response()->json([
            'message' => 'Recibo firmado.',
            'data' => $this->receiptPayload($expense->refresh()),
        ]);
    }

    public function download(Expense $expense, string $token)
    {
        $expense = $this->validatedReceipt($expense, $token);
        abort_unless($expense->reimbursement_receipt_signed_at, 404);

        $path = $this->ensurePdfReceipt($expense);

        return Storage::disk('public')->download(
            $path,
            'reimbursement-receipt-' . $expense->id . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function qr(Expense $expense, string $token, DocumentValidationService $documentValidationService)
    {
        $expense = $this->validatedReceipt($expense, $token);

        return response($documentValidationService->qrCodeSvg($expense->reimbursement_confirmation_url), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function validatedReceipt(Expense $expense, string $token): Expense
    {
        abort_unless(
            $expense->pay_to === 'reimbursement_to'
            && $expense->status === 'completed'
            && $expense->reimbursement_receipt_token
            && hash_equals((string) $expense->reimbursement_receipt_token, $token),
            404
        );

        return $expense->loadMissing([
            'club:id,club_name',
            'createdBy:id,name',
            'reimbursementPayee:id,club_id,name,phone,email',
            'reimbursementOriginExpense:id,pay_to,amount,expense_date,description',
            'settlementExpense:id,settles_expense_id,pay_to,funds_location,amount,expense_date,description,created_by_user_id',
            'settlementExpense.createdBy:id,name',
        ]);
    }

    private function receiptPayload(Expense $expense): array
    {
        $settlement = $expense->settlementExpense;
        $origin = $expense->reimbursementOriginExpense;

        return [
            'id' => (int) $expense->id,
            'club_name' => $expense->club?->club_name,
            'amount' => (float) $expense->amount,
            'description' => $expense->description,
            'reimbursed_to' => $expense->reimbursementPayee?->name ?: $expense->reimbursed_to,
            'payee' => $expense->reimbursementPayee ? [
                'name' => $expense->reimbursementPayee->name,
                'phone' => $expense->reimbursementPayee->phone,
                'email' => $expense->reimbursementPayee->email,
            ] : null,
            'expense_date' => optional($expense->expense_date)->toDateString(),
            'settlement_date' => optional($settlement?->expense_date)->toDateString(),
            'settlement_account' => $settlement?->pay_to,
            'settlement_account_label' => $this->accountLabel($expense, $settlement?->pay_to),
            'settlement_location' => $settlement?->funds_location,
            'settled_by' => $settlement?->createdBy?->name,
            'origin_expense' => $origin ? [
                'id' => (int) $origin->id,
                'description' => $origin->description,
                'amount' => (float) $origin->amount,
                'expense_date' => optional($origin->expense_date)->toDateString(),
                'account' => $origin->pay_to,
            ] : null,
            'proof_url' => $expense->reimbursement_receipt_url,
            'download_url' => $expense->reimbursement_receipt_signed_at ? route('reimbursement-receipts.download', [
                'expense' => $expense,
                'token' => $expense->reimbursement_receipt_token,
            ]) : null,
            'signed_at' => optional($expense->reimbursement_receipt_signed_at)->toDateTimeString(),
            'signer_name' => $expense->reimbursement_receipt_signer_name,
            'signature_url' => $expense->reimbursement_signature_url,
            'acknowledged' => (bool) $expense->reimbursement_receipt_acknowledged,
        ];
    }

    private function ensurePdfReceipt(Expense $expense, bool $force = false): string
    {
        abort_unless($expense->reimbursement_receipt_signed_at, 404);

        if (
            !$force
            && $expense->reimbursement_receipt_path
            && str_ends_with(strtolower($expense->reimbursement_receipt_path), '-v2.pdf')
            && str_ends_with(strtolower($expense->reimbursement_receipt_path), '.pdf')
            && $expense->reimbursement_receipt_validation_checksum
            && Storage::disk('public')->exists($expense->reimbursement_receipt_path)
        ) {
            return $expense->reimbursement_receipt_path;
        }

        $expense = $this->validatedReceipt($expense->refresh(), (string) $expense->reimbursement_receipt_token);
        $oldPath = $expense->reimbursement_receipt_path;
        $path = 'reimbursement-receipts/reimbursement-receipt-' . $expense->id . '-' . $expense->reimbursement_receipt_token . '-v2.pdf';
        $receipt = $this->receiptPayload($expense);
        $generatedAt = $expense->reimbursement_receipt_signed_at ?: now();
        $validation = app(DocumentValidationService::class)->create(
            documentType: 'reimbursement_receipt',
            title: 'Recibo de reembolso #' . $expense->id,
            snapshot: [
                'expense_id' => (int) $expense->id,
                'club_id' => (int) $expense->club_id,
                'club_name' => $expense->club?->club_name,
                'amount' => (float) $expense->amount,
                'reimbursed_to' => $expense->reimbursementPayee?->name ?: $expense->reimbursed_to,
                'settlement_expense_id' => $expense->settlementExpense?->id,
                'settlement_date' => optional($expense->settlementExpense?->expense_date)->toDateString(),
                'origin_expense_id' => $expense->reimbursementOriginExpense?->id,
                'signed_at' => optional($expense->reimbursement_receipt_signed_at)->toDateTimeString(),
                'signer_name' => $expense->reimbursement_receipt_signer_name,
                'acknowledged' => (bool) $expense->reimbursement_receipt_acknowledged,
            ],
            metadata: [
                'Documento' => 'Recibo de reembolso',
                'Club' => $expense->club?->club_name ?? '—',
                'Reembolso' => '#' . $expense->id,
                'Persona' => $expense->reimbursementPayee?->name ?: ($expense->reimbursed_to ?? '—'),
                'Importe' => '$' . number_format((float) $expense->amount, 2),
            ],
            generatedBy: $expense->settlementExpense?->createdBy,
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('pdf.reimbursement_receipt', [
            'receipt' => $receipt,
            'signatureDataUri' => $this->storageImageDataUri($expense->reimbursement_receipt_signature_path),
            'generatedAt' => $generatedAt,
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'portrait');

        Storage::disk('public')->put($path, $pdf->output());

        if ($oldPath && $oldPath !== $path && str_starts_with($oldPath, 'reimbursement-receipts/')) {
            Storage::disk('public')->delete($oldPath);
        }

        $expense->update([
            'reimbursement_receipt_path' => $path,
            'reimbursement_receipt_validation_checksum' => $validation['checksum'],
        ]);

        if ($settlementExpense = $expense->settlementExpense()->first()) {
            $settlementExpense->update([
                'receipt_path' => $path,
                'status' => 'completed',
            ]);
        }

        return $path;
    }

    private function storageImageDataUri(?string $path): ?string
    {
        if (!$path || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($path));
    }

    private function accountLabel(Expense $expense, ?string $payTo): ?string
    {
        if (!$payTo) {
            return null;
        }

        return Account::query()
            ->where('club_id', $expense->club_id)
            ->where('pay_to', $payTo)
            ->value('label') ?: $payTo;
    }
}
