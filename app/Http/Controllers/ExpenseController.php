<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Support\ClubHelper;

class ExpenseController extends Controller
{
    public function uploadReceipt(Request $request, Expense $expense)
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        $validated = $request->validate([
            'receipt_image' => ['required', 'image', 'max:5120'],
        ]);

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

    public function removeReceipt(Request $request, Expense $expense)
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

    public function uploadReimbursementReceipt(Request $request, Expense $expense)
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        if ($expense->pay_to !== 'reimbursement_to') {
            return response()->json(['message' => 'Only reimbursements can accept this receipt.'], 422);
        }

        $validated = $request->validate([
            'receipt_image' => ['required', 'image', 'max:5120'],
        ]);

        $path = $request->file('receipt_image')->store('reimbursement-receipts', 'public');

        if ($expense->reimbursement_receipt_path) {
            Storage::disk('public')->delete($expense->reimbursement_receipt_path);
        }

        $expense->update([
            'reimbursement_receipt_path' => $path,
        ]);

        return response()->json([
            'message' => 'Reimbursement receipt uploaded',
            'data' => $expense->refresh(),
        ]);
    }

    public function removeReimbursementReceipt(Request $request, Expense $expense)
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        if (!$expense->reimbursement_receipt_path) {
            return response()->json(['message' => 'No reimbursement receipt to remove.'], 422);
        }

        Storage::disk('public')->delete($expense->reimbursement_receipt_path);

        $expense->update([
            'reimbursement_receipt_path' => null,
        ]);

        return response()->json([
            'message' => 'Reimbursement receipt removed',
            'data' => $expense->refresh(),
        ]);
    }

    public function markReimbursed(Request $request, Expense $expense)
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'funds_location' => ['nullable', 'in:cash,bank'],
            'receipt_image' => ['required', 'image', 'max:5120'],
        ]);

        if ($expense->pay_to !== 'reimbursement_to' || $expense->status !== 'pending_reimbursement') {
            return response()->json(['message' => 'Only pending reimbursements can be marked as reimbursed.'], 422);
        }

        if ($validated['pay_to'] === 'reimbursement_to') {
            return response()->json(['message' => 'Invalid funding account.'], 422);
        }

        $account = $this->resolveAccount($expense->club_id, $validated['pay_to']);
        $fundsLocation = $validated['funds_location'] ?? 'cash';
        $club = \App\Models\Club::withoutGlobalScopes()->findOrFail($expense->club_id);

        if ($this->locationBalanceFor($club, $validated['pay_to'], $fundsLocation) < (float) $expense->amount) {
            return response()->json([
                'message' => 'Saldo insuficiente en la ubicación seleccionada para reembolsar.',
                'errors' => ['funds_location' => ['Saldo insuficiente en la ubicación seleccionada para reembolsar.']]
            ], 422);
        }

        $receiptPath = $request->file('receipt_image')->store('reimbursement-receipts', 'public');

        if ($expense->reimbursement_receipt_path) {
            Storage::disk('public')->delete($expense->reimbursement_receipt_path);
        }

        \DB::transaction(function () use ($expense, $account, $receiptPath, $request, $fundsLocation) {
            $reimbursementAccount = $this->resolveAccount($expense->club_id, 'reimbursement_to');

            Payment::create([
                'club_id' => $expense->club_id,
                'payment_concept_id' => $expense->payment_concept_id,
                'concept_text' => 'Liquidacion de reembolso',
                'pay_to' => 'reimbursement_to',
                'account_id' => $reimbursementAccount->id,
                'settles_expense_id' => $expense->id,
                'amount_paid' => (float) $expense->amount,
                'expected_amount' => null,
                'balance_due_after' => null,
                'payment_date' => now()->toDateString(),
                'payment_type' => 'internal',
                'received_by_user_id' => $request->user()->id,
                'notes' => 'Credito automatico para saldar reembolso pendiente.',
            ]);

            // Outflow expense against the funding account makes the deduction visible in reports.
            Expense::create([
                'club_id'             => $expense->club_id,
                'pay_to'              => $account->pay_to,
                'funds_location'      => $fundsLocation,
                'amount'              => (float) $expense->amount,
                'expense_date'        => now()->toDateString(),
                'description'         => 'Reembolso a ' . ($expense->reimbursed_to ?? 'persona'),
                'reimbursed_to'       => $expense->reimbursed_to,
                'created_by_user_id'  => $request->user()->id,
                'status'              => 'completed',
                'receipt_path'        => $receiptPath,
                'settles_expense_id'  => $expense->id,
            ]);

            $account->decrement('balance', (float) $expense->amount);
            $reimbursementAccount->increment('balance', (float) $expense->amount);

            // Mark original pending_reimbursement as settled, attach proof
            $expense->update([
                'status'                      => 'completed',
                'reimbursement_receipt_path'  => $receiptPath,
            ]);
        });

        return response()->json([
            'message' => 'Reimbursement recorded',
            'data' => $expense->refresh(),
        ]);
    }

    protected function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }

    protected function locationBalanceFor(\App\Models\Club $club, string $payTo, string $fundsLocation): float
    {
        $row = app(\App\Services\ClubTreasuryService::class)
            ->locationBalancesByAccount($club)
            ->firstWhere('account', $payTo);

        return max((float) ($row[$fundsLocation . '_balance'] ?? 0), 0.0);
    }

    protected function ensureExpenseBelongsToUser($user, Expense $expense): void
    {
        $allowedClubIds = ClubHelper::clubIdsForUser($user);

        abort_unless($allowedClubIds->contains((int) $expense->club_id), 403, 'Unauthorized.');
    }
}
