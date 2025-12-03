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

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $club = $this->resolveClubForUser($request->user(), $request->input('club_id'));

        $payTo = $this->payToOptions($club->id);
        $accounts = $this->ensureAccounts($club->id, $payTo);
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
                'created_at',
            ]);

        $reimbursementConcepts = $this->reimbursementBalances($club->id);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club_id' => $club->id,
                    'pay_to' => $payTo,
                    'accounts' => $accounts,
                    'clubs' => $clubs,
                    'expenses' => $expenses,
                    'reimbursements' => $reimbursementConcepts,
                ]
            ]);
        }

        return Inertia::render('ClubDirector/Expenses', [
            'club_id' => $club->id,
            'pay_to' => $payTo,
            'accounts' => $accounts,
            'clubs' => $clubs,
            'expenses' => $expenses,
            'reimbursements' => $reimbursementConcepts,
        ]);
    }

    public function store(Request $request)
    {
        $club = $this->resolveClubForUser($request->user(), $request->input('club_id'));

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'payment_concept_id' => ['nullable', 'integer', 'exists:payment_concepts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reimbursed_to' => ['nullable', 'string', 'max:255'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($validated['pay_to'] === 'reimbursement_to' && empty($validated['reimbursed_to'])) {
            return response()->json(['message' => 'Please enter who is being reimbursed.'], 422);
        }

        $concept = null;
        $payeeId = null;
        $payeeName = null;
        if ($validated['pay_to'] === 'reimbursement_to') {
            if (empty($validated['payment_concept_id'])) {
                return response()->json(['message' => 'Select a reimbursement concept.'], 422);
            }
            $concept = PaymentConcept::where('id', $validated['payment_concept_id'])
                ->where('club_id', $club->id)
                ->where('pay_to', 'reimbursement_to')
                ->first();
            if (!$concept) {
                return response()->json(['message' => 'Invalid reimbursement concept.'], 422);
            }
            $payeeId = $concept->payee_id;
            $payeeName = $this->resolvePayeeName($concept->payee_type, $concept->payee_id);

            $available = $this->reimbursementAvailable($concept);
            if ($validated['amount'] > $available) {
                return response()->json([
                    'message' => 'Amount exceeds available reimbursement balance.',
                    'errors' => ['amount' => ['Amount exceeds available reimbursement balance.']],
                ], 422);
            }
        }

        $expense = null;
        \DB::transaction(function () use ($club, $validated, $request, &$expense, $payeeId) {
            $account = Account::firstOrCreate(
                ['club_id' => $club->id, 'pay_to' => $validated['pay_to']],
                ['label' => $validated['pay_to'], 'balance' => 0]
            );

            if ($account->balance < $validated['amount']) {
                abort(response()->json([
                    'message' => 'Insufficient balance for this account.',
                    'errors' => ['amount' => ['Amount exceeds account balance.']]
                ], 422));
            }

            $receiptPath = null;
            if ($request->hasFile('receipt_image')) {
                $receiptPath = $request->file('receipt_image')->store('expense-receipts', 'public');
            }

            $expense = Expense::create([
                'club_id' => $club->id,
                'pay_to' => $validated['pay_to'],
                'payment_concept_id' => $validated['payment_concept_id'] ?? null,
                'payee_id' => $payeeId,
                'amount' => $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'description' => $validated['description'] ?? null,
                'reimbursed_to' => $validated['reimbursed_to'] ?? $payeeName,
                'created_by_user_id' => $request->user()->id,
                'status' => $receiptPath ? 'completed' : 'working',
                'receipt_path' => $receiptPath,
            ]);

            $account->decrement('balance', $validated['amount']);
        });

        return response()->json([
            'message' => 'Expense recorded',
            'data' => $expense,
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

    protected function resolveClubForUser($user, $clubId = null)
    {
        $query = \App\Models\Club::where('user_id', $user->id);
        if ($clubId) {
            $query->where('id', $clubId);
        }

        $club = $query->first();

        if (!$club) {
            $club = \App\Models\Club::where('user_id', $user->id)->firstOrFail();
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
        $spent = (float) Expense::where('payment_concept_id', $concept->id)->sum('amount');
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
