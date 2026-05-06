<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankInfo;
use App\Models\Club;
use App\Support\BankInfoFormatter;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class BankInfoController extends Controller
{
    public function clubIndex(Request $request, $club)
    {
        $club = $this->resolveAllowedClub($request, (int) $club);

        $account = Account::query()
            ->where('club_id', $club->id)
            ->where('pay_to', 'club_budget')
            ->first(['pay_to', 'label']);

        $bankInfo = BankInfo::query()
            ->where('bankable_type', Club::class)
            ->where('bankable_id', $club->id)
            ->where('pay_to', 'club_budget')
            ->first();

        return response()->json([
            'data' => [[
                'pay_to' => 'club_budget',
                'label' => $bankInfo?->label ?: $account?->label ?: 'Presupuesto del club',
                'bank_info' => BankInfoFormatter::payload($bankInfo),
            ]],
        ]);
    }

    public function clubUpdate(Request $request, $club, string $payTo)
    {
        $club = $this->resolveAllowedClub($request, (int) $club);
        abort_unless($payTo === 'club_budget', 422, 'El club solo puede registrar una cuenta bancaria para club_budget.');

        $validated = $this->validatedBankInfo($request);

        $bankInfo = BankInfo::query()->updateOrCreate(
            [
                'bankable_type' => Club::class,
                'bankable_id' => $club->id,
                'pay_to' => $payTo,
            ],
            [
                ...$validated,
                'pay_to' => $payTo,
            ],
        );

        return response()->json([
            'message' => 'Bank info saved.',
            'data' => BankInfoFormatter::payload($bankInfo->fresh()),
        ]);
    }

    protected function resolveAllowedClub(Request $request, int $clubId): Club
    {
        $allowed = ClubHelper::clubIdsForUser($request->user())->map(fn ($id) => (int) $id);
        abort_unless($allowed->contains($clubId), 403, 'Unauthorized.');

        return Club::withoutGlobalScopes()->findOrFail($clubId);
    }

    protected function validatedBankInfo(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', 'string', 'max:80'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'routing_number' => ['nullable', 'string', 'max:255'],
            'zelle_email' => ['nullable', 'email', 'max:255'],
            'zelle_phone' => ['nullable', 'string', 'max:40'],
            'deposit_instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'accepts_parent_deposits' => ['boolean'],
            'accepts_event_deposits' => ['boolean'],
            'requires_receipt_upload' => ['boolean'],
        ]);
    }
}
