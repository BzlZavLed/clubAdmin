<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\Expense;
use App\Models\FinanceReimbursementPayee;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Services\ClubTreasuryService;
use App\Services\PaymentReceiptService;
use App\Support\ClubHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceExpenseWriter
{
    public function __construct(
        private readonly ClubTreasuryService $treasuryService,
        private readonly PaymentReceiptService $paymentReceiptService,
    ) {
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
            'reimbursed_to' => ['nullable', 'string', 'max:255'],
            'reimbursement_target_mode' => ['nullable', 'in:existing,new'],
            'reimbursement_payee_id' => ['nullable', 'integer', 'exists:finance_reimbursement_payees,id'],
            'reimbursement_payee_name' => ['nullable', 'string', 'max:255'],
            'reimbursement_payee_phone' => ['nullable', 'string', 'max:50'],
            'reimbursement_payee_email' => ['nullable', 'email', 'max:255'],
            'receipt_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], $this->receiptValidationMessages());

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
            $amount = round((float) $validated['amount'], 2);
            $fundsLocation = $validated['funds_location'] ?? 'cash';
            $fundingPlan = $this->treasuryService->expenseFundingPlan($club, $validated['pay_to'], $fundsLocation, $amount);
            $fromAccount = (float) $fundingPlan['amount_from_account'];
            $shortfall = (float) $fundingPlan['reimbursement_shortfall'];
            $reimbursementConcept = null;
            $reimbursementPayee = null;

            if ($shortfall > 0) {
                $reimbursementPayee = $this->resolveReimbursementPayee($club, $request, $validated);
                $reimbursementConcept = $this->reimbursementConceptFor($club, $request, $reimbursementPayee);
            }

            $receiptPath = $request->hasFile('receipt_image')
                ? $request->file('receipt_image')->store('expense-receipts', 'public')
                : null;

            if ($fromAccount > 0) {
                $this->treasuryService->recordAutomaticExpenseFundingTransfer(
                    $club,
                    $fundingPlan,
                    $request->user()?->id,
                    $validated['expense_date'],
                    'Transferencia automatica para registrar gasto desde ' . $fundsLocation . '.'
                );

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
                $reimbursementDescription = $this->normalizeText($validated['description'] ?? null)
                    ? 'Reembolso pendiente por: ' . $this->normalizeText($validated['description'] ?? null)
                    : 'Reembolso pendiente por gasto con saldo insuficiente.';

                $splitExpense = Expense::query()->create([
                    'club_id' => $club->id,
                    'pay_to' => 'reimbursement_to',
                    'funds_location' => null,
                    'payment_concept_id' => $reimbursementConcept->id,
                    'payee_id' => $reimbursementConcept->payee_id,
                    'reimbursement_payee_id' => $reimbursementPayee?->id,
                    'amount' => $shortfall,
                    'expense_date' => $validated['expense_date'],
                    'description' => $reimbursementDescription,
                    'reimbursed_to' => $reimbursementPayee?->name,
                    'created_by_user_id' => $request->user()->id,
                    'status' => 'pending_reimbursement',
                    'receipt_path' => null,
                    'reimbursement_origin_expense_id' => $expense?->id,
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

    public function uploadReceipt(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        $request->validate([
            'receipt_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], $this->receiptValidationMessages());

        $path = $request->file('receipt_image')->store('expense-receipts', 'public');

        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->update([
            'receipt_path' => $path,
            'status' => 'completed',
        ]);

        return response()->json([
            'message' => 'Receipt uploaded',
            'data' => $expense->refresh(),
        ]);
    }

    public function removeReceipt(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt to remove.'], 422);
        }

        Storage::disk('public')->delete($expense->receipt_path);

        $expense->update([
            'receipt_path' => null,
            'status' => 'working',
        ]);

        return response()->json([
            'message' => 'Receipt removed',
            'data' => $expense->refresh(),
        ]);
    }

    public function uploadReimbursementReceipt(Request $request, Expense $expense): JsonResponse
    {
        return $this->uploadReimbursementPaymentProof($request, $expense);
    }

    public function uploadReimbursementPaymentProof(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        if ($expense->pay_to !== 'reimbursement_to') {
            return response()->json(['message' => 'Only reimbursements can accept this payment proof.'], 422);
        }

        $field = $this->paymentProofFileField($request);
        if (!$field) {
            return response()->json([
                'message' => 'Select a reimbursement payment proof file.',
                'errors' => ['payment_proof_file' => ['Select a reimbursement payment proof file.']],
            ], 422);
        }

        $request->validate([$field => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']], $this->receiptValidationMessages());

        $path = $request->file($field)->store('reimbursement-payment-proofs', 'public');

        if ($expense->reimbursement_payment_proof_path) {
            Storage::disk('public')->delete($expense->reimbursement_payment_proof_path);
        }

        $expense->update([
            'reimbursement_payment_proof_path' => $path,
            'reimbursement_payment_proof_uploaded_at' => now(),
            'reimbursement_payment_proof_uploaded_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Reimbursement payment proof uploaded',
            'data' => $expense->refresh(),
        ]);
    }

    public function removeReimbursementReceipt(Request $request, Expense $expense): JsonResponse
    {
        return $this->removeReimbursementPaymentProof($request, $expense);
    }

    public function removeReimbursementPaymentProof(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        if (!$expense->reimbursement_payment_proof_path) {
            return response()->json(['message' => 'No reimbursement payment proof to remove.'], 422);
        }

        $oldPath = $expense->reimbursement_payment_proof_path;
        Storage::disk('public')->delete($oldPath);

        $expense->update([
            'reimbursement_payment_proof_path' => null,
            'reimbursement_payment_proof_uploaded_at' => null,
            'reimbursement_payment_proof_uploaded_by_user_id' => null,
        ]);

        return response()->json([
            'message' => 'Reimbursement payment proof removed',
            'data' => $expense->refresh(),
        ]);
    }

    public function markReimbursed(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'funds_location' => ['nullable', 'in:cash,bank'],
            'receipt_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'payment_proof_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'reimbursement_date' => ['nullable', 'date'],
        ], $this->receiptValidationMessages());

        if ($expense->pay_to !== 'reimbursement_to' || $expense->status !== 'pending_reimbursement') {
            return response()->json(['message' => 'Only pending reimbursements can be marked as reimbursed.'], 422);
        }

        if ($validated['pay_to'] === 'reimbursement_to') {
            return response()->json(['message' => 'Invalid funding account.'], 422);
        }

        $account = $this->resolveAccount($expense->club_id, $validated['pay_to']);
        $fundsLocation = $validated['funds_location'] ?? 'cash';
        $club = Club::withoutGlobalScopes()->findOrFail($expense->club_id);
        $fundingPlan = $this->treasuryService->expenseFundingPlan($club, $validated['pay_to'], $fundsLocation, (float) $expense->amount);

        if ((float) $fundingPlan['reimbursement_shortfall'] > 0) {
            return response()->json([
                'message' => 'La cuenta seleccionada no tiene el monto completo para este reembolso.',
                'errors' => ['pay_to' => ['La cuenta seleccionada no tiene el monto completo para este reembolso.']]
            ], 422);
        }

        $paymentProofPath = $expense->reimbursement_payment_proof_path;
        $paymentProofField = $this->paymentProofFileField($request);
        if ($paymentProofField) {
            $paymentProofPath = $request->file($paymentProofField)->store('reimbursement-payment-proofs', 'public');

            if ($expense->reimbursement_payment_proof_path) {
                Storage::disk('public')->delete($expense->reimbursement_payment_proof_path);
            }
        }

        $reimbursementDate = $validated['reimbursement_date'] ?? now()->toDateString();

        DB::transaction(function () use ($expense, $account, $paymentProofPath, $paymentProofField, $request, $fundsLocation, $reimbursementDate, $club, $fundingPlan) {
            $reimbursementAccount = $this->resolveAccount($expense->club_id, 'reimbursement_to');

            $this->treasuryService->recordAutomaticExpenseFundingTransfer(
                $club,
                $fundingPlan,
                $request->user()?->id,
                $reimbursementDate,
                'Transferencia automatica para liquidar reembolso desde ' . $fundsLocation . '.'
            );

            $settlementPayment = \App\Models\Payment::query()->create([
                'club_id' => $expense->club_id,
                'payment_concept_id' => $expense->payment_concept_id,
                'concept_text' => 'Liquidacion de reembolso',
                'pay_to' => 'reimbursement_to',
                'account_id' => $reimbursementAccount->id,
                'settles_expense_id' => $expense->id,
                'amount_paid' => (float) $expense->amount,
                'expected_amount' => null,
                'balance_due_after' => null,
                'payment_date' => $reimbursementDate,
                'payment_type' => 'internal',
                'received_by_user_id' => $request->user()->id,
                'notes' => 'Credito automatico para saldar reembolso pendiente.',
            ]);
            $this->paymentReceiptService->syncForPayment($settlementPayment);

            Expense::query()->create([
                'club_id' => $expense->club_id,
                'pay_to' => $account->pay_to,
                'funds_location' => $fundsLocation,
                'amount' => (float) $expense->amount,
                'expense_date' => $reimbursementDate,
                'description' => 'Reembolso a ' . ($expense->reimbursed_to ?? 'persona'),
                'reimbursed_to' => $expense->reimbursed_to,
                'reimbursement_payee_id' => $expense->reimbursement_payee_id,
                'created_by_user_id' => $request->user()->id,
                'status' => 'completed',
                'receipt_path' => null,
                'settles_expense_id' => $expense->id,
                'reimbursement_origin_expense_id' => $expense->reimbursement_origin_expense_id,
            ]);

            $account->decrement('balance', (float) $expense->amount);
            $reimbursementAccount->increment('balance', (float) $expense->amount);

            $expense->update([
                'status' => 'completed',
                'reimbursement_receipt_path' => null,
                'reimbursement_receipt_token' => $expense->reimbursement_receipt_token ?: Str::random(48),
                'reimbursement_receipt_signed_at' => null,
                'reimbursement_receipt_signature_path' => null,
                'reimbursement_receipt_signer_name' => null,
                'reimbursement_receipt_acknowledged' => false,
                'reimbursement_receipt_ip' => null,
                'reimbursement_receipt_user_agent' => null,
                'reimbursement_receipt_validation_checksum' => null,
                'reimbursement_payment_proof_path' => $paymentProofPath,
                'reimbursement_payment_proof_uploaded_at' => $paymentProofPath && $paymentProofField ? now() : $expense->reimbursement_payment_proof_uploaded_at,
                'reimbursement_payment_proof_uploaded_by_user_id' => $paymentProofPath && $paymentProofField ? $request->user()?->id : $expense->reimbursement_payment_proof_uploaded_by_user_id,
            ]);
        });

        return response()->json([
            'message' => 'Reimbursement recorded',
            'data' => $expense->refresh(),
        ]);
    }

    private function reimbursementConceptFor(Club $club, Request $request, FinanceReimbursementPayee $payee): PaymentConcept
    {
        return PaymentConcept::query()->firstOrCreate(
            [
                'club_id' => $club->id,
                'pay_to' => 'reimbursement_to',
                'payee_type' => FinanceReimbursementPayee::class,
                'payee_id' => $payee->id,
            ],
            [
                'concept' => 'Reembolso a ' . $payee->name,
                'payment_expected_by' => null,
                'type' => 'optional',
                'status' => 'active',
                'amount' => 0,
                'created_by' => $request->user()->id,
            ]
        );
    }

    private function resolveReimbursementPayee(Club $club, Request $request, array $validated): FinanceReimbursementPayee
    {
        if (($validated['reimbursement_target_mode'] ?? null) === 'existing' && !empty($validated['reimbursement_payee_id'])) {
            return FinanceReimbursementPayee::query()
                ->where('club_id', $club->id)
                ->findOrFail((int) $validated['reimbursement_payee_id']);
        }

        $name = $this->normalizeText($validated['reimbursement_payee_name'] ?? null)
            ?: $this->normalizeText($validated['reimbursed_to'] ?? null);
        $phone = $this->normalizeText($validated['reimbursement_payee_phone'] ?? null);
        $email = $this->normalizeEmail($validated['reimbursement_payee_email'] ?? null);

        if ($name) {
            return $this->storeReimbursementPayee($club, $request, $name, $phone, $email);
        }

        $staff = Staff::query()
            ->where('user_id', $request->user()->id)
            ->where('club_id', $club->id)
            ->first();

        $name = $staff
            ? (ClubHelper::staffDetail($staff)['name'] ?? $request->user()->name ?? 'Personal')
            : ($request->user()->name ?? 'Director');

        return $this->storeReimbursementPayee(
            $club,
            $request,
            $name,
            null,
            $this->normalizeEmail($request->user()->email ?? null)
        );
    }

    private function storeReimbursementPayee(Club $club, Request $request, string $name, ?string $phone, ?string $email): FinanceReimbursementPayee
    {
        $identity = $email
            ? ['club_id' => $club->id, 'email' => $email]
            : ['club_id' => $club->id, 'name' => $name, 'phone' => $phone];

        $payee = FinanceReimbursementPayee::query()->firstOrNew($identity);
        $payee->fill([
            'club_id' => $club->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'created_by_user_id' => $payee->created_by_user_id ?: $request->user()->id,
        ]);
        $payee->save();

        return $payee;
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        return $normalized ? strtolower($normalized) : null;
    }

    private function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }

    private function paymentProofFileField(Request $request): ?string
    {
        if ($request->hasFile('payment_proof_file')) {
            return 'payment_proof_file';
        }

        return $request->hasFile('receipt_image') ? 'receipt_image' : null;
    }

    private function receiptValidationMessages(): array
    {
        return [
            'receipt_image.image' => 'El comprobante debe ser una imagen JPG, PNG o WEBP.',
            'receipt_image.mimes' => 'El comprobante debe ser JPG, PNG o WEBP.',
            'receipt_image.max' => 'El comprobante no puede pesar mas de 5 MB.',
            'payment_proof_file.mimes' => 'El comprobante de pago debe ser JPG, PNG, WEBP o PDF.',
            'payment_proof_file.max' => 'El comprobante de pago no puede pesar mas de 10 MB.',
        ];
    }

    private function ensureExpenseBelongsToUser($user, Expense $expense): void
    {
        abort_unless(ClubHelper::clubIdsForUser($user)->contains((int) $expense->club_id), 403, 'Unauthorized.');
    }
}
