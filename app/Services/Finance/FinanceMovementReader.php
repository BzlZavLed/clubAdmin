<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FinanceMovementConceptOverride;
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
            ->merge($this->paymentMovements($club, []))
            ->merge($this->expenseMovements($club, []))
            ->merge($this->treasuryMovements($club, []));

        $movements = $this->withConceptOverrides($club, $movements);
        $movements = $this->withRunningBalances($movements);

        if (!empty($filters['date_from'])) {
            $movements = $movements->filter(fn (array $row) => ($row['date'] ?? null) >= $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $movements = $movements->filter(fn (array $row) => ($row['date'] ?? null) <= $filters['date_to']);
        }

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

        if (!empty($filters['search'])) {
            $movements = $this->filterBySearch($movements, (string) $filters['search']);
        }

        return $movements
            ->sortByDesc(fn (array $row) => sprintf('%s-%010d', $row['occurred_at'] ?? $row['date'] ?? '0000-00-00 00:00:00', $row['id'] ?? 0))
            ->values()
            ->when(!empty($filters['limit']), fn (Collection $rows) => $rows->take((int) $filters['limit']));
    }

    private function filterBySearch(Collection $movements, string $search): Collection
    {
        $query = mb_strtolower(trim($search));

        if ($query === '') {
            return $movements;
        }

        return $movements->filter(fn (array $movement) => collect([
            $movement['movement_id'] ?? null,
            $movement['id'] ?? null,
            $movement['reference'] ?? null,
            $movement['display_concept'] ?? null,
            $movement['concept'] ?? null,
            $movement['original_concept'] ?? null,
            $movement['notes'] ?? null,
            $movement['counterparty'] ?? null,
            $movement['created_by'] ?? null,
            $movement['account'] ?? null,
            $movement['account_label'] ?? null,
            $movement['from_account'] ?? null,
            $movement['from_account_label'] ?? null,
            $movement['to_account'] ?? null,
            $movement['to_account_label'] ?? null,
            $movement['location'] ?? null,
            $movement['from_location'] ?? null,
            $movement['to_location'] ?? null,
            $movement['payment_type'] ?? null,
            $movement['status'] ?? null,
            $movement['kind'] ?? null,
            $movement['receipt']['number'] ?? null,
            ...$this->movementProofSearchValues($movement),
        ])->filter(fn ($value) => $value !== null && $value !== '')
            ->contains(fn ($value) => str_contains(mb_strtolower((string) $value), $query)));
    }

    private function movementProofSearchValues(array $movement): array
    {
        if (!isset($movement['proofs']) || !is_array($movement['proofs'])) {
            return [];
        }

        return collect($movement['proofs'])
            ->flatMap(fn ($proof) => [
                $proof['name'] ?? null,
                $proof['type'] ?? null,
                $proof['path'] ?? null,
                $proof['url'] ?? null,
            ])
            ->all();
    }

    private function withRunningBalances(Collection $movements): Collection
    {
        $balances = [];
        $annotated = [];

        $movements
            ->sortBy(fn (array $row) => sprintf(
                '%s-%010d-%s',
                $row['occurred_at'] ?? $row['date'] ?? '0000-00-00 00:00:00',
                $row['id'] ?? 0,
                $row['movement_id'] ?? ''
            ))
            ->each(function (array $row) use (&$balances, &$annotated) {
                $row['balance_after'] = $this->runningBalanceAfterMovement($row, $balances);
                $annotated[$row['movement_id'] ?? spl_object_id((object) $row)] = $row;
            });

        return $movements->map(fn (array $row) => $annotated[$row['movement_id'] ?? ''] ?? $row);
    }

    private function runningBalanceAfterMovement(array $movement, array &$balances): ?array
    {
        if (array_key_exists('is_counted_in_balance', $movement) && !$movement['is_counted_in_balance']) {
            return null;
        }

        if (($movement['domain'] ?? null) === 'transfer') {
            $amount = round((float) ($movement['amount'] ?? 0), 2);
            $fromAccount = $movement['from_account'] ?? $movement['account'] ?? 'club_budget';
            $toAccount = $movement['to_account'] ?? $movement['account'] ?? $fromAccount;
            $fromLocation = $this->balanceLocation($movement['from_location'] ?? null);
            $toLocation = $this->balanceLocation($movement['to_location'] ?? null);

            if ($fromLocation) {
                $this->applyBalanceDelta($balances, $fromAccount, $fromLocation, -1 * $amount);
            } else {
                $this->ensureBalanceAccount($balances, $fromAccount);
            }

            if ($toLocation) {
                $this->applyBalanceDelta($balances, $toAccount, $toLocation, $amount);
            } else {
                $this->ensureBalanceAccount($balances, $toAccount);
            }

            $from = $this->balanceSnapshot(
                $balances,
                $fromAccount,
                $fromLocation,
                $movement['from_account_label'] ?? $movement['account_label'] ?? null
            );
            $to = $this->balanceSnapshot(
                $balances,
                $toAccount,
                $toLocation,
                $movement['to_account_label'] ?? $movement['account_label'] ?? null
            );

            return [
                'from' => $from,
                'to' => $to,
                'account_balance' => $fromAccount === $toAccount ? $from['account_balance'] : null,
            ];
        }

        $account = $movement['account'] ?? $movement['to_account'] ?? $movement['from_account'] ?? 'club_budget';
        $location = $this->balanceLocation($movement['location'] ?? $movement['from_location'] ?? null);

        if (!$location) {
            $this->ensureBalanceAccount($balances, $account);

            return null;
        }

        $this->applyBalanceDelta($balances, $account, $location, round((float) ($movement['signed_amount'] ?? 0), 2));

        return $this->balanceSnapshot($balances, $account, $location, $movement['account_label'] ?? null);
    }

    private function balanceLocation(?string $location): ?string
    {
        return in_array($location, [TreasuryMovement::LOCATION_CASH, TreasuryMovement::LOCATION_BANK], true)
            ? $location
            : null;
    }

    private function applyBalanceDelta(array &$balances, string $account, string $location, float $delta): void
    {
        $this->ensureBalanceAccount($balances, $account);
        $balances[$account][$location] = round((float) $balances[$account][$location] + $delta, 2);
    }

    private function ensureBalanceAccount(array &$balances, string $account): void
    {
        if (!isset($balances[$account])) {
            $balances[$account] = [
                TreasuryMovement::LOCATION_CASH => 0.0,
                TreasuryMovement::LOCATION_BANK => 0.0,
            ];
        }
    }

    private function balanceSnapshot(array &$balances, string $account, ?string $location, ?string $label = null): array
    {
        $this->ensureBalanceAccount($balances, $account);
        $cash = round((float) $balances[$account][TreasuryMovement::LOCATION_CASH], 2);
        $bank = round((float) $balances[$account][TreasuryMovement::LOCATION_BANK], 2);

        return [
            'account' => $account,
            'account_label' => $label,
            'location' => $location,
            'location_balance' => $location ? round((float) $balances[$account][$location], 2) : null,
            'cash_balance' => $cash,
            'bank_balance' => $bank,
            'account_balance' => round($cash + $bank, 2),
        ];
    }

    public function summaryForClub(Club $club): array
    {
        return $this->treasuryService->summary($club);
    }

    private function withConceptOverrides(Club $club, Collection $movements): Collection
    {
        $overrides = FinanceMovementConceptOverride::query()
            ->where('club_id', $club->id)
            ->get()
            ->keyBy(fn (FinanceMovementConceptOverride $override) => "{$override->movement_type}:{$override->movement_id}");

        return $movements->map(function (array $row) use ($overrides) {
            $row['original_concept'] = $row['concept'] ?? null;
            $row['display_concept'] = $row['concept'] ?? null;
            $row['concept_override'] = null;

            $movementId = (string) ($row['movement_id'] ?? '');
            $override = $overrides->get($movementId);
            if (!$override) {
                return $row;
            }

            $row['display_concept'] = $override->display_concept;
            $row['concept_override'] = [
                'id' => (int) $override->id,
                'display_concept' => $override->display_concept,
                'original_concept' => $override->original_concept,
                'updated_by_user_id' => $override->updated_by_user_id ? (int) $override->updated_by_user_id : null,
                'updated_at' => $override->updated_at?->toIso8601String(),
            ];

            return $row;
        });
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
                'receipt:id,payment_id,receipt_number,issued_at,issued_to_email,issued_to_type',
                'receivedBy:id,name',
                'heldBy:id,name',
                'custodyValidatedBy:id,name',
                'reversedPayment:id,payment_type,custody_status',
                'reversalPayment:id,reversed_payment_id',
                'settledExpense:id,pay_to,amount,expense_date,description,reimbursed_to,reimbursement_payee_id,status,reimbursement_origin_expense_id',
                'settledExpense.reimbursementPayee:id,club_id,name,phone,email',
                'settledExpense.reimbursementOriginExpense:id,pay_to,amount,expense_date,description',
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
                $isCancellationPayment = $payment->reversed_payment_id && $payment->canceling_id;
                $balancePaymentType = $isCancellationPayment && $payment->reversedPayment
                    ? $payment->reversedPayment->payment_type
                    : $payment->payment_type;
                $isCountedInBalance = $isCancellationPayment && $payment->reversedPayment
                    ? !in_array($payment->reversedPayment->custody_status, [
                        AttendanceDuesPaymentService::CUSTODY_HELD_BY_STAFF,
                        AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING,
                    ], true)
                    : !$isCustodyHeld;

                return [
                    'movement_id' => "payment:{$payment->id}",
                    'model' => Payment::class,
                    'id' => (int) $payment->id,
                    'domain' => 'income',
                    'kind' => $amount < 0 ? 'income_reversal' : 'income',
                    'direction' => $amount < 0 ? 'out' : 'in',
                    'date' => optional($payment->payment_date)->toDateString(),
                    'occurred_at' => $this->movementOccurredAt($payment->payment_date, $payment->created_at),
                    'created_at' => $payment->created_at?->toIso8601String(),
                    'account' => $payment->pay_to ?: 'club_budget',
                    'account_label' => $payment->account?->label,
                    'from_account' => null,
                    'to_account' => $payment->pay_to ?: 'club_budget',
                    'location' => $isCountedInBalance ? $this->treasuryService->paymentLocation($balancePaymentType) : 'staff_custody',
                    'amount' => abs($amount),
                    'signed_amount' => $amount,
                    'concept' => $conceptName,
                    'notes' => $payment->notes,
                    'counterparty' => $member['name'] ?? $staff['name'] ?? $payment->payer_name,
                    'payment_type' => $payment->payment_type,
                    'balance_payment_type' => $balancePaymentType,
                    'source_type' => $payment->source_type,
                    'source_id' => $payment->source_id,
                    'source_line_id' => $payment->source_line_id,
                    'settles_expense_id' => $payment->settles_expense_id,
                    'status' => $this->paymentStatus($payment),
                    'is_counted_in_balance' => $isCountedInBalance,
                    'receipt' => $payment->receipt ? [
                        'id' => (int) $payment->receipt->id,
                        'type' => 'payment_receipt',
                        'number' => $payment->receipt->receipt_number,
                        'issued_at' => optional($payment->receipt->issued_at)->toDateTimeString(),
                        'issued_to_email' => $payment->receipt->issued_to_email,
                        'issued_to_type' => $payment->receipt->issued_to_type,
                        'url' => route('payment-receipts.download', $payment->receipt),
                    ] : null,
                    'proof' => $payment->check_image_path ? [
                        'type' => 'check_image',
                        'url' => asset('storage/' . $payment->check_image_path),
                        'path' => $payment->check_image_path,
                    ] : null,
                    'created_by' => $payment->receivedBy?->name,
                    'reimbursement_group' => $this->reimbursementGroupForPayment($payment),
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
                'settledReimbursement:id,pay_to,amount,description,reimbursed_to,reimbursement_payee_id,reimbursement_origin_expense_id',
                'settledReimbursement.reimbursementPayee:id,club_id,name,phone,email',
                'settledReimbursement.reimbursementOriginExpense:id,pay_to,amount,expense_date,description',
                'reimbursementOriginExpense:id,pay_to,amount,expense_date,description',
                'generatedReimbursementExpense:id,pay_to,amount,status,reimbursed_to,reimbursement_payee_id,reimbursement_origin_expense_id',
                'generatedReimbursementExpense.reimbursementPayee:id,club_id,name,phone,email',
                'fundraiserInvestmentReceipts:id,expense_id,path,original_name,mime_type',
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
                $proofs = [];
                $addProof = function (?string $type, ?string $url, ?string $name = null, ?string $path = null) use (&$proofs): void {
                    if (!$type && !$url && !$name && !$path) {
                        return;
                    }

                    $dedupeKey = $path ?: $url;
                    if ($dedupeKey) {
                        foreach ($proofs as $proof) {
                            if (($proof['path'] ?? $proof['url'] ?? null) === $dedupeKey) {
                                return;
                            }
                        }
                    }

                    $proof = [
                        'type' => $type,
                        'url' => $url,
                        'name' => $name,
                        'path' => $path,
                    ];

                    $proofs[] = array_filter($proof, fn ($value) => $value !== null && $value !== '');
                };

                $addProof('expense_receipt', $expense->receipt_url, null, $expense->receipt_path);
                $addProof('reimbursement_payment_proof', $expense->reimbursement_payment_proof_url, null, $expense->reimbursement_payment_proof_path);
                $addProof('reimbursement_receipt', $expense->reimbursement_receipt_url, null, $expense->reimbursement_receipt_path);
                foreach ($expense->fundraiserInvestmentReceipts as $receipt) {
                    $addProof('fundraiser_investment_receipt', $receipt->url, $receipt->original_name, $receipt->path);
                }

                return [
                    'movement_id' => "expense:{$expense->id}",
                    'model' => Expense::class,
                    'id' => (int) $expense->id,
                    'domain' => 'expense',
                    'kind' => $amount < 0 ? 'expense_reversal' : 'expense',
                    'direction' => $signedAmount < 0 ? 'out' : 'in',
                    'date' => optional($expense->expense_date)->toDateString(),
                    'occurred_at' => $this->movementOccurredAt($expense->expense_date, $expense->created_at),
                    'created_at' => $expense->created_at?->toIso8601String(),
                    'account' => $payTo,
                    'account_label' => $accountLabels[$payTo] ?? $payTo,
                    'from_account' => $payTo,
                    'to_account' => null,
                    'location' => $expense->funds_location ?: 'pending',
                    'amount' => abs($amount),
                    'signed_amount' => $signedAmount,
                    'concept' => $expense->event?->title ?: $expense->description,
                    'notes' => $expense->notes,
                    'counterparty' => $expense->reimbursed_to,
                    'payment_type' => null,
                    'status' => $this->expenseStatus($expense),
                    'is_counted_in_balance' => true,
                    'settles_expense_id' => $expense->settles_expense_id,
                    'reimbursement_origin_expense_id' => $expense->reimbursement_origin_expense_id,
                    'receipt' => null,
                    'proof' => $proofs[0] ?? null,
                    'proofs' => $proofs,
                    'reimbursement_payee' => $expense->reimbursementPayee ? [
                        'id' => (int) $expense->reimbursementPayee->id,
                        'name' => $expense->reimbursementPayee->name,
                        'phone' => $expense->reimbursementPayee->phone,
                        'email' => $expense->reimbursementPayee->email,
                    ] : null,
                    'created_by' => $expense->createdBy?->name,
                    'reimbursement_group' => $this->reimbursementGroupForExpense($expense),
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

    private function reimbursementGroupForPayment(Payment $payment): ?array
    {
        $reimbursement = $payment->settledExpense;

        if (!$reimbursement || $reimbursement->pay_to !== 'reimbursement_to') {
            return null;
        }

        return $this->reimbursementGroupPayload(
            $reimbursement,
            $reimbursement->reimbursementOriginExpense,
            'settlement_credit'
        );
    }

    private function reimbursementGroupForExpense(Expense $expense): ?array
    {
        if ($expense->settles_expense_id) {
            $reimbursement = $expense->settledReimbursement;

            if (!$reimbursement || $reimbursement->pay_to !== 'reimbursement_to') {
                return null;
            }

            return $this->reimbursementGroupPayload(
                $reimbursement,
                $reimbursement->reimbursementOriginExpense,
                'settlement_expense'
            );
        }

        if ($expense->pay_to === 'reimbursement_to') {
            return $this->reimbursementGroupPayload(
                $expense,
                $expense->reimbursementOriginExpense,
                'pending_reimbursement'
            );
        }

        $reimbursement = $expense->generatedReimbursementExpense;
        if (!$reimbursement || $reimbursement->pay_to !== 'reimbursement_to') {
            return null;
        }

        return $this->reimbursementGroupPayload($reimbursement, $expense, 'origin_expense');
    }

    private function reimbursementGroupPayload(?Expense $reimbursement, ?Expense $origin, string $role): ?array
    {
        if (!$reimbursement && !$origin) {
            return null;
        }

        $originId = $origin ? (int) $origin->id : null;
        $reimbursementId = $reimbursement ? (int) $reimbursement->id : null;
        $payee = $reimbursement?->reimbursementPayee?->name ?: $reimbursement?->reimbursed_to;
        $label = $origin?->description ?: $reimbursement?->description ?: 'Reembolso';

        return [
            'key' => 'reimbursement:' . ($originId ?: $reimbursementId),
            'role' => $role,
            'label' => $label,
            'origin_expense_id' => $originId,
            'origin_movement_id' => $originId ? "expense:{$originId}" : null,
            'origin_description' => $origin?->description,
            'origin_amount' => $origin ? (float) $origin->amount : null,
            'origin_date' => $this->expenseDateString($origin),
            'reimbursement_expense_id' => $reimbursementId,
            'reimbursement_movement_id' => $reimbursementId ? "expense:{$reimbursementId}" : null,
            'reimbursement_amount' => $reimbursement ? (float) $reimbursement->amount : null,
            'reimbursement_status' => $reimbursement?->status,
            'reimbursed_to' => $payee,
        ];
    }

    private function expenseDateString(?Expense $expense): ?string
    {
        if (!$expense?->expense_date) {
            return null;
        }

        return method_exists($expense->expense_date, 'toDateString')
            ? $expense->expense_date->toDateString()
            : substr((string) $expense->expense_date, 0, 10);
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
                    'occurred_at' => $this->movementOccurredAt($movement->movement_date, $movement->created_at),
                    'created_at' => $movement->created_at?->toIso8601String(),
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
                    'notes' => $movement->notes,
                    'reference' => $movement->reference,
                    'counterparty' => $movement->eventClubSettlement?->receipt_number,
                    'payment_type' => null,
                    'status' => $this->treasuryStatus($movement),
                    'is_counted_in_balance' => true,
                    'receipt' => $movement->eventClubSettlement ? [
                        'number' => $movement->eventClubSettlement->receipt_number,
                        'url' => route('event-club-settlements.download', $movement->eventClubSettlement),
                    ] : null,
                    'proof' => $movement->proof_path ? [
                        'type' => 'treasury_proof',
                        'url' => asset('storage/' . $movement->proof_path),
                        'name' => $movement->proof_original_name,
                        'path' => $movement->proof_path,
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

    private function movementOccurredAt($movementDate, $createdAt): ?string
    {
        $date = $movementDate
            ? (method_exists($movementDate, 'toDateString') ? $movementDate->toDateString() : substr((string) $movementDate, 0, 10))
            : null;

        if (!$date) {
            return $createdAt && method_exists($createdAt, 'toIso8601String')
                ? $createdAt->toIso8601String()
                : null;
        }

        if (!$createdAt || !method_exists($createdAt, 'copy')) {
            return "{$date}T00:00:00+00:00";
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return $createdAt->copy()
            ->setDate($year, $month, $day)
            ->toIso8601String();
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
