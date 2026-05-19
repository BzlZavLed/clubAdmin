<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FundraiserEvent;
use App\Models\FundraiserProduct;
use App\Models\FundraiserSale;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class FinanceEngine
{
    public function __construct(
        private readonly FinanceActionCatalog $actions,
        private readonly FinanceMovementReader $movements,
        private readonly FinanceWriter $writer,
        private readonly FinanceBootstrapper $bootstrapper,
        private readonly FinanceFundraiserService $fundraisers,
    ) {
    }

    public function actionablesFor(User $user, Club $club): array
    {
        return [
            'engine_version' => 'finance_engine_v1_read_model',
            'scope' => [
                'club_id' => (int) $club->id,
                'club_name' => $club->club_name,
                'role' => $user->profile_type,
            ],
            'groups' => $this->actions->forRole($user->profile_type),
        ];
    }

    public function movementReport(Club $club, array $filters = []): array
    {
        $movements = $this->movements->movementsForClub($club, $filters);

        return [
            'engine_version' => 'finance_engine_v1_read_model',
            'scope' => [
                'club_id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'account' => $filters['account'] ?? null,
                'domain' => $filters['domain'] ?? null,
                'limit' => $filters['limit'] ?? null,
            ],
            'summary' => $this->movements->summaryForClub($club),
            'movements' => $movements->values()->all(),
        ];
    }

    public function cashboxData(User $user, Club $club, array $filters = []): array
    {
        return $this->bootstrapper->cashboxData($user, $club, $filters);
    }

    public function accountingData(User $user, Club $club, array $filters = []): array
    {
        return $this->bootstrapper->accountingData($user, $club, $filters);
    }

    public function fundraiserData(User $user, Club $club): array
    {
        return $this->fundraisers->data($user, $club);
    }

    public function fundraiserKitchenEvent(FundraiserEvent $fundraiserEvent): array
    {
        return $this->fundraisers->kitchenEvent($fundraiserEvent);
    }

    public function fundraiserKitchenData(FundraiserEvent $fundraiserEvent): array
    {
        return $this->fundraisers->kitchenData($fundraiserEvent);
    }

    public function finishFundraiserKitchenOrder(Request $request, FundraiserEvent $fundraiserEvent, FundraiserSale $fundraiserSale)
    {
        return $this->fundraisers->finishKitchenOrder($request, $fundraiserEvent, $fundraiserSale);
    }

    public function storeConcept(Request $request)
    {
        return $this->writer->storeConcept($request);
    }

    public function storeIncome(Request $request)
    {
        return $this->writer->storeIncome($request);
    }

    public function storeFundraiserEvent(Request $request)
    {
        return $this->fundraisers->storeEvent($request);
    }

    public function storeFundraiserProduct(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->fundraisers->storeProduct($request, $fundraiserEvent);
    }

    public function updateFundraiserProduct(Request $request, FundraiserProduct $fundraiserProduct)
    {
        return $this->fundraisers->updateProduct($request, $fundraiserProduct);
    }

    public function storeFundraiserSale(Request $request, FundraiserEvent $fundraiserEvent)
    {
        return $this->fundraisers->storeSale($request, $fundraiserEvent);
    }

    public function storeExpense(Request $request)
    {
        return $this->writer->storeExpense($request);
    }

    public function uploadExpenseReceipt(Request $request, Expense $expense)
    {
        return $this->writer->uploadExpenseReceipt($request, $expense);
    }

    public function removeExpenseReceipt(Request $request, Expense $expense)
    {
        return $this->writer->removeExpenseReceipt($request, $expense);
    }

    public function uploadReimbursementReceipt(Request $request, Expense $expense)
    {
        return $this->writer->uploadReimbursementReceipt($request, $expense);
    }

    public function removeReimbursementReceipt(Request $request, Expense $expense)
    {
        return $this->writer->removeReimbursementReceipt($request, $expense);
    }

    public function markExpenseReimbursed(Request $request, Expense $expense)
    {
        return $this->writer->markExpenseReimbursed($request, $expense);
    }

    public function storeTransfer(Request $request)
    {
        return $this->writer->storeTransfer($request);
    }

    public function validateStaffRemittance(Request $request)
    {
        return $this->writer->validateStaffRemittance($request);
    }

    public function storeEventSettlement(Request $request, Event $event)
    {
        return $this->writer->storeEventSettlement($request, $event);
    }

    public function reversePayment(Request $request, Payment $payment)
    {
        return $this->writer->reversePayment($request, $payment);
    }

    public function reverseExpense(Request $request, Expense $expense)
    {
        return $this->writer->reverseExpense($request, $expense);
    }

    public function reverseReimbursement(Request $request, Expense $expense)
    {
        return $this->writer->reverseReimbursement($request, $expense);
    }
}
