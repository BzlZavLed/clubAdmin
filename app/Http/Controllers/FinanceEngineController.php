<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinanceEngine;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FundraiserEvent;
use App\Models\FundraiserEventPartner;
use App\Models\FundraiserProduct;
use App\Models\FundraiserSale;
use App\Models\Payment;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use App\Support\ClubHelper;
use App\Support\GeneratedPdfResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class FinanceEngineController extends Controller
{
    public function __construct(private readonly FinanceEngine $financeEngine)
    {
    }

    public function actionables(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));

        return response()->json([
            'data' => $this->financeEngine->actionablesFor($request->user(), $club),
        ]);
    }

    public function movements(Request $request)
    {
        $validated = $this->validateMovementFilters($request);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);

        return response()->json([
            'data' => $this->financeEngine->movementReport($club, $validated),
        ]);
    }

    public function movementsPdf(Request $request, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '180');
        @ini_set('zlib.output_compression', '0');

        $validated = $this->validateMovementFilters($request);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);

        $report = $this->financeEngine->movementReport($club, [
            ...$validated,
            'limit' => $validated['limit'] ?? 5000,
        ]);

        $receiptAnnexes = $this->ledgerReceiptAnnexes($report);
        $generatedAt = now();

        $validation = $documentValidationService->create(
            documentType: 'finance_engine_movements',
            title: 'Libro contable financiero',
            snapshot: [
                'club_id' => $club->id,
                'filters' => $report['filters'] ?? [],
                'summary' => $report['summary'] ?? [],
                'movements' => collect($report['movements'] ?? [])->map(fn(array $movement) => [
                    'movement_id' => $movement['movement_id'] ?? null,
                    'date' => $movement['date'] ?? null,
                    'domain' => $movement['domain'] ?? null,
                    'kind' => $movement['kind'] ?? null,
                    'account' => $movement['account'] ?? null,
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
                'Anexos' => (string) count($receiptAnnexes),
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
            'receiptAnnexes' => $receiptAnnexes,
        ])->setPaper('a4', 'landscape');

        return GeneratedPdfResponse::fromDomPdf(
            $pdf,
            'generated/finance-ledgers',
            'finance-ledger-' . $club->id,
            'finance-ledger.pdf',
            $request
        );
    }

    private function ledgerReceiptAnnexes(array $report): array
    {
        $annexes = [];
        $seen = [];

        foreach (($report['movements'] ?? []) as $movement) {
            $receipt = $movement['receipt'] ?? null;
            if (is_array($receipt) && (!empty($receipt['number']) || !empty($receipt['url']))) {
                $this->pushLedgerAnnex($annexes, $seen, [
                    'key' => 'receipt:' . ($receipt['url'] ?? $receipt['number']),
                    'reference' => $receipt['number'] ?? $this->movementReference($movement),
                    'title' => 'Recibo ' . ($receipt['number'] ?? $this->movementReference($movement)),
                    'url' => $receipt['url'] ?? null,
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
                $file = $this->annexFilePayload($path);

                $this->pushLedgerAnnex($annexes, $seen, [
                    'key' => 'proof:' . ($path ?: $url ?: $reference),
                    'reference' => $reference,
                    'title' => $this->proofLabel($proof['type'] ?? null) . ' ' . $reference,
                    'url' => $url,
                    'filename' => $proof['name'] ?? ($path ? basename($path) : null),
                    'data_uri' => $file['data_uri'] ?? null,
                    'mime_type' => $file['mime_type'] ?? null,
                    'movement' => $this->annexMovementContext($movement),
                ]);
            }
        }

        return array_values($annexes);
    }

    private function pushLedgerAnnex(array &$annexes, array &$seen, array $annex): void
    {
        $key = $annex['key'] ?? null;
        if (!$key || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $annexes[] = $annex;
    }

    private function movementProofs(array $movement): array
    {
        if (!empty($movement['proofs']) && is_array($movement['proofs'])) {
            return array_values(array_filter($movement['proofs'], fn($proof) => is_array($proof)));
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

        return [
            'mime_type' => $mime,
            'data_uri' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath)),
        ];
    }

    private function annexMovementContext(array $movement): array
    {
        return [
            'movement_id' => $movement['movement_id'] ?? null,
            'date' => $movement['date'] ?? null,
            'concept' => $movement['concept'] ?? $movement['reference'] ?? null,
            'counterparty' => $movement['counterparty'] ?? $movement['created_by'] ?? null,
            'amount' => $movement['amount'] ?? null,
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
            'reimbursement_receipt' => 'Comprobante de reembolso',
            'fundraiser_investment_receipt' => 'Comprobante de inversion',
            'treasury_proof' => 'Comprobante de transferencia',
            default => 'Comprobante',
        };
    }

    public function cashbox(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);

        return response()->json([
            'data' => $this->financeEngine->cashboxData($request->user(), $club, $validated),
        ]);
    }

    public function accounting(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);

        return response()->json([
            'data' => $this->financeEngine->accountingData($request->user(), $club, $validated),
        ]);
    }

    public function fundraisers(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);

        return response()->json([
            'data' => $this->financeEngine->fundraiserData($request->user(), $club),
        ]);
    }

    public function fundraiserKitchen(FundraiserEvent $fundraiserEvent)
    {
        return Inertia::render('ClubDirector/Finance/FundraiserKitchen', [
            'event' => $this->financeEngine->fundraiserKitchenEvent($fundraiserEvent),
            'data_url' => URL::signedRoute('fundraisers.kitchen.orders', ['fundraiserEvent' => $fundraiserEvent]),
        ]);
    }

    public function fundraiserKitchenOrders(FundraiserEvent $fundraiserEvent)
    {
        return response()->json([
            'data' => $this->financeEngine->fundraiserKitchenData($fundraiserEvent),
        ]);
    }

    public function finishFundraiserKitchenOrder(Request $request, FundraiserEvent $fundraiserEvent, FundraiserSale $fundraiserSale)
    {
        return $this->financeEngine->finishFundraiserKitchenOrder($request, $fundraiserEvent, $fundraiserSale);
    }

    public function accountingPdf(Request $request, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);
        $data = $this->financeEngine->accountingData($request->user(), $club, [
            ...$validated,
            'limit' => $validated['limit'] ?? 5000,
        ]);
        $generatedAt = now();

        $validation = $documentValidationService->create(
            documentType: 'finance_engine_accounting',
            title: 'Saldos y contabilidad financiera',
            snapshot: [
                'club_id' => $club->id,
                'summary' => $data['treasury']['summary'] ?? [],
                'account_report' => [
                    'accounts' => $data['account_report']['accounts'] ?? [],
                ],
                'movements' => collect($data['engine_report']['movements'] ?? [])->map(fn(array $movement) => [
                    'movement_id' => $movement['movement_id'] ?? null,
                    'date' => $movement['date'] ?? null,
                    'domain' => $movement['domain'] ?? null,
                    'account' => $movement['account'] ?? null,
                    'amount' => $movement['amount'] ?? null,
                    'balance_after' => $movement['balance_after'] ?? null,
                    'receipt' => $movement['receipt']['number'] ?? null,
                    'status' => $movement['status'] ?? null,
                ])->all(),
            ],
            metadata: [
                'Club' => $club->club_name,
                'Documento' => 'Saldos y contabilidad financiera',
                'Cuentas' => (string) count($data['treasury']['summary']['accounts'] ?? []),
                'Movimientos' => (string) count($data['engine_report']['movements'] ?? []),
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('reports.finance_engine_accounting', [
            'club' => $club,
            'data' => $data,
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'landscape');

        return GeneratedPdfResponse::fromDomPdf(
            $pdf,
            'generated/finance-accounting',
            'account-balances-' . $club->id,
            'account-balances.pdf',
            $request
        );
    }

    public function storeConcept(Request $request)
    {
        return $this->financeEngine->storeConcept($request);
    }

    public function storeIncome(Request $request)
    {
        return $this->financeEngine->storeIncome($request);
    }

    public function storeFundraiserEvent(Request $request)
    {
        return $this->financeEngine->storeFundraiserEvent($request);
    }

    public function storeFundraiserProduct(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->financeEngine->storeFundraiserProduct($request, $fundraiserEvent);
    }

    public function updateFundraiserProduct(Request $request, FundraiserProduct $fundraiserProduct)
    {
        return $this->financeEngine->updateFundraiserProduct($request, $fundraiserProduct);
    }

    public function storeFundraiserSale(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->financeEngine->storeFundraiserSale($request, $fundraiserEvent);
    }

    public function closeFundraiserEvent(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->financeEngine->closeFundraiserEvent($request, $fundraiserEvent);
    }

    public function uploadFundraiserInvestmentReceipts(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->financeEngine->uploadFundraiserInvestmentReceipts($request, $fundraiserEvent);
    }

    public function storeFundraiserPartner(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->financeEngine->storeFundraiserPartner($request, $fundraiserEvent);
    }

    public function recordFundraiserPartnerContribution(Request $request, FundraiserEventPartner $fundraiserEventPartner)
    {
        return $this->financeEngine->recordFundraiserPartnerContribution($request, $fundraiserEventPartner);
    }

    public function recordFundraiserPartnerDistribution(Request $request, FundraiserEventPartner $fundraiserEventPartner)
    {
        return $this->financeEngine->recordFundraiserPartnerDistribution($request, $fundraiserEventPartner);
    }

    public function storeExpense(Request $request)
    {
        return $this->financeEngine->storeExpense($request);
    }

    public function uploadExpenseReceipt(Request $request, Expense $expense)
    {
        return $this->financeEngine->uploadExpenseReceipt($request, $expense);
    }

    public function removeExpenseReceipt(Request $request, Expense $expense)
    {
        return $this->financeEngine->removeExpenseReceipt($request, $expense);
    }

    public function uploadReimbursementReceipt(Request $request, Expense $expense)
    {
        return $this->financeEngine->uploadReimbursementReceipt($request, $expense);
    }

    public function removeReimbursementReceipt(Request $request, Expense $expense)
    {
        return $this->financeEngine->removeReimbursementReceipt($request, $expense);
    }

    public function markExpenseReimbursed(Request $request, Expense $expense)
    {
        return $this->financeEngine->markExpenseReimbursed($request, $expense);
    }

    public function storeTransfer(Request $request)
    {
        return $this->financeEngine->storeTransfer($request);
    }

    public function validateStaffRemittance(Request $request)
    {
        return $this->financeEngine->validateStaffRemittance($request);
    }

    public function storeEventSettlement(Request $request, Event $event)
    {
        return $this->financeEngine->storeEventSettlement($request, $event);
    }

    public function reversePayment(Request $request, Payment $payment)
    {
        return $this->financeEngine->reversePayment($request, $payment);
    }

    public function reverseExpense(Request $request, Expense $expense)
    {
        return $this->financeEngine->reverseExpense($request, $expense);
    }

    public function reverseReimbursement(Request $request, Expense $expense)
    {
        return $this->financeEngine->reverseReimbursement($request, $expense);
    }

    private function validateMovementFilters(Request $request): array
    {
        return $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'account' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'in:income,expense,transfer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
    }
}
