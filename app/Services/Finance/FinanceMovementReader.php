<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\TreasuryMovement;
use App\Services\AttendanceDuesPaymentService;
use App\Services\ClubTreasuryService;
use App\Support\ClubHelper;
use Illuminate\Support\Collection;

class FinanceMovementReader
{
    public function __construct(private readonly ClubTreasuryService $treasuryService)
    {
    }

    public function movementsForClub(Club $club, array $filters = []): Collection
    {
        $movements = collect()
            ->merge($this->paymentMovements($club, $filters))
            ->merge($this->expenseMovements($club, $filters))
            ->merge($this->treasuryMovements($club, $filters));

        if (!empty($filters['account'])) {
            $account = $filters['account'];
            $movements = $movements->filter(fn (array $row) => in_array($account, [
                $row['account'] ?? null,
                $row['from_account'] ?? null,
                $row['to_account'] ?? null,
            ], true));
        }

        if (!empty($filters['domain'])) {
            $movements = $movements->where('domain', $filters['domain']);
        }

        return $movements
            ->sortByDesc(fn (array $row) => sprintf('%s-%010d', $row['date'] ?? '0000-00-00', $row['id'] ?? 0))
            ->values()
            ->when(!empty($filters['limit']), fn (Collection $rows) => $rows->take((int) $filters['limit']));
    }

    public function summaryForClub(Club $club): array
    {
        return $this->treasuryService->summary($club);
    }

    private function paymentMovements(Club $club, array $filters): Collection
    {
        return Payment::query()
            ->where('club_id', $club->id)
            ->with([
                'member:id,type,id_data,parent_id',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept,event_id,event_fee_component_id',
                'concept.event:id,title',
                'account:id,club_id,pay_to,label',
                'receipt:id,payment_id,receipt_number',
                'receivedBy:id,name',
                'heldBy:id,name',
                'custodyValidatedBy:id,name',
                'reversalPayment:id,reversed_payment_id',
            ])
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('payment_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('payment_date', '<=', $filters['date_to']))
            ->get()
            ->map(function (Payment $payment) {
                $amount = (float) $payment->amount_paid;
                $isCustodyHeld = in_array($payment->custody_status, [
                    AttendanceDuesPaymentService::CUSTODY_HELD_BY_STAFF,
                    AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING,
                ], true);
                $member = ClubHelper::memberDetail($payment->member);
                $staff = ClubHelper::staffDetail($payment->staff);
                $conceptName = $payment->concept?->event?->title
                    ?: $payment->concept?->concept
                    ?: $payment->concept_text;
                $cancellation = [
                    'is_cancelled' => (bool) $payment->is_cancelled,
                    'related_canceled_movement_id' => $payment->related_canceled_movement_id,
                    'related_canceled_movement_key' => $payment->related_canceled_movement_id ? "payment:{$payment->related_canceled_movement_id}" : null,
                    'canceling_id' => $payment->canceling_id,
                    'canceling_movement_key' => $payment->canceling_id ? "payment:{$payment->canceling_id}" : null,
                    'reversed_payment_id' => $payment->reversed_payment_id,
                    'reversed_movement_key' => $payment->reversed_payment_id ? "payment:{$payment->reversed_payment_id}" : null,
                ];
                $canReverse = $payment->payment_type !== 'internal'
                    && !$payment->canceling_id
                    && !$payment->reversed_payment_id
                    && !$payment->is_cancelled
                    && !$payment->related_canceled_movement_id
                    && $payment->reversalPayment === null;

                return [
                    'movement_id' => "payment:{$payment->id}",
                    'model' => Payment::class,
                    'id' => (int) $payment->id,
                    'domain' => 'income',
                    'kind' => $amount < 0 ? 'income_reversal' : 'income',
                    'direction' => $amount < 0 ? 'out' : 'in',
                    'date' => optional($payment->payment_date)->toDateString(),
                    'account' => $payment->pay_to ?: 'club_budget',
                    'account_label' => $payment->account?->label,
                    'from_account' => null,
                    'to_account' => $payment->pay_to ?: 'club_budget',
                    'location' => $isCustodyHeld ? 'staff_custody' : $this->treasuryService->paymentLocation($payment->payment_type),
                    'amount' => abs($amount),
                    'signed_amount' => $amount,
                    'concept' => $conceptName,
                    'counterparty' => $member['name'] ?? $staff['name'] ?? $payment->payer_name,
                    'payment_type' => $payment->payment_type,
                    'status' => $this->paymentStatus($payment),
                    'is_counted_in_balance' => !$isCustodyHeld,
                    'receipt' => $payment->receipt ? [
                        'id' => (int) $payment->receipt->id,
                        'number' => $payment->receipt->receipt_number,
                        'url' => route('payment-receipts.download', $payment->receipt),
                    ] : null,
                    'proof' => $payment->check_image_path ? [
                        'type' => 'check_image',
                        'url' => asset('storage/' . $payment->check_image_path),
                    ] : null,
                    'created_by' => $payment->receivedBy?->name,
                    'custody' => [
                        'status' => $payment->custody_status,
                        'held_by' => $payment->heldBy?->name,
                        'validated_by' => $payment->custodyValidatedBy?->name,
                        'remittance_method' => $payment->remittance_method,
                        'remittance_reference' => $payment->remittance_reference,
                        'remitted_at' => optional($payment->remitted_at)->toDateTimeString(),
                        'validated_at' => optional($payment->custody_validated_at)->toDateTimeString(),
                    ],
                    'can_reverse' => $canReverse,
                    'correction_type' => $canReverse ? 'income' : null,
                    'is_cancelled' => $cancellation['is_cancelled'],
                    'related_canceled_movement_id' => $cancellation['related_canceled_movement_id'],
                    'related_canceled_movement_key' => $cancellation['related_canceled_movement_key'],
                    'canceling_id' => $cancellation['canceling_id'],
                    'canceling_movement_key' => $cancellation['canceling_movement_key'],
                    'cancellation' => $cancellation,
                ];
            });
    }

    private function expenseMovements(Club $club, array $filters): Collection
    {
        $accountLabels = $this->accountLabels($club);

        return Expense::query()
            ->where('club_id', $club->id)
            ->with([
                'createdBy:id,name',
                'event:id,title',
                'reimbursementPayee:id,club_id,name,phone,email',
                'reversalExpense:id,reversed_expense_id',
                'settlementExpense:id,settles_expense_id',
            ])
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('expense_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('expense_date', '<=', $filters['date_to']))
            ->get()
            ->map(function (Expense $expense) use ($accountLabels) {
                $amount = (float) $expense->amount;
                $signedAmount = -1 * $amount;
                $payTo = $expense->pay_to ?: 'club_budget';
                $isReimbursementMain = $expense->pay_to === 'reimbursement_to' && !$expense->settles_expense_id;
                $isReimbursementRelated = (bool) $expense->settles_expense_id;
                $cancellation = [
                    'is_cancelled' => (bool) $expense->is_cancelled,
                    'related_canceled_movement_id' => $expense->related_canceled_movement_id,
                    'related_canceled_movement_key' => $expense->related_canceled_movement_id ? "expense:{$expense->related_canceled_movement_id}" : null,
                    'canceling_id' => $expense->canceling_id,
                    'canceling_movement_key' => $expense->canceling_id ? "expense:{$expense->canceling_id}" : null,
                    'reversed_expense_id' => $expense->reversed_expense_id,
                    'reversed_movement_key' => $expense->reversed_expense_id ? "expense:{$expense->reversed_expense_id}" : null,
                ];
                $canReverse = !$expense->canceling_id
                    && !$expense->reversed_expense_id
                    && !$expense->is_cancelled
                    && !$expense->related_canceled_movement_id
                    && $expense->reversalExpense === null
                    && !$isReimbursementRelated;

                return [
                    'movement_id' => "expense:{$expense->id}",
                    'model' => Expense::class,
                    'id' => (int) $expense->id,
                    'domain' => 'expense',
                    'kind' => $amount < 0 ? 'expense_reversal' : 'expense',
                    'direction' => $signedAmount < 0 ? 'out' : 'in',
                    'date' => optional($expense->expense_date)->toDateString(),
                    'account' => $payTo,
                    'account_label' => $accountLabels[$payTo] ?? $payTo,
                    'from_account' => $payTo,
                    'to_account' => null,
                    'location' => $expense->funds_location ?: 'pending',
                    'amount' => abs($amount),
                    'signed_amount' => $signedAmount,
                    'concept' => $expense->event?->title ?: $expense->description,
                    'counterparty' => $expense->reimbursed_to,
                    'payment_type' => null,
                    'status' => $this->expenseStatus($expense),
                    'is_counted_in_balance' => true,
                    'receipt' => null,
                    'proof' => $expense->receipt_url ? [
                        'type' => 'expense_receipt',
                        'url' => $expense->receipt_url,
                    ] : null,
                    'reimbursement_payee' => $expense->reimbursementPayee ? [
                        'id' => (int) $expense->reimbursementPayee->id,
                        'name' => $expense->reimbursementPayee->name,
                        'phone' => $expense->reimbursementPayee->phone,
                        'email' => $expense->reimbursementPayee->email,
                    ] : null,
                    'created_by' => $expense->createdBy?->name,
                    'custody' => null,
                    'can_reverse' => $canReverse,
                    'correction_type' => $canReverse ? ($isReimbursementMain ? 'reimbursement' : 'expense') : null,
                    'is_cancelled' => $cancellation['is_cancelled'],
                    'related_canceled_movement_id' => $cancellation['related_canceled_movement_id'],
                    'related_canceled_movement_key' => $cancellation['related_canceled_movement_key'],
                    'canceling_id' => $cancellation['canceling_id'],
                    'canceling_movement_key' => $cancellation['canceling_movement_key'],
                    'cancellation' => $cancellation,
                ];
            });
    }

    private function treasuryMovements(Club $club, array $filters): Collection
    {
        $accountLabels = $this->accountLabels($club);

        return TreasuryMovement::query()
            ->where('club_id', $club->id)
            ->with(['creator:id,name', 'event:id,title', 'eventClubSettlement:id,receipt_number'])
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('movement_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('movement_date', '<=', $filters['date_to']))
            ->get()
            ->map(function (TreasuryMovement $movement) use ($accountLabels) {
                $fromPayTo = $movement->from_pay_to ?: $movement->pay_to ?: 'club_budget';
                $toPayTo = $movement->to_pay_to ?: $movement->pay_to ?: $fromPayTo;
                $cancellation = [
                    'is_cancelled' => (bool) $movement->is_cancelled,
                    'related_canceled_movement_id' => $movement->related_canceled_movement_id,
                    'related_canceled_movement_key' => $movement->related_canceled_movement_id ? "treasury:{$movement->related_canceled_movement_id}" : null,
                    'canceling_id' => $movement->canceling_id,
                    'canceling_movement_key' => $movement->canceling_id ? "treasury:{$movement->canceling_id}" : null,
                ];

                return [
                    'movement_id' => "treasury:{$movement->id}",
                    'model' => TreasuryMovement::class,
                    'id' => (int) $movement->id,
                    'domain' => 'transfer',
                    'kind' => $movement->movement_type,
                    'direction' => 'transfer',
                    'date' => optional($movement->movement_date)->toDateString(),
                    'account' => $movement->pay_to ?: $fromPayTo,
                    'account_label' => $accountLabels[$movement->pay_to] ?? $movement->pay_to,
                    'from_account' => $fromPayTo,
                    'from_account_label' => $accountLabels[$fromPayTo] ?? $fromPayTo,
                    'to_account' => $toPayTo,
                    'to_account_label' => $accountLabels[$toPayTo] ?? $toPayTo,
                    'from_location' => $movement->from_location,
                    'to_location' => $movement->to_location,
                    'location' => $movement->to_location ?: $movement->from_location,
                    'amount' => abs((float) $movement->amount),
                    'signed_amount' => 0.0,
                    'concept' => $movement->event?->title ?: $movement->reference,
                    'reference' => $movement->reference,
                    'counterparty' => $movement->eventClubSettlement?->receipt_number,
                    'payment_type' => null,
                    'status' => $this->treasuryStatus($movement),
                    'is_counted_in_balance' => true,
                    'receipt' => $movement->eventClubSettlement ? [
                        'number' => $movement->eventClubSettlement->receipt_number,
                    ] : null,
                    'proof' => $movement->proof_path ? [
                        'type' => 'treasury_proof',
                        'url' => asset('storage/' . $movement->proof_path),
                        'name' => $movement->proof_original_name,
                    ] : null,
                    'created_by' => $movement->creator?->name,
                    'custody' => null,
                    'can_reverse' => false,
                    'correction_type' => null,
                    'is_cancelled' => $cancellation['is_cancelled'],
                    'related_canceled_movement_id' => $cancellation['related_canceled_movement_id'],
                    'related_canceled_movement_key' => $cancellation['related_canceled_movement_key'],
                    'canceling_id' => $cancellation['canceling_id'],
                    'canceling_movement_key' => $cancellation['canceling_movement_key'],
                    'cancellation' => $cancellation,
                ];
            });
    }

    private function paymentStatus(Payment $payment): string
    {
        if ($payment->canceling_id || $payment->reversed_payment_id) {
            return 'cancellation';
        }

        if ($payment->is_cancelled || $payment->related_canceled_movement_id) {
            return 'cancelled';
        }

        if ($payment->custody_status) {
            return $payment->custody_status;
        }

        return 'posted';
    }

    private function expenseStatus(Expense $expense): string
    {
        if ($expense->canceling_id || $expense->reversed_expense_id) {
            return 'cancellation';
        }

        if ($expense->is_cancelled || $expense->related_canceled_movement_id) {
            return 'cancelled';
        }

        return $expense->status ?: 'posted';
    }

    private function treasuryStatus(TreasuryMovement $movement): string
    {
        if ($movement->canceling_id) {
            return 'cancellation';
        }

        if ($movement->is_cancelled || $movement->related_canceled_movement_id) {
            return 'cancelled';
        }

        return 'posted';
    }

    private function accountLabels(Club $club): array
    {
        return Account::query()
            ->where('club_id', $club->id)
            ->pluck('label', 'pay_to')
            ->all();
    }
}
