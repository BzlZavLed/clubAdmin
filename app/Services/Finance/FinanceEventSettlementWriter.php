<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\TreasuryMovement;
use App\Services\ClubTreasuryService;
use App\Services\EventClubSettlementService;
use App\Services\EventFinanceService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinanceEventSettlementWriter
{
    public function __construct(
        private readonly EventClubSettlementService $settlementService,
        private readonly ClubTreasuryService $treasuryService,
        private readonly EventFinanceService $eventFinanceService,
    ) {
    }

    public function store(Request $request, Event $event)
    {
        $request->user()->can('view', $event) || abort(403);

        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'deposited_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'deposit_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $club = Club::query()->findOrFail((int) $validated['club_id']);
        abort_unless($event->targetClubs()->where('clubs.id', $club->id)->exists(), 422);
        abort_unless($this->canManageSettlementForClub($request->user(), $club), 403);

        $summary = collect($this->eventFinanceService->clubSignupSummary($event))
            ->firstWhere('club_id', $club->id);

        abort_unless($summary, 422, 'Settlement summary not available for this club.');
        abort_if((float) ($summary['pending_settlement_amount'] ?? 0) <= 0, 422, 'This club has no collected balance pending organizer deposit.');

        if (!$this->treasuryService->hasClubBankInfo($club)) {
            abort(422, 'Register the club bank account before transferring event money.');
        }

        $treasurySummary = $this->treasuryService->summary($club);
        $settlementAmount = (float) ($summary['pending_settlement_amount'] ?? 0);
        $clubBudgetSummary = collect($treasurySummary['accounts'] ?? [])->firstWhere('account', 'club_budget') ?: ['bank_balance' => 0];
        abort_if((float) $clubBudgetSummary['bank_balance'] + 0.0001 < $settlementAmount, 422, 'Bank balance is not enough for this event transfer. Deposit cash to bank first if needed.');

        [$depositProofPath, $depositProofOriginalName] = $this->storeDepositProof($request);

        $settlement = $this->settlementService->recordSettlement(
            $event,
            $club,
            (int) $request->user()->id,
            $summary['pending_settlement_breakdown'] ?? [],
            $settlementAmount,
            Carbon::parse($validated['deposited_at']),
            $validated['reference'] ?? null,
            $validated['notes'] ?? null,
            $depositProofPath,
            $depositProofOriginalName,
        );

        TreasuryMovement::query()->create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'created_by_user_id' => $request->user()?->id,
            'movement_type' => TreasuryMovement::TYPE_EVENT_SETTLEMENT,
            'from_location' => TreasuryMovement::LOCATION_BANK,
            'to_location' => TreasuryMovement::LOCATION_EXTERNAL,
            'amount' => $settlementAmount,
            'movement_date' => Carbon::parse($validated['deposited_at'])->toDateString(),
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $depositProofPath,
            'proof_original_name' => $depositProofOriginalName,
            'event_id' => $event->id,
            'event_club_settlement_id' => $settlement->id,
        ]);

        return response()->json([
            'message' => "Settlement receipt {$settlement->receipt_number} generated.",
            'data' => [
                'id' => (int) $settlement->id,
                'receipt_number' => $settlement->receipt_number,
                'receipt_url' => route('event-club-settlements.download', $settlement),
            ],
        ], 201);
    }

    private function canManageSettlementForClub($user, Club $club): bool
    {
        $role = ClubHelper::roleKey($user);
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id);

        if ($role === 'superadmin') {
            return $allowedClubIds->contains((int) $club->id);
        }

        if (in_array($role, ['club_director', 'club_personal', 'treasurer'], true)) {
            return $allowedClubIds->contains((int) $club->id);
        }

        return false;
    }

    private function storeDepositProof(Request $request): array
    {
        if (!$request->hasFile('deposit_proof')) {
            return [null, null];
        }

        $file = $request->file('deposit_proof');

        return [
            $file->store('event-settlements/proofs', 'public'),
            $file->getClientOriginalName(),
        ];
    }
}
