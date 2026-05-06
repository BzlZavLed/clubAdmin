<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Models\Staff;
use App\Services\EventChecklistService;
use App\Services\EventFinanceService;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;

class EventParticipantController extends Controller
{
    public function __construct(
        private readonly EventChecklistService $checklistService,
        private readonly EventFinanceService $eventFinanceService,
    )
    {
    }

    protected function visibleClubIdsForUser($user, Event $event): array
    {
        $targetClubIds = $event->targetClubs()->pluck('clubs.id')->map(fn ($id) => (int) $id)->all();
        $scopeType = (string) ($event->scope_type ?: 'club');

        if ($scopeType === 'club') {
            return [(int) $event->club_id];
        }

        $role = $this->plannerRoleKey($user);

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return $targetClubIds;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $clubIds = $this->manageableClubIdsForUser($user);

            return collect($targetClubIds)
                ->filter(fn ($clubId) => in_array((int) $clubId, $clubIds, true))
                ->values()
                ->all();
        }

        return $targetClubIds;
    }

    protected function plannerRoleKey($user): ?string
    {
        if (($user->profile_type ?? null) === 'superadmin') {
            return SuperadminContext::fromSession()['role'] ?? 'superadmin';
        }

        return ClubHelper::roleKey($user);
    }

    protected function manageableClubIdsForUser($user): array
    {
        if (($user->profile_type ?? null) === 'superadmin') {
            $context = SuperadminContext::fromSession();

            return !empty($context['club_id']) ? [(int) $context['club_id']] : [];
        }

        return ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->all();
    }

    protected function canManageParticipantRoster($user, Event $event): bool
    {
        $scopeType = (string) ($event->scope_type ?: 'club');
        if ($scopeType === 'club') {
            return true;
        }

        return in_array($this->plannerRoleKey($user), ['club_director', 'club_personal'], true)
            && !empty($this->visibleClubIdsForUser($user, $event));
    }

    protected function assertParticipantManagementAccess($user, Event $event): array
    {
        abort_unless($this->canManageParticipantRoster($user, $event), 403, 'La lista de participantes se confirma desde el nivel de club.');

        $visibleClubIds = $this->visibleClubIdsForUser($user, $event);
        abort_if(empty($visibleClubIds), 403, 'No tienes acceso a participantes para este evento.');

        return $visibleClubIds;
    }

    public function index(Event $event)
    {
        $this->authorize('view', $event);
        $user = auth()->user();
        $visibleClubIds = $this->visibleClubIdsForUser($user, $event);
        $restrictToMemberClubs = ($event->scope_type ?: 'club') !== 'club'
            && in_array($this->plannerRoleKey($user), ['club_director', 'club_personal'], true);

        return response()->json([
            'participants' => $event->participants()
                ->with(['member:id,club_id', 'staff:id,club_id,user_id'])
                ->when($restrictToMemberClubs, function ($query) use ($visibleClubIds) {
                    $query->where(function ($inner) use ($visibleClubIds) {
                        $inner
                            ->whereHas('member', fn ($memberQuery) => $memberQuery->whereIn('club_id', $visibleClubIds))
                            ->orWhereHas('staff', fn ($staffQuery) => $staffQuery->whereIn('club_id', $visibleClubIds));
                    });
                })
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('view', $event);
        $visibleClubIds = $this->assertParticipantManagementAccess($request->user(), $event);

        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'participant_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'permission_received' => ['nullable', 'boolean'],
            'medical_form_received' => ['nullable', 'boolean'],
            'emergency_contact_json' => ['nullable', 'array'],
        ]);

        if (!empty($validated['member_id'])) {
            $member = Member::query()->findOrFail((int) $validated['member_id']);
            abort_unless(in_array((int) $member->club_id, $visibleClubIds, true), 403, 'Ese miembro no pertenece a un club permitido para este evento.');
        }

        if (!empty($validated['staff_id'])) {
            $staff = Staff::query()->findOrFail((int) $validated['staff_id']);
            abort_unless(in_array((int) $staff->club_id, $visibleClubIds, true), 403, 'Ese staff no pertenece a un club permitido para este evento.');
        }

        $participant = EventParticipant::create([
            'event_id' => $event->id,
            'member_id' => $validated['member_id'] ?? null,
            'staff_id' => $validated['staff_id'] ?? null,
            'participant_name' => $validated['participant_name'],
            'role' => $validated['role'],
            'status' => $validated['status'],
            'permission_received' => $validated['permission_received'] ?? false,
            'medical_form_received' => $validated['medical_form_received'] ?? false,
            'emergency_contact_json' => $validated['emergency_contact_json'] ?? null,
        ]);

        $this->checklistService->syncPermissionSlips($event);
        $this->markClubSignupFromParticipant($event, $participant);
        $this->eventFinanceService->syncPaymentConcepts($event->fresh(), $request->user()->id);

        return response()->json(['participant' => $participant]);
    }

    public function update(Request $request, EventParticipant $eventParticipant)
    {
        $event = $eventParticipant->event;
        $this->authorize('view', $event);
        $visibleClubIds = $this->assertParticipantManagementAccess($request->user(), $event);

        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'participant_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'permission_received' => ['nullable', 'boolean'],
            'medical_form_received' => ['nullable', 'boolean'],
            'emergency_contact_json' => ['nullable', 'array'],
        ]);

        if (!empty($validated['member_id'])) {
            $member = Member::query()->findOrFail((int) $validated['member_id']);
            abort_unless(in_array((int) $member->club_id, $visibleClubIds, true), 403, 'Ese miembro no pertenece a un club permitido para este evento.');
        }

        if (!empty($validated['staff_id'])) {
            $staff = Staff::query()->findOrFail((int) $validated['staff_id']);
            abort_unless(in_array((int) $staff->club_id, $visibleClubIds, true), 403, 'Ese staff no pertenece a un club permitido para este evento.');
        }

        $eventParticipant->update($validated);

        $this->checklistService->syncPermissionSlips($event);
        $this->markClubSignupFromParticipant($event, $eventParticipant->fresh());
        $this->eventFinanceService->syncPaymentConcepts($event->fresh(), $request->user()->id);

        return response()->json(['participant' => $eventParticipant]);
    }

    public function destroy(Request $request, EventParticipant $eventParticipant)
    {
        $event = $eventParticipant->event;
        $this->authorize('view', $event);
        $visibleClubIds = $this->assertParticipantManagementAccess($request->user(), $event);

        if ($eventParticipant->member_id) {
            $member = Member::query()->find($eventParticipant->member_id);
            abort_if($member && !in_array((int) $member->club_id, $visibleClubIds, true), 403);
        }

        if ($eventParticipant->staff_id) {
            $staff = Staff::query()->find($eventParticipant->staff_id);
            abort_if($staff && !in_array((int) $staff->club_id, $visibleClubIds, true), 403);
        }

        $eventParticipant->delete();

        $this->checklistService->syncPermissionSlips($event);
        $this->eventFinanceService->syncPaymentConcepts($event->fresh(), $request->user()->id);

        return response()->json(['deleted' => true]);
    }

    protected function markClubSignupFromParticipant(Event $event, EventParticipant $participant): void
    {
        if (!$participant->member_id) {
            if (!$participant->staff_id) {
                return;
            }

            $staff = Staff::query()->find($participant->staff_id);
            if (!$staff?->club_id) {
                return;
            }

            $event->targetClubs()->updateExistingPivot((int) $staff->club_id, [
                'signup_status' => 'signed_up',
                'signed_up_at' => now(),
            ]);

            return;
        }

        $member = Member::query()->find($participant->member_id);
        if (!$member?->club_id) {
            return;
        }

        $event->targetClubs()->updateExistingPivot((int) $member->club_id, [
            'signup_status' => 'signed_up',
            'signed_up_at' => now(),
        ]);
    }
}
