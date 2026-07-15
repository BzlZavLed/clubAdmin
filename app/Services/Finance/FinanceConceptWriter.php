<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\PaymentConcept;
use App\Models\User;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceConceptWriter
{
    public function store(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));
        $payload = $this->validateConcept($request);

        $payload['pay_to'] = $this->normalizePayTo($payload['pay_to'] ?? null);
        [$payload['payee_type'], $payload['payee_id']] = $this->normalizePayee(
            $payload['pay_to'] ?? null,
            $payload['payee_type'] ?? null,
            $payload['payee_id'] ?? null
        );

        $this->assertScopeCoherence($payload['scopes'] ?? []);
        $this->assertAccountPayTo($club->id, $payload['pay_to'] ?? null);

        return DB::transaction(function () use ($payload, $request, $club) {
            $concept = PaymentConcept::query()->create([
                'concept' => $payload['concept'],
                'payment_expected_by' => $payload['payment_expected_by'] ?? null,
                'amount' => $payload['amount'],
                'reusable' => (bool) ($payload['reusable'] ?? false),
                'type' => $payload['type'],
                'pay_to' => $payload['pay_to'],
                'payee_type' => $payload['payee_type'] ?? null,
                'payee_id' => $payload['payee_id'] ?? null,
                'created_by' => $request->user()->id,
                'status' => $payload['status'],
                'club_id' => $club->id,
            ]);

            foreach ($payload['scopes'] as $scope) {
                $concept->scopes()->create([
                    'scope_type' => $scope['scope_type'],
                    'club_id' => $scope['club_id'] ?? null,
                    'class_id' => $scope['class_id'] ?? null,
                    'member_id' => $scope['member_id'] ?? null,
                    'staff_id' => $scope['staff_id'] ?? null,
                ]);
            }

            return response()->json([
                'data' => $concept->load([
                    'createdBy:id,name',
                    'club:id,club_name',
                    'scopes',
                    'scopes.club:id,club_name',
                    'scopes.class:id,class_name',
                    'scopes.member:id,applicant_name',
                    'scopes.staff:id,name',
                ]),
            ], 201);
        });
    }

    private function validateConcept(Request $request): array
    {
        return $request->validate([
            'concept' => ['required', 'string', 'max:255'],
            'payment_expected_by' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'reusable' => ['sometimes', 'boolean'],
            'type' => ['required', Rule::in(['mandatory', 'optional'])],
            'pay_to' => ['required', 'string', 'max:255'],
            'payee_type' => ['nullable', 'string', 'max:255'],
            'payee_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*.scope_type' => ['required_with:scopes', Rule::in(['club_wide', 'class', 'member', 'member_excluded', 'staff_wide', 'staff'])],
            'scopes.*.club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'scopes.*.class_id' => ['nullable', 'integer', 'exists:club_classes,id'],
            'scopes.*.member_id' => ['nullable', 'integer', 'exists:members,id'],
            'scopes.*.staff_id' => ['nullable', 'integer', 'exists:staff,id'],
        ]);
    }

    private function normalizePayTo(?string $payTo): ?string
    {
        return $payTo === 'reinbursement_to' ? 'reimbursement_to' : $payTo;
    }

    private function assertAccountPayTo(int $clubId, ?string $payTo): void
    {
        if (!$payTo) {
            abort(422, 'Invalid pay_to.');
        }

        $exists = Account::query()
            ->where('club_id', $clubId)
            ->where('pay_to', $payTo)
            ->exists();

        if (!$exists) {
            abort(422, "Account '{$payTo}' does not exist for this club.");
        }
    }

    private function normalizePayee(?string $payTo, ?string $type, $id): array
    {
        if ($payTo !== 'reimbursement_to' || !$type || !$id) {
            return [null, null];
        }

        $map = [
            'StaffAdventurer' => \App\Models\Staff::class,
            'MemberAdventurer' => \App\Models\Member::class,
            'Staff' => \App\Models\Staff::class,
            'Member' => \App\Models\Member::class,
            'User' => User::class,
        ];

        return [$map[$type] ?? $type, $id];
    }

    private function assertScopeCoherence(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $type = $scope['scope_type'] ?? null;
            $valid = match ($type) {
                'club_wide' => !empty($scope['club_id']),
                'class' => !empty($scope['class_id']),
                'member', 'member_excluded' => !empty($scope['member_id']),
                'staff_wide' => !empty($scope['club_id']),
                'staff' => !empty($scope['staff_id']),
                default => false,
            };

            if (!$valid) {
                abort(422, "Invalid scope payload for scope_type '{$type}'");
            }
        }
    }
}
