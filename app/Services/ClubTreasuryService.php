<?php

namespace App\Services;

use App\Models\BankInfo;
use App\Models\Club;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\TreasuryMovement;
use Illuminate\Support\Collection;

class ClubTreasuryService
{
    public function clubBankInfo(Club $club): ?BankInfo
    {
        return BankInfo::query()
            ->where('bankable_type', Club::class)
            ->where('bankable_id', $club->id)
            ->where('pay_to', 'club_budget')
            ->where('is_active', true)
            ->first();
    }

    public function hasClubBankInfo(Club $club): bool
    {
        return (bool) $this->clubBankInfo($club);
    }

    public function electronicPaymentTypes(): array
    {
        return ['zelle', 'transfer', 'check'];
    }

    public function paymentLocation(?string $paymentType): string
    {
        if ($paymentType === 'internal') {
            return 'internal';
        }

        return in_array($paymentType, $this->electronicPaymentTypes(), true)
            ? TreasuryMovement::LOCATION_BANK
            : TreasuryMovement::LOCATION_CASH;
    }

    public function summary(Club $club): array
    {
        $accountBalances = $this->locationBalancesByAccount($club);

        $totals = [
            'cash_income' => round($accountBalances->sum('cash_income'), 2),
            'bank_income' => round($accountBalances->sum('bank_income'), 2),
            'cash_expenses' => round($accountBalances->sum('cash_expenses'), 2),
            'bank_expenses' => round($accountBalances->sum('bank_expenses'), 2),
            'cash_deposits' => round($accountBalances->sum('cash_deposits'), 2),
            'cash_withdrawals' => round($accountBalances->sum('cash_withdrawals'), 2),
            'event_settlements' => round($accountBalances->sum('event_settlements'), 2),
            'transfer_in_total' => round($accountBalances->sum('transfer_in_total'), 2),
            'transfer_out_total' => round($accountBalances->sum('transfer_out_total'), 2),
            'cash_balance' => round($accountBalances->sum('cash_balance'), 2),
            'bank_balance' => round($accountBalances->sum('bank_balance'), 2),
            'total_available' => round($accountBalances->sum('total_available'), 2),
        ];

        return [
            'cash_income' => $totals['cash_income'],
            'bank_income' => $totals['bank_income'],
            'cash_expenses' => $totals['cash_expenses'],
            'bank_expenses' => $totals['bank_expenses'],
            'cash_deposits' => $totals['cash_deposits'],
            'cash_withdrawals' => $totals['cash_withdrawals'],
            'event_settlements' => $totals['event_settlements'],
            'transfer_in_total' => $totals['transfer_in_total'],
            'transfer_out_total' => $totals['transfer_out_total'],
            'cash_balance' => $totals['cash_balance'],
            'bank_balance' => $totals['bank_balance'],
            'total_available' => $totals['total_available'],
            'accounts' => $accountBalances->values()->all(),
        ];
    }

    public function locationBalancesByAccount(Club $club): Collection
    {
        $payments = Payment::query()
            ->where('club_id', $club->id)
            ->selectRaw("COALESCE(pay_to, 'unassigned') as pay_to, COALESCE(payment_type, 'cash') as payment_type, COALESCE(SUM(amount_paid), 0) as total")
            ->groupBy('pay_to', 'payment_type')
            ->get()
            ->groupBy('pay_to');

        $movements = TreasuryMovement::query()
            ->where('club_id', $club->id)
            ->get([
                'pay_to',
                'from_pay_to',
                'to_pay_to',
                'movement_type',
                'from_location',
                'to_location',
                'amount',
            ]);

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->where('pay_to', '!=', 'reimbursement_to')
            ->selectRaw("COALESCE(pay_to, 'unassigned') as pay_to, COALESCE(funds_location, 'cash') as funds_location, COALESCE(SUM(amount), 0) as total")
            ->groupBy('pay_to', 'funds_location')
            ->get()
            ->groupBy('pay_to');

        $configuredAccounts = Account::query()
            ->where('club_id', $club->id)
            ->pluck('pay_to');

        $accountKeys = $payments->keys()
            ->merge($movements->pluck('pay_to'))
            ->merge($movements->pluck('from_pay_to'))
            ->merge($movements->pluck('to_pay_to'))
            ->merge($expenses->keys())
            ->merge($configuredAccounts)
            ->merge(['club_budget'])
            ->filter()
            ->unique()
            ->values();

        return $accountKeys->map(function (string $payTo) use ($payments, $movements, $expenses) {
            $paymentRows = collect($payments->get($payTo, []))->keyBy('payment_type');
            $accountMovements = $movements->filter(function (TreasuryMovement $movement) use ($payTo) {
                $legacyPayTo = $movement->pay_to ?: 'club_budget';
                $fromPayTo = $movement->from_pay_to ?: $legacyPayTo;
                $toPayTo = $movement->to_pay_to;

                return $legacyPayTo === $payTo || $fromPayTo === $payTo || $toPayTo === $payTo;
            });
            $expenseRows = collect($expenses->get($payTo, []))->keyBy('funds_location');

            $cashIncome = round((float) ($paymentRows->get('cash')?->total ?? 0) + (float) ($paymentRows->get('initial')?->total ?? 0), 2);
            $bankIncome = round(collect($this->electronicPaymentTypes())->sum(fn (string $type) => (float) ($paymentRows->get($type)?->total ?? 0)), 2);
            $cashExpenses = round((float) ($expenseRows->get(TreasuryMovement::LOCATION_CASH)?->total ?? 0), 2);
            $bankExpenses = round((float) ($expenseRows->get(TreasuryMovement::LOCATION_BANK)?->total ?? 0), 2);
            $cashDeposits = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_CASH_DEPOSIT)
                ->filter(fn (TreasuryMovement $movement) => ($movement->pay_to ?: 'club_budget') === $payTo)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $cashWithdrawals = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_CASH_WITHDRAWAL)
                ->filter(fn (TreasuryMovement $movement) => ($movement->pay_to ?: 'club_budget') === $payTo)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $eventSettlements = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_EVENT_SETTLEMENT)
                ->filter(fn (TreasuryMovement $movement) => ($movement->pay_to ?: 'club_budget') === $payTo)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $transferOutCash = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_ACCOUNT_TRANSFER)
                ->filter(fn (TreasuryMovement $movement) => ($movement->from_pay_to ?: $movement->pay_to ?: 'club_budget') === $payTo)
                ->filter(fn (TreasuryMovement $movement) => $movement->from_location === TreasuryMovement::LOCATION_CASH)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $transferInCash = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_ACCOUNT_TRANSFER)
                ->filter(fn (TreasuryMovement $movement) => $movement->to_pay_to === $payTo)
                ->filter(fn (TreasuryMovement $movement) => $movement->to_location === TreasuryMovement::LOCATION_CASH)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $transferOutBank = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_ACCOUNT_TRANSFER)
                ->filter(fn (TreasuryMovement $movement) => ($movement->from_pay_to ?: $movement->pay_to ?: 'club_budget') === $payTo)
                ->filter(fn (TreasuryMovement $movement) => $movement->from_location === TreasuryMovement::LOCATION_BANK)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $transferInBank = round((float) $accountMovements
                ->where('movement_type', TreasuryMovement::TYPE_ACCOUNT_TRANSFER)
                ->filter(fn (TreasuryMovement $movement) => $movement->to_pay_to === $payTo)
                ->filter(fn (TreasuryMovement $movement) => $movement->to_location === TreasuryMovement::LOCATION_BANK)
                ->sum(fn (TreasuryMovement $movement) => (float) $movement->amount), 2);
            $cashBalance = round($cashIncome + $cashWithdrawals + $transferInCash - $cashDeposits - $cashExpenses - $transferOutCash, 2);
            $bankBalance = round($bankIncome + $cashDeposits + $transferInBank - $cashWithdrawals - $eventSettlements - $bankExpenses - $transferOutBank, 2);

            return [
                'account' => $payTo,
                'cash_income' => $cashIncome,
                'bank_income' => $bankIncome,
                'cash_expenses' => $cashExpenses,
                'bank_expenses' => $bankExpenses,
                'cash_deposits' => $cashDeposits,
                'cash_withdrawals' => $cashWithdrawals,
                'event_settlements' => $eventSettlements,
                'transfer_in_cash' => $transferInCash,
                'transfer_out_cash' => $transferOutCash,
                'transfer_in_bank' => $transferInBank,
                'transfer_out_bank' => $transferOutBank,
                'transfer_in_total' => round($transferInCash + $transferInBank, 2),
                'transfer_out_total' => round($transferOutCash + $transferOutBank, 2),
                'cash_balance' => max($cashBalance, 0),
                'bank_balance' => max($bankBalance, 0),
                'total_available' => max(round($cashBalance + $bankBalance, 2), 0),
            ];
        });
    }

    public function incomeRows(Club $club, int $limit = 100): Collection
    {
        $accountLabels = Account::query()
            ->where('club_id', $club->id)
            ->pluck('label', 'pay_to');

        return Payment::query()
            ->where('club_id', $club->id)
            ->with([
                'member:id,type,id_data,parent_id',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept,event_id,event_fee_component_id',
                'concept.event:id,title',
                'allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
                'allocations.concept:id,concept,event_id,event_fee_component_id',
                'allocations.concept.event:id,title',
                'receivedBy:id,name',
            ])
            ->latest('payment_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (Payment $payment) use ($accountLabels) {
                $member = \App\Support\ClubHelper::memberDetail($payment->member);
                $staff = \App\Support\ClubHelper::staffDetail($payment->staff);
                $eventTitle = $payment->concept?->event?->title
                    ?: $payment->allocations?->first()?->concept?->event?->title;
                $payTo = $payment->pay_to ?: 'unassigned';

                return [
                    'id' => (int) $payment->id,
                    'payment_date' => optional($payment->payment_date)->toDateString(),
                    'payment_type' => $payment->payment_type,
                    'pay_to' => $payTo,
                    'account_label' => $accountLabels[$payTo] ?? ($payTo === 'unassigned' ? 'Cuenta sin asignar' : $payTo),
                    'location' => $this->paymentLocation($payment->payment_type),
                    'amount_paid' => (float) $payment->amount_paid,
                    'concept_name' => $eventTitle ?: $payment->concept?->concept ?: $payment->concept_text,
                    'event_title' => $eventTitle,
                    'payer_name' => $member['name'] ?? $staff['name'] ?? '—',
                    'received_by' => $payment->receivedBy?->name,
                ];
            });
    }
}
