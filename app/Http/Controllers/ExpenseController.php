<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PayToOption;
use App\Models\Account;
use App\Models\PaymentConcept;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Member;
use App\Models\StaffAdventurer;
use App\Models\MemberAdventurer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use App\Support\ClubHelper;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));

        $accounts = Account::query()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);

        if ($accounts->isEmpty()) {
            $accounts = collect([
                Account::create([
                    'club_id' => $club->id,
                    'pay_to' => 'club_budget',
                    'label' => 'Club budget',
                    'balance' => 0,
                ])
            ]);
        }

        $payTo = $accounts
            ->filter(fn($a) => $a->pay_to !== 'reimbursement_to')
            ->map(function ($a) {
                return ['value' => $a->pay_to, 'label' => $a->label];
            })
            ->values();
        $clubs = \App\Models\Club::where('user_id', $request->user()->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name']);
        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'club_id',
                'pay_to',
                'payment_concept_id',
                'payee_id',
                'amount',
                'expense_date',
                'description',
                'reimbursed_to',
                'created_by_user_id',
                'status',
                'receipt_path',
                'reimbursement_receipt_path',
                'created_at',
            ]);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club_id' => $club->id,
                    'pay_to' => $payTo,
                    'accounts' => $accounts,
                    'clubs' => $clubs,
                    'expenses' => $expenses,
                ]
            ]);
        }

        return Inertia::render('ClubDirector/Expenses', [
            'club_id' => $club->id,
            'pay_to' => $payTo,
            'accounts' => $accounts,
            'clubs' => $clubs,
            'expenses' => $expenses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'pay_to' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $clubId = (int) $validated['club_id'];
        $allowedClubIds = ClubHelper::clubIdsForUser($request->user());
        if (!$allowedClubIds->contains($clubId)) {
            return response()->json(['message' => 'Unauthorized club selection.'], 403);
        }
        $club = \App\Models\Club::where('id', $clubId)->firstOrFail();

        if ($validated['pay_to'] === 'reimbursement_to') {
            return response()->json(['message' => 'Los reembolsos se generan automaticamente.'], 422);
        }

        $payeeId = null;
        $payeeName = null;

        $expense = null;
        $splitExpense = null;
        \DB::transaction(function () use ($club, $validated, $request, &$expense, &$splitExpense, $payeeId, $payeeName) {
            $account = Account::firstOrCreate(
                ['club_id' => $club->id, 'pay_to' => $validated['pay_to']],
                ['label' => $validated['pay_to'], 'balance' => 0]
            );

            $amount = (float) $validated['amount'];
            $available = max((float) $account->balance, 0.0);
            $fromAccount = $amount;
            $shortfall = 0.0;
            $reimbursementConcept = null;
            $reimburseTo = null;

            if ($amount > $available) {
                $fromAccount = $available;
                $shortfall = max($amount - $available, 0.0);

                $staff = Staff::where('user_id', $request->user()->id)
                    ->where('club_id', $club->id)
                    ->first();

                if ($staff) {
                    $reimburseTo = ClubHelper::staffDetail($staff)['name'] ?? $request->user()->name;
                    $reimbursementConcept = PaymentConcept::firstOrCreate(
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
                } else {
                    $reimburseTo = $request->user()->name ?? 'Director';
                    $reimbursementConcept = PaymentConcept::firstOrCreate(
                        [
                            'club_id' => $club->id,
                            'pay_to' => 'reimbursement_to',
                            'payee_type' => \App\Models\User::class,
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
                }
            }

            $receiptPath = null;
            if ($request->hasFile('receipt_image')) {
                $receiptPath = $request->file('receipt_image')->store('expense-receipts', 'public');
            }

            if ($fromAccount > 0) {
                $expense = Expense::create([
                    'club_id' => $club->id,
                    'pay_to' => $validated['pay_to'],
                    'payment_concept_id' => null,
                    'payee_id' => $payeeId,
                    'amount' => $fromAccount,
                    'expense_date' => $validated['expense_date'],
                    'description' => $validated['description'] ?? null,
                    'reimbursed_to' => $payeeName,
                    'created_by_user_id' => $request->user()->id,
                    'status' => $receiptPath ? 'completed' : 'working',
                    'receipt_path' => $receiptPath,
                ]);
            }

            if ($fromAccount > 0) {
                $account->decrement('balance', $fromAccount);
            }

            if ($shortfall > 0 && $reimbursementConcept) {
                $splitExpense = Expense::create([
                    'club_id' => $club->id,
                    'pay_to' => 'reimbursement_to',
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

    public function markReimbursed(Request $request, Expense $expense)
    {
        $this->ensureExpenseBelongsToUser($request->user(), $expense);

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'receipt_image' => ['required', 'image', 'max:5120'],
        ]);

        if ($expense->pay_to !== 'reimbursement_to' || $expense->status !== 'pending_reimbursement') {
            return response()->json(['message' => 'Only pending reimbursements can be marked as reimbursed.'], 422);
        }

        if ($validated['pay_to'] === 'reimbursement_to') {
            return response()->json(['message' => 'Invalid funding account.'], 422);
        }

        $account = Account::firstOrCreate(
            ['club_id' => $expense->club_id, 'pay_to' => $validated['pay_to']],
            ['label' => $validated['pay_to'], 'balance' => 0]
        );

        if ((float) $account->balance < (float) $expense->amount) {
            return response()->json([
                'message' => 'Insufficient balance to reimburse.',
                'errors' => ['pay_to' => ['Insufficient balance to reimburse.']]
            ], 422);
        }

        $receiptPath = $request->file('receipt_image')->store('reimbursement-receipts', 'public');

        if ($expense->reimbursement_receipt_path) {
            Storage::disk('public')->delete($expense->reimbursement_receipt_path);
        }

        \DB::transaction(function () use ($expense, $account, $receiptPath) {
            $account->decrement('balance', (float) $expense->amount);
            $expense->update([
                'status' => 'completed',
                'reimbursement_receipt_path' => $receiptPath,
            ]);
        });

        return response()->json([
            'message' => 'Reimbursement recorded',
            'data' => $expense->refresh(),
        ]);
    }

    protected function resolveClubForUser($user, $clubId = null)
    {
        // Allow access to:
        // - clubs owned by the user
        // - clubs linked through the pivot (club_users relation)
        // - the explicit club_id stored on the user record
        $pivotIds = $user->clubs?->pluck('id') ?? collect();
        $explicitId = $user->club_id ? collect([$user->club_id]) : collect();

        $allowed = \App\Models\Club::query()
            ->where('user_id', $user->id)
            ->orWhereIn('id', $pivotIds)
            ->orWhereIn('id', $explicitId)
            ->orderBy('club_name');

        if ($clubId) {
            $allowed->where('id', $clubId);
        }

        $club = $allowed->first();

        // Fallback: if still empty but the user has any allowed IDs, grab the first
        if (!$club) {
            $club = \App\Models\Club::query()
                ->where('user_id', $user->id)
                ->orWhereIn('id', $pivotIds)
                ->orWhereIn('id', $explicitId)
                ->firstOrFail();
        }

        return $club;
    }

    protected function ensureExpenseBelongsToUser($user, Expense $expense): void
    {
        $ownsExpenseClub = \App\Models\Club::where('user_id', $user->id)
            ->where('id', $expense->club_id)
            ->exists();

        abort_unless($ownsExpenseClub, 403, 'Unauthorized.');
    }

    protected function reimbursementBalances(int $clubId)
    {
        $concepts = PaymentConcept::query()
            ->where('club_id', $clubId)
            ->where('pay_to', 'reimbursement_to')
            ->get(['id', 'concept', 'amount', 'payee_id', 'payee_type']);

        $paymentsByConcept = Payment::query()
            ->whereIn('payment_concept_id', $concepts->pluck('id'))
            ->selectRaw('payment_concept_id, COALESCE(SUM(amount_paid),0) as total_paid')
            ->groupBy('payment_concept_id')
            ->pluck('total_paid', 'payment_concept_id');

        $expensesByConcept = Expense::query()
            ->where('pay_to', 'reimbursement_to')
            ->whereIn('payment_concept_id', $concepts->pluck('id'))
            ->where('status', '!=', 'pending_reimbursement')
            ->selectRaw('payment_concept_id, COALESCE(SUM(amount),0) as total_spent')
            ->groupBy('payment_concept_id')
            ->pluck('total_spent', 'payment_concept_id');

        return $concepts->map(function ($c) use ($paymentsByConcept, $expensesByConcept) {
            $paid = (float) ($paymentsByConcept[$c->id] ?? 0);
            $spent = (float) ($expensesByConcept[$c->id] ?? 0);
            $available = max(0, $paid - $spent);
            return [
                'id' => $c->id,
                'concept' => $c->concept,
                'payee_id' => $c->payee_id,
                'payee_type' => $c->payee_type,
                'payee_name' => $this->resolvePayeeName($c->payee_type, $c->payee_id),
                'available' => $available,
                'paid' => $paid,
                'spent' => $spent,
            ];
        });
    }

    protected function reimbursementAvailable(PaymentConcept $concept): float
    {
        $paid = (float) Payment::where('payment_concept_id', $concept->id)->sum('amount_paid');
        $spent = (float) Expense::where('payment_concept_id', $concept->id)
            ->where('status', '!=', 'pending_reimbursement')
            ->sum('amount');
        return max(0, $paid - $spent);
    }

    protected function payToOptions($clubId)
    {
        $clubPayTo = PayToOption::active()
            ->where('club_id', $clubId)
            ->orderBy('label')
            ->get(['value', 'label']);

        $globalPayTo = PayToOption::active()
            ->whereNull('club_id')
            ->whereNotIn('value', $clubPayTo->pluck('value'))
            ->orderBy('label')
            ->get(['value', 'label']);

        return $clubPayTo->concat($globalPayTo)->values();
    }

    protected function ensureAccounts($clubId, $payToOptions)
    {
        $existing = Account::query()
            ->where('club_id', $clubId)
            ->get()
            ->keyBy('pay_to');

        foreach ($payToOptions as $opt) {
            if (!$existing->has($opt->value)) {
                $account = Account::create([
                    'club_id' => $clubId,
                    'pay_to' => $opt->value,
                    'label' => $opt->label,
                    'balance' => 0,
                ]);
                $existing->put($opt->value, $account);
            } else {
                $acc = $existing->get($opt->value);
                if ($acc && $acc->label !== $opt->label) {
                    $acc->label = $opt->label;
                    $acc->save();
                }
            }
        }

        return $existing->values();
    }

    protected function resolvePayeeName(?string $type, ?int $id): ?string
    {
        if (!$type || !$id) {
            return null;
        }

        if ($type === \App\Models\User::class) {
            return \App\Models\User::where('id', $id)->value('name');
        }

        // If the new Staff/Member table was used
        if ($type === Staff::class) {
            $staff = Staff::find($id);
            if (!$staff) return null;
            if ($staff->type === 'adventurers') {
                return StaffAdventurer::where('id', $staff->id_data)->value('name');
            }
            // extend here for pathfinders or other club types when models exist
            return null;
        }

        if ($type === Member::class) {
            $member = Member::find($id);
            if (!$member) return null;
            if ($member->type === 'adventurers') {
                return MemberAdventurer::where('id', $member->id_data)->value('applicant_name');
            }
            // extend here for pathfinders or other club types when models exist
            return null;
        }

        // Fallback: legacy types
        if ($type === StaffAdventurer::class) {
            return StaffAdventurer::where('id', $id)->value('name');
        }
        if ($type === MemberAdventurer::class) {
            return MemberAdventurer::where('id', $id)->value('applicant_name');
        }

        return null;
    }
}
