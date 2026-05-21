<?php

namespace App\Services\Finance;

use App\Models\Event;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\Request;

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
}
