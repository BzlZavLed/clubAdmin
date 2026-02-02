<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

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
}
