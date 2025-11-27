<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PayToOption;
use App\Models\Account;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $club = $this->resolveClubFromUser($request->user());

        $payTo = $this->payToOptions($club->id);
        $accounts = $this->ensureAccounts($club->id, $payTo);

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get(['id', 'club_id', 'pay_to', 'amount', 'expense_date', 'description', 'created_by_user_id']);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'pay_to' => $payTo,
                    'accounts' => $accounts,
                    'expenses' => $expenses,
                ]
            ]);
        }

        return Inertia::render('ClubDirector/Expenses', [
            'pay_to' => $payTo,
            'accounts' => $accounts,
            'expenses' => $expenses,
        ]);
    }

    public function store(Request $request)
    {
        $club = $this->resolveClubFromUser($request->user());

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reimbursed_to' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['pay_to'] === 'reimbursement_to' && empty($validated['reimbursed_to'])) {
            return response()->json(['message' => 'Please enter who is being reimbursed.'], 422);
        }

        $expense = null;
        \DB::transaction(function () use ($club, $validated, $request, &$expense) {
            $expense = Expense::create([
                'club_id' => $club->id,
                'pay_to' => $validated['pay_to'],
                'amount' => $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'description' => $validated['description'] ?? null,
                'reimbursed_to' => $validated['reimbursed_to'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);

            $account = Account::firstOrCreate(
                ['club_id' => $club->id, 'pay_to' => $validated['pay_to']],
                ['label' => $validated['pay_to'], 'balance' => 0]
            );
            $account->decrement('balance', $validated['amount']);
        });

        return response()->json([
            'message' => 'Expense recorded',
            'data' => $expense,
        ], 201);
    }

    protected function resolveClubFromUser($user)
    {
        return \App\Models\Club::where('id', $user->club_id)->firstOrFail();
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
}
