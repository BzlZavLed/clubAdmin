<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\Expense;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Models\User;
use App\Services\ClubTreasuryService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceExpenseWriter
{
    public function __construct(private readonly ClubTreasuryService $treasuryService)
    {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'pay_to' => ['required', 'string', 'max:255'],
            'funds_location' => ['nullable', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $clubId = (int) $validated['club_id'];
        if (!ClubHelper::clubIdsForUser($request->user())->contains($clubId)) {
            return response()->json(['message' => 'Unauthorized club selection.'], 403);
        }

        if ($validated['pay_to'] === 'reimbursement_to') {
            return response()->json(['message' => 'Los reembolsos se generan automaticamente.'], 422);
        }

        $club = Club::query()->findOrFail($clubId);
        $expense = null;
        $splitExpense = null;

        DB::transaction(function () use ($club, $validated, $request, &$expense, &$splitExpense) {
            $account = $this->resolveAccount($club->id, $validated['pay_to']);
            $amount = (float) $validated['amount'];
            $fundsLocation = $validated['funds_location'] ?? 'cash';
            $available = $this->locationBalanceFor($club, $validated['pay_to'], $fundsLocation);
            $fromAccount = $amount;
            $shortfall = 0.0;
            $reimbursementConcept = null;
            $reimburseTo = null;

            if ($amount > $available) {
                $fromAccount = $available;
                $shortfall = max($amount - $available, 0.0);
                [$reimbursementConcept, $reimburseTo] = $this->reimbursementConceptFor($club, $request);
            }

            $receiptPath = $request->hasFile('receipt_image')
                ? $request->file('receipt_image')->store('expense-receipts', 'public')
                : null;

            if ($fromAccount > 0) {
                $expense = Expense::query()->create([
                    'club_id' => $club->id,
                    'pay_to' => $validated['pay_to'],
                    'funds_location' => $fundsLocation,
                    'payment_concept_id' => null,
                    'payee_id' => null,
                    'amount' => $fromAccount,
                    'expense_date' => $validated['expense_date'],
                    'description' => $validated['description'] ?? null,
                    'reimbursed_to' => null,
                    'created_by_user_id' => $request->user()->id,
                    'status' => $receiptPath ? 'completed' : 'working',
                    'receipt_path' => $receiptPath,
                ]);

                $account->decrement('balance', $fromAccount);
            }

            if ($shortfall > 0 && $reimbursementConcept) {
                $reimbursementAccount = $this->resolveAccount($club->id, 'reimbursement_to');

                $splitExpense = Expense::query()->create([
                    'club_id' => $club->id,
                    'pay_to' => 'reimbursement_to',
                    'funds_location' => null,
                    'payment_concept_id' => $reimbursementConcept->id,
                    'payee_id' => $reimbursementConcept->payee_id,
                    'amount' => $shortfall,
                    'expense_date' => $validated['expense_date'],
                    'description' => 'Reembolso pendiente por gasto con saldo insuficiente.',
                    'reimbursed_to' => $reimburseTo,
                    'created_by_user_id' => $request->user()->id,
                    'status' => 'pending_reimbursement',
                    'receipt_path' => null,
                ]);

                $reimbursementAccount->decrement('balance', $shortfall);
            }
        });

        return response()->json([
            'message' => 'Expense recorded',
            'data' => [
                'expense' => $expense,
                'split_expense' => $splitExpense,
            ],
        ], 201);
    }

    private function reimbursementConceptFor(Club $club, Request $request): array
    {
        $staff = Staff::query()
            ->where('user_id', $request->user()->id)
            ->where('club_id', $club->id)
            ->first();

        if ($staff) {
            $reimburseTo = ClubHelper::staffDetail($staff)['name'] ?? $request->user()->name;
            $concept = PaymentConcept::query()->firstOrCreate(
                [
                    'club_id' => $club->id,
                    'pay_to' => 'reimbursement_to',
                    'payee_type' => Staff::class,
                    'payee_id' => $staff->id,
                ],
                [
                    'concept' => 'Reembolso a ' . ($reimburseTo ?? 'Personal'),
                    'payment_expected_by' => null,
                    'type' => 'optional',
                    'status' => 'active',
                    'amount' => 0,
                    'created_by' => $request->user()->id,
                ]
            );

            return [$concept, $reimburseTo];
        }

        $reimburseTo = $request->user()->name ?? 'Director';
        $concept = PaymentConcept::query()->firstOrCreate(
            [
                'club_id' => $club->id,
                'pay_to' => 'reimbursement_to',
                'payee_type' => User::class,
                'payee_id' => $request->user()->id,
            ],
            [
                'concept' => 'Reembolso a ' . ($reimburseTo ?? 'Director'),
                'payment_expected_by' => null,
                'type' => 'optional',
                'status' => 'active',
                'amount' => 0,
                'created_by' => $request->user()->id,
            ]
        );

        return [$concept, $reimburseTo];
    }

    private function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }

    private function locationBalanceFor(Club $club, string $payTo, string $fundsLocation): float
    {
        $row = $this->treasuryService
            ->locationBalancesByAccount($club)
            ->firstWhere('account', $payTo);

        return max((float) ($row[$fundsLocation . '_balance'] ?? 0), 0.0);
    }
}
