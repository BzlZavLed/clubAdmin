<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinanceEngine;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

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
        $validated = $this->validateMovementFilters($request);
        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);
        $report = $this->financeEngine->movementReport($club, [
            ...$validated,
            'limit' => $validated['limit'] ?? 5000,
        ]);
        $generatedAt = now();

        $validation = $documentValidationService->create(
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
                    'amount' => $movement['amount'] ?? null,
                    'signed_amount' => $movement['signed_amount'] ?? null,
                    'receipt' => $movement['receipt']['number'] ?? null,
                    'status' => $movement['status'] ?? null,
                ])->all(),
            ],
            metadata: [
                'Club' => $club->club_name,
                'Documento' => 'Libro contable financiero',
                'Movimientos' => (string) count($report['movements'] ?? []),
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        return Pdf::loadView('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'landscape')
            ->download('finance-ledger.pdf');
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
                'movements' => collect($data['engine_report']['movements'] ?? [])->map(fn (array $movement) => [
                    'movement_id' => $movement['movement_id'] ?? null,
                    'date' => $movement['date'] ?? null,
                    'domain' => $movement['domain'] ?? null,
                    'account' => $movement['account'] ?? null,
                    'amount' => $movement['amount'] ?? null,
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

        return Pdf::loadView('reports.finance_engine_accounting', [
            'club' => $club,
            'data' => $data,
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'landscape')
            ->download('account-balances.pdf');
    }

    public function storeConcept(Request $request)
    {
        return $this->financeEngine->storeConcept($request);
    }

    public function storeIncome(Request $request)
    {
        return $this->financeEngine->storeIncome($request);
    }

    public function storeExpense(Request $request)
    {
        return $this->financeEngine->storeExpense($request);
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
