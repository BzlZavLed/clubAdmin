<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Expense;
use App\Models\PayToOption;
use App\Models\Payment;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index(Request $request, $club)
    {
        $clubId = (int) $club;
        $allowed = ClubHelper::clubIdsForUser($request->user());
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');

        $accounts = Account::query()
            ->where('club_id', $clubId)
            ->orderBy('label')
            ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request, $club)
    {
        $clubId = (int) $club;
        $allowed = ClubHelper::clubIdsForUser($request->user());
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');

        $validated = $request->validate([
            'pay_to' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = Account::query()
            ->where('club_id', $clubId)
            ->where('pay_to', $validated['pay_to'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Account key already exists.'], 422);
        }

        $account = Account::create([
            'club_id' => $clubId,
            'pay_to' => $validated['pay_to'],
            'label' => $validated['label'] ?: $validated['pay_to'],
            'balance' => 0,
        ]);

        return response()->json(['data' => $account], 201);
    }

    public function update(Request $request, $club, Account $account)
    {
        $clubId = (int) $club;
        $allowed = ClubHelper::clubIdsForUser($request->user());
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');
        abort_unless((int) $account->club_id === $clubId, 404);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
        ]);

        $account->update(['label' => $validated['label']]);

        return response()->json(['data' => $account->fresh()]);
    }

    public function destroy(Request $request, $club, Account $account)
    {
        $clubId = (int) $club;
        $allowed = ClubHelper::clubIdsForUser($request->user());
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');
        abort_unless((int) $account->club_id === $clubId, 404);

        if ((float) $account->balance !== 0.0) {
            return response()->json(['message' => 'Account must have zero balance to delete.'], 422);
        }

        $account->delete();

        return response()->json(['message' => 'Account deleted']);
    }

    public function recalculate(Request $request, $club)
    {
        $clubId = (int) $club;
        $allowed = ClubHelper::clubIdsForUser($request->user());
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');

        $accounts = DB::transaction(function () use ($clubId) {
            $clubPayTo = PayToOption::active()
                ->where('club_id', $clubId)
                ->get(['value', 'label'])
                ->keyBy('value');

            $globalPayTo = PayToOption::active()
                ->whereNull('club_id')
                ->whereNotIn('value', $clubPayTo->keys())
                ->get(['value', 'label'])
                ->keyBy('value');

            $labelMap = $clubPayTo
                ->concat($globalPayTo)
                ->map
                ->label
                ->all();

            $existingLabels = Account::query()
                ->where('club_id', $clubId)
                ->pluck('label', 'pay_to');

            $paymentSums = Payment::query()
                ->where('club_id', $clubId)
                ->selectRaw("COALESCE(pay_to, 'unassigned') as pay_to, COALESCE(SUM(amount_paid), 0) as total")
                ->groupBy('pay_to')
                ->pluck('total', 'pay_to');

            $expenseSums = Expense::query()
                ->where('club_id', $clubId)
                ->where(fn ($q) => $q
                    ->where('pay_to', '!=', 'reimbursement_to')
                    ->orWhere('status', 'pending_reimbursement')
                )
                ->selectRaw("COALESCE(pay_to, 'unassigned') as pay_to, COALESCE(SUM(amount), 0) as total")
                ->groupBy('pay_to')
                ->pluck('total', 'pay_to');

            $payToKeys = $paymentSums->keys()
                ->merge($expenseSums->keys())
                ->merge(array_keys($labelMap))
                ->merge($existingLabels->keys())
                ->filter()
                ->unique()
                ->values();

            foreach ($payToKeys as $payTo) {
                $entries = (float) ($paymentSums[$payTo] ?? 0);
                $expenses = (float) ($expenseSums[$payTo] ?? 0);
                $balance = round($entries - $expenses, 2);
                $label = $existingLabels[$payTo] ?? $labelMap[$payTo] ?? $payTo;

                Account::updateOrCreate(
                    ['club_id' => $clubId, 'pay_to' => $payTo],
                    ['label' => $label, 'balance' => $balance]
                );
            }

            return Account::query()
                ->where('club_id', $clubId)
                ->orderBy('label')
                ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);
        });

        return response()->json([
            'message' => 'Account balances recalculated.',
            'data' => $accounts,
        ]);
    }
}
