<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\PaymentReceiptService;
use App\Support\ClubHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceCorrectionWriter
{
    public function __construct(private readonly PaymentReceiptService $paymentReceiptService)
    {
    }

    public function reversePayment(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensurePaymentBelongsToUser($user, $payment);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->payment_type === 'internal') {
            return response()->json(['message' => 'Los movimientos internos no se corrigen desde este modulo.'], 422);
        }

        if ($payment->reversed_payment_id || $payment->canceling_id) {
            return response()->json(['message' => 'Este movimiento ya es una reversa y no puede revertirse de nuevo.'], 422);
        }

        if ($payment->is_cancelled || $payment->related_canceled_movement_id || $payment->reversalPayment()->exists()) {
            return response()->json(['message' => 'Este ingreso ya fue revertido previamente.'], 422);
        }

        $account = $payment->account ?: $this->resolveAccount($payment->club_id, $payment->pay_to ?: 'club_budget');
        $amount = abs((float) $payment->amount_paid);
        $reversal = null;

        DB::transaction(function () use ($payment, $validated, $user, $account, $amount, &$reversal) {
            $reversal = Payment::query()->create([
                'club_id' => $payment->club_id,
                'payment_concept_id' => $payment->payment_concept_id,
                'concept_text' => $payment->concept_text ?: 'Correccion contable de ingreso',
                'pay_to' => $payment->pay_to,
                'account_id' => $account->id,
                'member_id' => $payment->member_id,
                'staff_id' => $payment->staff_id,
                'payer_name' => $payment->payer_name,
                'amount_paid' => -$amount,
                'expected_amount' => null,
                'balance_due_after' => null,
                'payment_date' => $validated['correction_date'],
                'payment_type' => 'internal',
                'zelle_phone' => null,
                'check_image_path' => null,
                'received_by_user_id' => $user->id,
                'notes' => trim("Correccion contable. Reversa del ingreso #{$payment->id}. Motivo: {$validated['reason']}"),
                'reversed_payment_id' => $payment->id,
                'canceling_id' => $payment->id,
            ]);

            $this->paymentReceiptService->syncForPayment($reversal);

            $payment->update([
                'is_cancelled' => true,
                'related_canceled_movement_id' => $reversal->id,
            ]);

            $account->decrement('balance', $amount);
        });

        return response()->json([
            'message' => 'Ingreso revertido mediante movimiento opuesto.',
            'data' => ['reversal_id' => $reversal->id],
        ], 201);
    }

    public function reverseExpense(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensureExpenseBelongsToUser($user, $expense);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($expense->reversed_expense_id || $expense->canceling_id) {
            return response()->json(['message' => 'Este movimiento ya es una reversa y no puede revertirse de nuevo.'], 422);
        }

        if ($expense->settles_expense_id || $expense->settlementExpense()->exists()) {
            return response()->json(['message' => 'Los movimientos ligados a reembolsos se corrigen desde su flujo de reembolso.'], 422);
        }

        if ($expense->is_cancelled || $expense->related_canceled_movement_id || $expense->reversalExpense()->exists()) {
            return response()->json(['message' => 'Este gasto ya fue revertido previamente.'], 422);
        }

        $account = $this->resolveAccount($expense->club_id, $expense->pay_to);
        $amount = abs((float) $expense->amount);
        $reversal = null;

        DB::transaction(function () use ($expense, $validated, $user, $account, $amount, &$reversal) {
            $reversal = Expense::query()->create([
                'club_id' => $expense->club_id,
                'event_id' => $expense->event_id,
                'pay_to' => $expense->pay_to,
                'funds_location' => $expense->funds_location,
                'payment_concept_id' => $expense->payment_concept_id,
                'payee_id' => $expense->payee_id,
                'amount' => -$amount,
                'expense_date' => $validated['correction_date'],
                'description' => trim("Correccion contable. Reversa del gasto #{$expense->id}. Motivo: {$validated['reason']}"),
                'reimbursed_to' => $expense->reimbursed_to,
                'reimbursement_payee_id' => $expense->reimbursement_payee_id,
                'created_by_user_id' => $user->id,
                'status' => 'completed',
                'receipt_path' => null,
                'reimbursement_receipt_path' => null,
                'settles_expense_id' => null,
                'reversed_expense_id' => $expense->id,
                'canceling_id' => $expense->id,
            ]);

            $expense->update([
                'is_cancelled' => true,
                'related_canceled_movement_id' => $reversal->id,
            ]);

            $account->increment('balance', $amount);
        });

        return response()->json([
            'message' => 'Gasto revertido mediante movimiento opuesto.',
            'data' => ['reversal_id' => $reversal->id],
        ], 201);
    }

    public function reverseReimbursement(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensureExpenseBelongsToUser($user, $expense);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($expense->pay_to !== 'reimbursement_to' || $expense->settles_expense_id) {
            return response()->json(['message' => 'Selecciona el movimiento principal del reembolso.'], 422);
        }

        if ($expense->reversed_expense_id || $expense->canceling_id || $expense->is_cancelled || $expense->related_canceled_movement_id || $expense->reversalExpense()->exists()) {
            return response()->json(['message' => 'Este reembolso ya fue revertido previamente.'], 422);
        }

        $settlementExpense = $expense->settlementExpense()->with('reversalExpense')->first();
        $settlementPayment = $this->findSettlementPaymentForExpense($expense, $settlementExpense);

        if ($settlementExpense && ($settlementExpense->is_cancelled || $settlementExpense->related_canceled_movement_id || $settlementExpense->reversalExpense)) {
            return response()->json(['message' => 'La salida de fondos de este reembolso ya fue revertida.'], 422);
        }

        if ($settlementPayment && ($settlementPayment->is_cancelled || $settlementPayment->related_canceled_movement_id || $settlementPayment->reversalPayment()->exists())) {
            return response()->json(['message' => 'La entrada interna de este reembolso ya fue revertida.'], 422);
        }

        $amount = abs((float) $expense->amount);
        $reimbursementAccount = $this->resolveAccount($expense->club_id, 'reimbursement_to');

        DB::transaction(function () use ($expense, $validated, $user, $amount, $reimbursementAccount, $settlementExpense, $settlementPayment) {
            $reimbursementReversal = Expense::query()->create([
                'club_id' => $expense->club_id,
                'event_id' => $expense->event_id,
                'pay_to' => 'reimbursement_to',
                'funds_location' => null,
                'payment_concept_id' => $expense->payment_concept_id,
                'payee_id' => $expense->payee_id,
                'amount' => -$amount,
                'expense_date' => $validated['correction_date'],
                'description' => trim("Correccion contable. Reversa del reembolso #{$expense->id}. Motivo: {$validated['reason']}"),
                'reimbursed_to' => $expense->reimbursed_to,
                'reimbursement_payee_id' => $expense->reimbursement_payee_id,
                'created_by_user_id' => $user->id,
                'status' => 'pending_reimbursement',
                'receipt_path' => null,
                'reimbursement_receipt_path' => null,
                'settles_expense_id' => null,
                'reversed_expense_id' => $expense->id,
                'canceling_id' => $expense->id,
            ]);

            $expense->update([
                'is_cancelled' => true,
                'related_canceled_movement_id' => $reimbursementReversal->id,
            ]);

            $reimbursementAccount->increment('balance', $amount);

            if ($settlementPayment) {
                $settlementPaymentReversal = Payment::query()->create([
                    'club_id' => $settlementPayment->club_id,
                    'payment_concept_id' => $settlementPayment->payment_concept_id,
                    'concept_text' => $settlementPayment->concept_text ?: 'Correccion contable de liquidacion de reembolso',
                    'pay_to' => $settlementPayment->pay_to,
                    'account_id' => $settlementPayment->account_id,
                    'member_id' => $settlementPayment->member_id,
                    'staff_id' => $settlementPayment->staff_id,
                    'payer_name' => $settlementPayment->payer_name,
                    'amount_paid' => -abs((float) $settlementPayment->amount_paid),
                    'expected_amount' => null,
                    'balance_due_after' => null,
                    'payment_date' => $validated['correction_date'],
                    'payment_type' => 'internal',
                    'zelle_phone' => null,
                    'check_image_path' => null,
                    'received_by_user_id' => $user->id,
                    'notes' => trim("Correccion contable. Reversa de liquidacion de reembolso #{$expense->id}. Motivo: {$validated['reason']}"),
                    'reversed_payment_id' => $settlementPayment->id,
                    'settles_expense_id' => $expense->id,
                    'canceling_id' => $settlementPayment->id,
                ]);

                $this->paymentReceiptService->syncForPayment($settlementPaymentReversal);

                $settlementPayment->update([
                    'is_cancelled' => true,
                    'related_canceled_movement_id' => $settlementPaymentReversal->id,
                ]);

                $reimbursementAccount->decrement('balance', $amount);
            }

            if ($settlementExpense) {
                $fundingAccount = $this->resolveAccount($settlementExpense->club_id, $settlementExpense->pay_to);

                $settlementExpenseReversal = Expense::query()->create([
                    'club_id' => $settlementExpense->club_id,
                    'event_id' => $settlementExpense->event_id,
                    'pay_to' => $settlementExpense->pay_to,
                    'funds_location' => $settlementExpense->funds_location,
                    'payment_concept_id' => $settlementExpense->payment_concept_id,
                    'payee_id' => $settlementExpense->payee_id,
                    'amount' => -abs((float) $settlementExpense->amount),
                    'expense_date' => $validated['correction_date'],
                    'description' => trim("Correccion contable. Reversa de liquidacion del reembolso #{$expense->id}. Motivo: {$validated['reason']}"),
                    'reimbursed_to' => $settlementExpense->reimbursed_to,
                    'reimbursement_payee_id' => $settlementExpense->reimbursement_payee_id,
                    'created_by_user_id' => $user->id,
                    'status' => 'completed',
                    'receipt_path' => null,
                    'reimbursement_receipt_path' => null,
                    'settles_expense_id' => $expense->id,
                    'reversed_expense_id' => $settlementExpense->id,
                    'canceling_id' => $settlementExpense->id,
                ]);

                $settlementExpense->update([
                    'is_cancelled' => true,
                    'related_canceled_movement_id' => $settlementExpenseReversal->id,
                ]);

                $fundingAccount->increment('balance', abs((float) $settlementExpense->amount));
            }
        });

        return response()->json([
            'message' => $settlementExpense
                ? 'Reembolso completado revertido con todos sus movimientos relacionados.'
                : 'Reembolso pendiente revertido mediante movimiento opuesto.',
        ], 201);
    }

    private function ensureAccountingAccess($user): void
    {
        abort_unless(in_array(($user?->profile_type ?? null), ['club_director', 'treasurer', 'superadmin'], true), 403, 'Unauthorized.');
    }

    private function ensurePaymentBelongsToUser($user, Payment $payment): void
    {
        abort_unless(ClubHelper::clubIdsForUser($user)->contains((int) $payment->club_id), 403, 'Unauthorized.');
    }

    private function ensureExpenseBelongsToUser($user, Expense $expense): void
    {
        abort_unless(ClubHelper::clubIdsForUser($user)->contains((int) $expense->club_id), 403, 'Unauthorized.');
    }

    private function findSettlementPaymentForExpense(Expense $expense, ?Expense $settlementExpense = null): ?Payment
    {
        $query = Payment::query()
            ->where('club_id', $expense->club_id)
            ->where('pay_to', 'reimbursement_to')
            ->where('payment_type', 'internal')
            ->whereNull('reversed_payment_id');

        if ($expense->payment_concept_id) {
            $query->where('payment_concept_id', $expense->payment_concept_id);
        }

        if (Schema::hasColumn('payments', 'settles_expense_id')) {
            $linked = (clone $query)
                ->where('settles_expense_id', $expense->id)
                ->with('reversalPayment')
                ->first();

            if ($linked) {
                return $linked;
            }
        }

        if (!$settlementExpense) {
            return null;
        }

        return $query
            ->where('amount_paid', abs((float) $expense->amount))
            ->orderByRaw('ABS(id - ?) asc', [$settlementExpense->id])
            ->with('reversalPayment')
            ->first();
    }

    private function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }
}
