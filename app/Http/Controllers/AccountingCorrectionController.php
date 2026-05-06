<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\ClubHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountingCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);

        $club = ClubHelper::clubForUser($user, $request->input('club_id'));
        $clubs = ClubHelper::clubsForUser($user)
            ->map(fn ($allowedClub) => [
                'id' => $allowedClub->id,
                'club_name' => $allowedClub->club_name,
            ])
            ->values();

        $payments = Payment::query()
            ->where('club_id', $club->id)
            ->whereNull('reversed_payment_id')
            ->where('payment_type', '!=', 'internal')
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept',
                'account:id,club_id,pay_to,label',
                'receivedBy:id,name',
                'reversalPayment:id,reversed_payment_id,amount_paid,payment_date,created_at',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Payment $payment) {
                $member = ClubHelper::memberDetail($payment->member);
                $staff = ClubHelper::staffDetail($payment->staff);
                $reversal = $payment->reversalPayment;

                return [
                    'id' => $payment->id,
                    'club_id' => $payment->club_id,
                    'payment_concept_id' => $payment->payment_concept_id,
                    'concept_text' => $payment->concept_text,
                    'concept_name' => $payment->concept?->concept,
                    'pay_to' => $payment->pay_to,
                    'account_label' => $payment->account?->label,
                    'member_display_name' => $member['name'] ?? null,
                    'staff_display_name' => $staff['name'] ?? null,
                    'amount_paid' => (float) $payment->amount_paid,
                    'payment_date' => optional($payment->payment_date)->toDateString(),
                    'payment_type' => $payment->payment_type,
                    'notes' => $payment->notes,
                    'received_by_name' => $payment->receivedBy?->name,
                    'can_reverse' => $reversal === null,
                    'reversal' => $reversal ? [
                        'id' => $reversal->id,
                        'amount_paid' => (float) $reversal->amount_paid,
                        'payment_date' => optional($reversal->payment_date)->toDateString(),
                        'created_at' => optional($reversal->created_at)->toDateTimeString(),
                    ] : null,
                ];
            })
            ->values();

        $reimbursements = Expense::query()
            ->where('club_id', $club->id)
            ->whereNull('reversed_expense_id')
            ->where('pay_to', 'reimbursement_to')
            ->whereNull('settles_expense_id')
            ->with([
                'createdBy:id,name',
                'settlementExpense:id,club_id,pay_to,amount,expense_date,created_at,settles_expense_id,reversed_expense_id',
                'settlementExpense.reversalExpense:id,reversed_expense_id,amount,expense_date,created_at,status',
                'reversalExpense:id,reversed_expense_id,amount,expense_date,created_at,status',
            ])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Expense $expense) {
                $settlementExpense = $expense->settlementExpense;
                $settlementPayment = $this->findSettlementPaymentForExpense($expense, $settlementExpense);
                $reversal = $expense->reversalExpense;
                $canReverse = $reversal === null
                    && (!$settlementExpense || $settlementExpense->reversalExpense === null)
                    && (!$settlementPayment || $settlementPayment->reversalPayment === null);

                return [
                    'id' => $expense->id,
                    'club_id' => $expense->club_id,
                    'amount' => (float) $expense->amount,
                    'expense_date' => optional($expense->expense_date)->toDateString(),
                    'description' => $expense->description,
                    'status' => $expense->status,
                    'reimbursed_to' => $expense->reimbursed_to,
                    'created_by_name' => $expense->createdBy?->name,
                    'can_reverse' => $canReverse,
                    'is_completed' => $expense->status === 'completed' && $settlementExpense !== null,
                    'settlement' => $settlementExpense ? [
                        'expense_id' => $settlementExpense->id,
                        'pay_to' => $settlementExpense->pay_to,
                        'amount' => (float) $settlementExpense->amount,
                        'expense_date' => optional($settlementExpense->expense_date)->toDateString(),
                        'reversed' => $settlementExpense->reversalExpense !== null,
                    ] : null,
                    'settlement_payment' => $settlementPayment ? [
                        'id' => $settlementPayment->id,
                        'amount_paid' => (float) $settlementPayment->amount_paid,
                        'payment_date' => optional($settlementPayment->payment_date)->toDateString(),
                        'reversed' => $settlementPayment->reversalPayment !== null,
                    ] : null,
                    'reversal' => $reversal ? [
                        'id' => $reversal->id,
                        'amount' => (float) $reversal->amount,
                        'expense_date' => optional($reversal->expense_date)->toDateString(),
                        'created_at' => optional($reversal->created_at)->toDateTimeString(),
                    ] : null,
                ];
            })
            ->values();

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->whereNull('reversed_expense_id')
            ->whereNull('settles_expense_id')
            ->where('pay_to', '!=', 'reimbursement_to')
            ->whereDoesntHave('settlementExpense')
            ->with([
                'createdBy:id,name',
                'reversalExpense:id,reversed_expense_id,amount,expense_date,created_at',
            ])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Expense $expense) {
                $reversal = $expense->reversalExpense;

                return [
                    'id' => $expense->id,
                    'club_id' => $expense->club_id,
                    'pay_to' => $expense->pay_to,
                    'amount' => (float) $expense->amount,
                    'expense_date' => optional($expense->expense_date)->toDateString(),
                    'description' => $expense->description,
                    'status' => $expense->status,
                    'reimbursed_to' => $expense->reimbursed_to,
                    'created_by_name' => $expense->createdBy?->name,
                    'can_reverse' => $reversal === null,
                    'reversal' => $reversal ? [
                        'id' => $reversal->id,
                        'amount' => (float) $reversal->amount,
                        'expense_date' => optional($reversal->expense_date)->toDateString(),
                        'created_at' => optional($reversal->created_at)->toDateTimeString(),
                    ] : null,
                ];
            })
            ->values();

        $payload = [
            'club_id' => $club->id,
            'clubs' => $clubs,
            'payments' => $payments,
            'reimbursements' => $reimbursements,
            'expenses' => $expenses,
        ];

        if ($request->wantsJson()) {
            return response()->json(['data' => $payload]);
        }

        return Inertia::render('ClubDirector/AccountingCorrections', $payload);
    }

    public function reversePayment(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensurePaymentBelongsToDirector($user, $payment);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->payment_type === 'internal') {
            return response()->json(['message' => 'Los movimientos internos no se corrigen desde este modulo.'], 422);
        }

        if ($payment->reversed_payment_id) {
            return response()->json(['message' => 'Este movimiento ya es una reversa y no puede revertirse de nuevo.'], 422);
        }

        if ($payment->reversalPayment()->exists()) {
            return response()->json(['message' => 'Este ingreso ya fue revertido previamente.'], 422);
        }

        $account = $payment->account ?: $this->resolveAccount($payment->club_id, $payment->pay_to ?: 'club_budget');
        $amount = abs((float) $payment->amount_paid);
        $reversal = null;

        DB::transaction(function () use ($payment, $validated, $user, $account, $amount, &$reversal) {
            $reversal = Payment::create([
                'club_id' => $payment->club_id,
                'payment_concept_id' => $payment->payment_concept_id,
                'concept_text' => $payment->concept_text ?: 'Correccion contable de ingreso',
                'pay_to' => $payment->pay_to,
                'account_id' => $account->id,
                'member_id' => $payment->member_id,
                'staff_id' => $payment->staff_id,
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
            ]);

            $account->decrement('balance', $amount);
        });

        return response()->json([
            'message' => 'Ingreso revertido mediante movimiento opuesto.',
            'data' => [
                'reversal_id' => $reversal->id,
            ],
        ], 201);
    }

    public function reverseExpense(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensureExpenseBelongsToDirector($user, $expense);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($expense->reversed_expense_id) {
            return response()->json(['message' => 'Este movimiento ya es una reversa y no puede revertirse de nuevo.'], 422);
        }

        if ($expense->settles_expense_id || $expense->settlementExpense()->exists()) {
            return response()->json(['message' => 'Los movimientos ligados a reembolsos se corrigen desde su flujo de reembolso.'], 422);
        }

        if ($expense->reversalExpense()->exists()) {
            return response()->json(['message' => 'Este gasto ya fue revertido previamente.'], 422);
        }

        $account = $this->resolveAccount($expense->club_id, $expense->pay_to);
        $amount = abs((float) $expense->amount);
        $reversal = null;

        DB::transaction(function () use ($expense, $validated, $user, $account, $amount, &$reversal) {
            $reversal = Expense::create([
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
                'created_by_user_id' => $user->id,
                'status' => 'completed',
                'receipt_path' => null,
                'reimbursement_receipt_path' => null,
                'settles_expense_id' => null,
                'reversed_expense_id' => $expense->id,
            ]);

            $account->increment('balance', $amount);
        });

        return response()->json([
            'message' => 'Gasto revertido mediante movimiento opuesto.',
            'data' => [
                'reversal_id' => $reversal->id,
            ],
        ], 201);
    }

    public function reverseReimbursement(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccountingAccess($user);
        $this->ensureExpenseBelongsToDirector($user, $expense);

        $validated = $request->validate([
            'correction_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($expense->pay_to !== 'reimbursement_to' || $expense->settles_expense_id) {
            return response()->json(['message' => 'Selecciona el movimiento principal del reembolso.'], 422);
        }

        if ($expense->reversed_expense_id || $expense->reversalExpense()->exists()) {
            return response()->json(['message' => 'Este reembolso ya fue revertido previamente.'], 422);
        }

        $settlementExpense = $expense->settlementExpense()->with('reversalExpense')->first();
        $settlementPayment = $this->findSettlementPaymentForExpense($expense, $settlementExpense);

        if ($settlementExpense && $settlementExpense->reversalExpense) {
            return response()->json(['message' => 'La salida de fondos de este reembolso ya fue revertida.'], 422);
        }

        if ($settlementPayment && $settlementPayment->reversalPayment()->exists()) {
            return response()->json(['message' => 'La entrada interna de este reembolso ya fue revertida.'], 422);
        }

        $amount = abs((float) $expense->amount);
        $reimbursementAccount = $this->resolveAccount($expense->club_id, 'reimbursement_to');

        DB::transaction(function () use ($expense, $validated, $user, $amount, $reimbursementAccount, $settlementExpense, $settlementPayment) {
            // Reverse the original reimbursement request so the clearing account is restored.
            Expense::create([
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
                'created_by_user_id' => $user->id,
                'status' => 'pending_reimbursement',
                'receipt_path' => null,
                'reimbursement_receipt_path' => null,
                'settles_expense_id' => null,
                'reversed_expense_id' => $expense->id,
            ]);

            $reimbursementAccount->increment('balance', $amount);

            if ($settlementPayment) {
                Payment::create([
                    'club_id' => $settlementPayment->club_id,
                    'payment_concept_id' => $settlementPayment->payment_concept_id,
                    'concept_text' => $settlementPayment->concept_text ?: 'Correccion contable de liquidacion de reembolso',
                    'pay_to' => $settlementPayment->pay_to,
                    'account_id' => $settlementPayment->account_id,
                    'member_id' => $settlementPayment->member_id,
                    'staff_id' => $settlementPayment->staff_id,
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
                ]);

                $reimbursementAccount->decrement('balance', $amount);
            }

            if ($settlementExpense) {
                $fundingAccount = $this->resolveAccount($settlementExpense->club_id, $settlementExpense->pay_to);

                Expense::create([
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
                    'created_by_user_id' => $user->id,
                    'status' => 'completed',
                    'receipt_path' => null,
                    'reimbursement_receipt_path' => null,
                    'settles_expense_id' => $expense->id,
                    'reversed_expense_id' => $settlementExpense->id,
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

    protected function ensureAccountingAccess($user): void
    {
        abort_unless(in_array(($user?->profile_type ?? null), ['club_director', 'superadmin'], true), 403, 'Unauthorized.');
    }

    protected function ensurePaymentBelongsToDirector($user, Payment $payment): void
    {
        $allowedClubIds = ClubHelper::clubIdsForUser($user);
        abort_unless($allowedClubIds->contains((int) $payment->club_id), 403, 'Unauthorized.');
    }

    protected function ensureExpenseBelongsToDirector($user, Expense $expense): void
    {
        $allowedClubIds = ClubHelper::clubIdsForUser($user);
        abort_unless($allowedClubIds->contains((int) $expense->club_id), 403, 'Unauthorized.');
    }

    protected function findSettlementPaymentForExpense(Expense $expense, ?Expense $settlementExpense = null): ?Payment
    {
        $query = Payment::query()
            ->where('club_id', $expense->club_id)
            ->where('pay_to', 'reimbursement_to')
            ->where('payment_type', 'internal')
            ->whereNull('reversed_payment_id');

        if ($expense->payment_concept_id) {
            $query->where('payment_concept_id', $expense->payment_concept_id);
        }

        if (\Schema::hasColumn('payments', 'settles_expense_id')) {
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

    protected function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }
}
