<?php

namespace App\Services\Finance;

use App\Models\Event;
use App\Models\Expense;
use App\Models\FinanceMovementConceptOverride;
use App\Models\Payment;
use App\Models\TreasuryMovement;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceWriter
{
    public function __construct(
        private readonly FinanceConceptWriter $conceptWriter,
        private readonly FinanceIncomeWriter $incomeWriter,
        private readonly FinanceExpenseWriter $expenseWriter,
        private readonly FinanceTransferWriter $transferWriter,
        private readonly FinanceEventSettlementWriter $eventSettlementWriter,
        private readonly FinanceCorrectionWriter $correctionWriter,
    ) {
    }

    public function storeConcept(Request $request)
    {
        $this->forceJson($request);

        return $this->conceptWriter->store($request);
    }

    public function storeIncome(Request $request)
    {
        $this->forceJson($request);

        return $this->incomeWriter->store($request);
    }

    public function storeExpense(Request $request)
    {
        $this->forceJson($request);

        return $this->expenseWriter->store($request);
    }

    public function uploadExpenseReceipt(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->uploadReceipt($request, $expense);
    }

    public function removeExpenseReceipt(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->removeReceipt($request, $expense);
    }

    public function uploadReimbursementReceipt(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->uploadReimbursementReceipt($request, $expense);
    }

    public function uploadReimbursementPaymentProof(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->uploadReimbursementPaymentProof($request, $expense);
    }

    public function removeReimbursementReceipt(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->removeReimbursementReceipt($request, $expense);
    }

    public function removeReimbursementPaymentProof(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->removeReimbursementPaymentProof($request, $expense);
    }

    public function markExpenseReimbursed(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->expenseWriter->markReimbursed($request, $expense);
    }

    public function storeTransfer(Request $request)
    {
        $this->forceJson($request);

        return $this->transferWriter->storeMovement($request);
    }

    public function updateMovementDisplayConcept(Request $request, string $movementType, int $movementId)
    {
        $this->forceJson($request);

        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'display_concept' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'movement_type' => ['nullable', Rule::in(['payment', 'expense', 'treasury'])],
        ]);

        abort_unless(in_array($movementType, ['payment', 'expense', 'treasury'], true), 404);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id']);
        [$clubId, $originalConcept] = $this->movementConceptTarget($movementType, $movementId);

        abort_unless((int) $clubId === (int) $club->id, 403, 'Unauthorized.');

        $displayConcept = trim((string) ($validated['display_concept'] ?? ''));
        $notes = trim((string) ($validated['notes'] ?? ''));
        $this->updateMovementNotes($movementType, $movementId, $notes);

        if ($displayConcept === '') {
            FinanceMovementConceptOverride::query()
                ->where('club_id', $club->id)
                ->where('movement_type', $movementType)
                ->where('movement_id', $movementId)
                ->delete();

            return response()->json([
                'message' => 'Concept override removed',
                'data' => [
                    'movement_type' => $movementType,
                    'movement_id' => $movementId,
                    'movement_key' => "{$movementType}:{$movementId}",
                    'display_concept' => null,
                    'original_concept' => $originalConcept,
                    'notes' => $notes ?: null,
                ],
            ]);
        }

        $override = FinanceMovementConceptOverride::query()->updateOrCreate(
            [
                'club_id' => $club->id,
                'movement_type' => $movementType,
                'movement_id' => $movementId,
            ],
            [
                'original_concept' => $originalConcept,
                'display_concept' => $displayConcept,
                'updated_by_user_id' => $request->user()?->id,
            ]
        );

        return response()->json([
            'message' => 'Concept override saved',
            'data' => [
                'id' => (int) $override->id,
                'movement_type' => $movementType,
                'movement_id' => $movementId,
                'movement_key' => "{$movementType}:{$movementId}",
                'display_concept' => $override->display_concept,
                'original_concept' => $override->original_concept,
                'notes' => $notes ?: null,
                'updated_at' => $override->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function validateStaffRemittance(Request $request)
    {
        $this->forceJson($request);

        return $this->transferWriter->validateStaffRemittance($request);
    }

    public function storeEventSettlement(Request $request, Event $event)
    {
        $this->forceJson($request);

        return $this->eventSettlementWriter->store($request, $event);
    }

    public function reversePayment(Request $request, Payment $payment)
    {
        $this->forceJson($request);

        return $this->correctionWriter->reversePayment($request, $payment);
    }

    public function reverseExpense(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->correctionWriter->reverseExpense($request, $expense);
    }

    public function reverseReimbursement(Request $request, Expense $expense)
    {
        $this->forceJson($request);

        return $this->correctionWriter->reverseReimbursement($request, $expense);
    }

    private function forceJson(Request $request): void
    {
        $request->headers->set('Accept', 'application/json');
    }

    private function movementConceptTarget(string $movementType, int $movementId): array
    {
        return match ($movementType) {
            'payment' => $this->paymentConceptTarget($movementId),
            'expense' => $this->expenseConceptTarget($movementId),
            'treasury' => $this->treasuryConceptTarget($movementId),
        };
    }

    private function paymentConceptTarget(int $movementId): array
    {
        $payment = Payment::query()
            ->with(['concept:id,concept,event_id', 'concept.event:id,title'])
            ->findOrFail($movementId);

        return [
            $payment->club_id,
            $payment->concept?->event?->title ?: $payment->concept?->concept ?: $payment->concept_text,
        ];
    }

    private function expenseConceptTarget(int $movementId): array
    {
        $expense = Expense::query()
            ->with(['event:id,title'])
            ->findOrFail($movementId);

        return [
            $expense->club_id,
            $expense->event?->title ?: $expense->description,
        ];
    }

    private function treasuryConceptTarget(int $movementId): array
    {
        $movement = TreasuryMovement::query()
            ->with(['event:id,title'])
            ->findOrFail($movementId);

        return [
            $movement->club_id,
            $movement->event?->title ?: $movement->reference,
        ];
    }

    private function updateMovementNotes(string $movementType, int $movementId, string $notes): void
    {
        $value = $notes !== '' ? $notes : null;

        match ($movementType) {
            'payment' => Payment::query()->whereKey($movementId)->update(['notes' => $value]),
            'expense' => Expense::query()->whereKey($movementId)->update(['notes' => $value]),
            'treasury' => TreasuryMovement::query()->whereKey($movementId)->update(['notes' => $value]),
        };
    }
}
