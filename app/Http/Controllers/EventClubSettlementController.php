<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use App\Services\EventClubSettlementService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventClubSettlementController extends Controller
{
    public function __construct(private readonly EventClubSettlementService $settlementService)
    {
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'deposited_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $club = Club::query()->findOrFail((int) $validated['club_id']);
        abort_unless($event->targetClubs()->where('clubs.id', $club->id)->exists(), 422);
        abort_unless($this->canManageSettlementForClub($request->user(), $club), 403);

        $summary = collect(app(\App\Services\EventFinanceService::class)->clubSignupSummary($event))
            ->firstWhere('club_id', $club->id);

        abort_unless($summary, 422, 'Settlement summary not available for this club.');
        abort_if((float) ($summary['pending_settlement_amount'] ?? 0) <= 0, 422, 'This club has no collected balance pending organizer deposit.');

        $settlement = $this->settlementService->recordSettlement(
            $event,
            $club,
            (int) $request->user()->id,
            $summary['pending_settlement_breakdown'] ?? [],
            (float) $summary['pending_settlement_amount'],
            Carbon::parse($validated['deposited_at']),
            $validated['reference'] ?? null,
            $validated['notes'] ?? null,
        );

        return back()->with('success', "Settlement receipt {$settlement->receipt_number} generated.");
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

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            return ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->contains((int) $club->id);
        }

        return false;
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
