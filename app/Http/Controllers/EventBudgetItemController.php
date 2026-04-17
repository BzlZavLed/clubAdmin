<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventBudgetItem;
use App\Models\Expense;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EventBudgetItemController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'budget_items' => $event->budgetItems()->with(['expense', 'reimbursementExpense'])->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $item = DB::transaction(function () use ($request, $event, $validated) {
            $receiptPath = $request->hasFile('receipt_image')
                ? $request->file('receipt_image')->store("event-budget-receipts/{$event->id}", 'public')
                : null;

            $item = EventBudgetItem::create([
                'event_id' => $event->id,
                'category' => $validated['category'],
                'description' => $validated['description'],
                'qty' => (float) ($validated['qty'] ?? 1),
                'unit_cost' => (float) ($validated['unit_cost'] ?? 0),
                'funding_source' => $validated['funding_source'] ?: 'club_budget',
                'expense_date' => $validated['expense_date'] ?? optional($event->start_at)->format('Y-m-d') ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'receipt_path' => $receiptPath,
            ]);

            $this->syncLedgerExpenses($event, $item, $request->user()->id, $receiptPath);

            return $item;
        });

        return response()->json(['budget_item' => $item->fresh(['expense', 'reimbursementExpense'])]);
    }

    public function update(Request $request, EventBudgetItem $eventBudgetItem)
    {
        $event = $eventBudgetItem->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
        ]);

        DB::transaction(function () use ($request, $event, $eventBudgetItem, $validated) {
            $receiptPath = $eventBudgetItem->receipt_path;
            if ($request->hasFile('receipt_image')) {
                if ($receiptPath) {
                    Storage::disk('public')->delete($receiptPath);
                }
                $receiptPath = $request->file('receipt_image')->store("event-budget-receipts/{$event->id}", 'public');
            }

            $eventBudgetItem->update([
                'category' => $validated['category'],
                'description' => $validated['description'],
                'qty' => (float) ($validated['qty'] ?? 1),
                'unit_cost' => (float) ($validated['unit_cost'] ?? 0),
                'funding_source' => $validated['funding_source'] ?: 'club_budget',
                'expense_date' => $validated['expense_date'] ?? $eventBudgetItem->expense_date?->format('Y-m-d') ?? optional($event->start_at)->format('Y-m-d') ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'receipt_path' => $receiptPath,
            ]);

            $this->syncLedgerExpenses($event, $eventBudgetItem, $request->user()->id, $receiptPath);
        });

        return response()->json(['budget_item' => $eventBudgetItem->fresh(['expense', 'reimbursementExpense'])]);
    }

    public function uploadReceipt(Request $request, EventBudgetItem $eventBudgetItem)
    {
        $event = $eventBudgetItem->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'receipt_image' => ['required', 'image', 'max:5120'],
        ]);

        DB::transaction(function () use ($validated, $eventBudgetItem) {
            if ($eventBudgetItem->receipt_path) {
                Storage::disk('public')->delete($eventBudgetItem->receipt_path);
            }

            $path = $validated['receipt_image']->store("event-budget-receipts/{$eventBudgetItem->event_id}", 'public');

            $eventBudgetItem->update([
                'receipt_path' => $path,
            ]);

            if ($eventBudgetItem->expense) {
                $eventBudgetItem->expense->update([
                    'receipt_path' => $path,
                    'status' => 'completed',
                ]);
            }
        });

        return response()->json(['budget_item' => $eventBudgetItem->fresh(['expense', 'reimbursementExpense'])]);
    }

    public function destroy(EventBudgetItem $eventBudgetItem)
    {
        $event = $eventBudgetItem->event;
        $this->authorize('update', $event);

        DB::transaction(function () use ($event, $eventBudgetItem) {
            $this->reverseLedgerExpenses($event, $eventBudgetItem);

            if ($eventBudgetItem->receipt_path) {
                Storage::disk('public')->delete($eventBudgetItem->receipt_path);
            }

            $eventBudgetItem->delete();
        });

        return response()->json(['deleted' => true]);
    }

    protected function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => $payTo, 'balance' => 0]
        );
    }

    protected function syncLedgerExpenses(Event $event, EventBudgetItem $item, int $userId, ?string $receiptPath): void
    {
        $this->reverseLedgerExpenses($event, $item);

        $payTo = $item->funding_source ?: 'club_budget';
        $account = $this->resolveAccount($event->club_id, $payTo);
        $amount = (float) $item->total;
        $available = max((float) $account->balance, 0.0);
        $fromAccount = min($amount, $available);
        $shortfall = max($amount - $fromAccount, 0.0);

        $primaryExpense = null;
        if ($fromAccount > 0) {
            $primaryExpense = Expense::create([
                'club_id' => $event->club_id,
                'event_id' => $event->id,
                'pay_to' => $payTo,
                'payment_concept_id' => null,
                'payee_id' => null,
                'amount' => $fromAccount,
                'expense_date' => $item->expense_date,
                'description' => $item->description,
                'reimbursed_to' => null,
                'created_by_user_id' => $userId,
                'status' => $receiptPath ? 'completed' : 'working',
                'receipt_path' => $receiptPath,
            ]);

            $account->decrement('balance', $fromAccount);
        }

        $reimbursementExpense = null;
        if ($shortfall > 0) {
            [$reimbursementConcept, $reimburseTo] = $this->resolveReimbursementTarget($event, $userId);
            $reimbursementAccount = $this->resolveAccount($event->club_id, 'reimbursement_to');

            $reimbursementExpense = Expense::create([
                'club_id' => $event->club_id,
                'event_id' => $event->id,
                'pay_to' => 'reimbursement_to',
                'payment_concept_id' => $reimbursementConcept->id,
                'payee_id' => $reimbursementConcept->payee_id,
                'amount' => $shortfall,
                'expense_date' => $item->expense_date,
                'description' => 'Reembolso pendiente por gasto del evento con saldo insuficiente.',
                'reimbursed_to' => $reimburseTo,
                'created_by_user_id' => $userId,
                'status' => 'pending_reimbursement',
                'receipt_path' => null,
            ]);

            $reimbursementAccount->decrement('balance', $shortfall);
        }

        $item->update([
            'expense_id' => $primaryExpense?->id,
            'reimbursement_expense_id' => $reimbursementExpense?->id,
        ]);
    }

    protected function reverseLedgerExpenses(Event $event, EventBudgetItem $item): void
    {
        if ($item->expense) {
            $account = $this->resolveAccount($event->club_id, $item->expense->pay_to ?: ($item->funding_source ?: 'club_budget'));
            $account->increment('balance', (float) $item->expense->amount);
            $item->expense->delete();
        }

        if ($item->reimbursementExpense) {
            $reimbursementAccount = $this->resolveAccount($event->club_id, 'reimbursement_to');
            $reimbursementAccount->increment('balance', (float) $item->reimbursementExpense->amount);
            $item->reimbursementExpense->delete();
        }

        $item->update([
            'expense_id' => null,
            'reimbursement_expense_id' => null,
        ]);
    }

    protected function resolveReimbursementTarget(Event $event, int $userId): array
    {
        $staff = Staff::where('user_id', $userId)
            ->where('club_id', $event->club_id)
            ->first();

        if ($staff) {
            $reimburseTo = ClubHelper::staffDetail($staff)['name'] ?? auth()->user()?->name ?? 'Personal';
            $concept = PaymentConcept::firstOrCreate(
                [
                    'club_id' => $event->club_id,
                    'pay_to' => 'reimbursement_to',
                    'payee_type' => Staff::class,
                    'payee_id' => $staff->id,
                ],
                [
                    'concept' => 'Reembolso a ' . $reimburseTo,
                    'payment_expected_by' => null,
                    'type' => 'optional',
                    'status' => 'active',
                    'amount' => 0,
                    'created_by' => $userId,
                ]
            );

            return [$concept, $reimburseTo];
        }

        $reimburseTo = auth()->user()?->name ?? 'Director';
        $concept = PaymentConcept::firstOrCreate(
            [
                'club_id' => $event->club_id,
                'pay_to' => 'reimbursement_to',
                'payee_type' => \App\Models\User::class,
                'payee_id' => $userId,
            ],
            [
                'concept' => 'Reembolso a ' . $reimburseTo,
                'payment_expected_by' => null,
                'type' => 'optional',
                'status' => 'active',
                'amount' => 0,
                'created_by' => $userId,
            ]
        );

        return [$concept, $reimburseTo];
    }
}
