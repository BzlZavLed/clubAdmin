<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\BankInfo;
use App\Models\TreasuryMovement;
use App\Services\ClubLogoService;
use App\Services\ClubTreasuryService;
use App\Services\DocumentValidationService;
use App\Services\EventClubSettlementService;
use App\Services\EventFinanceService;
use App\Support\BankInfoFormatter;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventClubSettlementController extends Controller
{
    public function __construct(
        private readonly EventClubSettlementService $settlementService,
        private readonly ClubTreasuryService $treasuryService,
    )
    {
    }

    public function indexForClub(Request $request, EventFinanceService $financeService)
    {
        $user = $request->user();
        $club = ClubHelper::clubForUser($user, $request->input('club_id'));

        abort_unless($this->canManageSettlementForClub($user, $club), 403);

        $events = Event::query()
            ->where('is_payable', true)
            ->whereHas('targetClubs', fn ($query) => $query->where('clubs.id', $club->id))
            ->with([
                'feeComponents',
                'targetClubs' => fn ($query) => $query
                    ->where('clubs.id', $club->id)
                    ->with('district:id,name,association_id'),
            ])
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $rows = $events
            ->map(function (Event $event) use ($club, $financeService) {
                $summary = collect($financeService->clubSignupSummary($event))
                    ->firstWhere('club_id', $club->id);

                if (!$summary) {
                    return null;
                }

                $hasPending = (float) ($summary['pending_settlement_amount'] ?? 0) > 0;
                $hasReceipts = !empty($summary['settlement_receipts'] ?? []);

                if (!$hasPending && !$hasReceipts) {
                    return null;
                }

                $paidMembers = $financeService->paidMemberSummary($event, (int) $club->id);

                return [
                    'event_id' => (int) $event->id,
                    'event_title' => $event->title,
                    'event_start_at' => optional($event->start_at)->toDateTimeString(),
                    'organizer_label' => $this->organizerLabelForEvent($event),
                    'organizer_bank_info' => $this->organizerBankInfoForEvent($event),
                    'club_id' => (int) $club->id,
                    'club_name' => $club->club_name,
                    'pending_settlement_amount' => (float) ($summary['pending_settlement_amount'] ?? 0),
                    'pending_settlement_breakdown' => $summary['pending_settlement_breakdown'] ?? [],
                    'deposited_amount' => (float) ($summary['deposited_amount'] ?? 0),
                    'settlement_receipts' => $summary['settlement_receipts'] ?? [],
                    'paid_members_count' => count($paidMembers),
                    'paid_members_total' => (float) collect($paidMembers)->sum('total_paid'),
                    'paid_members' => $paidMembers,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'club' => [
                'id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'data' => $rows,
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('view', $event);

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

        $summary = collect(app(\App\Services\EventFinanceService::class)->clubSignupSummary($event))
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

        $depositProofPath = null;
        $depositProofOriginalName = null;
        if ($request->hasFile('deposit_proof')) {
            $file = $request->file('deposit_proof');
            $depositProofPath = $file->store('event-settlements/proofs', 'public');
            $depositProofOriginalName = $file->getClientOriginalName();
        }

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

        $message = "Settlement receipt {$settlement->receipt_number} generated.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => [
                    'id' => (int) $settlement->id,
                    'receipt_number' => $settlement->receipt_number,
                    'receipt_url' => route('event-club-settlements.download', $settlement),
                ],
            ], 201);
        }

        return back()->with('success', $message);
    }

    public function download(Request $request, EventClubSettlement $settlement, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $settlement->loadMissing([
            'event:id,title,scope_type,scope_id',
            'club:id,club_name,church_name,logo_path',
            'creator:id,name,email',
        ]);

        $this->authorizeSettlement($request->user(), $settlement);

        $settlement->update(['last_downloaded_at' => now()]);

        return $this->makePdf($settlement, $documentValidationService, $clubLogoService, $request->user())
            ->download("{$settlement->receipt_number}.pdf");
    }

    protected function authorizeSettlement($user, EventClubSettlement $settlement): void
    {
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            return;
        }

        if ($this->canManageSettlementForClub($user, $settlement->club)) {
            return;
        }

        $event = $settlement->event;
        if ($event && app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($user)->allows('view', $event)) {
            return;
        }

        abort(403);
    }

    protected function canManageSettlementForClub($user, Club $club): bool
    {
        $role = ClubHelper::roleKey($user);
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id);

        if ($role === 'superadmin') {
            return $allowedClubIds->contains((int) $club->id);
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            return $allowedClubIds->contains((int) $club->id);
        }

        return false;
    }

    protected function organizerLabelForEvent(Event $event): string
    {
        $settlement = new EventClubSettlement([
            'organizer_scope_type' => (string) ($event->scope_type ?: 'club'),
            'organizer_scope_id' => (int) ($event->scope_id ?: $event->club_id),
        ]);

        return $this->settlementService->organizerLabel($settlement);
    }

    protected function organizerBankInfoForEvent(Event $event): ?array
    {
        $scopeType = (string) ($event->scope_type ?: 'club');
        $scopeId = (int) ($event->scope_id ?: $event->club_id);

        [$bankableType, $payTo] = match ($scopeType) {
            'union' => [\App\Models\Union::class, 'union_budget'],
            'association' => [\App\Models\Association::class, 'association_budget'],
            'district' => [\App\Models\District::class, 'district_budget'],
            'church' => [\App\Models\Church::class, 'church_budget'],
            default => [Club::class, 'club_budget'],
        };

        $bankInfo = BankInfo::query()
            ->where('bankable_type', $bankableType)
            ->where('bankable_id', $scopeId)
            ->where('pay_to', $payTo)
            ->where('is_active', true)
            ->first();

        return BankInfoFormatter::payload($bankInfo);
    }

    protected function makePdf(EventClubSettlement $settlement, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService, $generatedBy = null)
    {
        $club = $settlement->club;
        $event = $settlement->event;
        $organizerLabel = $this->settlementService->organizerLabel($settlement);
        $generatedAt = now();

        $validation = $documentValidationService->create(
            documentType: 'event_club_settlement_receipt',
            title: 'Event settlement receipt',
            snapshot: [
                'settlement_id' => $settlement->id,
                'receipt_number' => $settlement->receipt_number,
                'event_id' => $event?->id,
                'event_title' => $event?->title,
                'club_id' => $club?->id,
                'club_name' => $club?->club_name,
                'organizer_scope_type' => $settlement->organizer_scope_type,
                'organizer_scope_id' => $settlement->organizer_scope_id,
                'amount' => $settlement->amount,
                'breakdown' => $settlement->breakdown_json,
                'deposited_at' => optional($settlement->deposited_at)->toISOString(),
                'reference' => $settlement->reference,
            ],
            metadata: [
                'Receipt' => $settlement->receipt_number,
                'Club' => $club?->club_name ?? '—',
                'Event' => $event?->title ?? '—',
                'Organizer' => $organizerLabel,
                'Amount' => '$' . number_format((float) $settlement->amount, 2),
            ],
            generatedBy: $generatedBy,
            generatedAt: $generatedAt,
        );

        return Pdf::loadView('pdf.event_club_settlement_receipt', [
            'settlement' => $settlement,
            'club' => $club,
            'event' => $event,
            'organizerLabel' => $organizerLabel,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4');
    }
}
