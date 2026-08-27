<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Member;
use App\Models\MemberPathfinder;
use App\Models\ParentChildLinkRequest;
use App\Models\User;
use App\Services\ParentChildIdentityMatcher;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ClubEnrollmentConfirmationController extends Controller
{
    public function __construct(private readonly ParentChildIdentityMatcher $identityMatcher)
    {
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function confirmParent(Request $request, User $user)
    {
        $this->assertDirectorCanConfirm($request, (int) $user->club_id);
        abort_unless(
            $user->profile_type === 'parent'
                && $user->secure_enrollment_link_id
                && !$user->enrollment_confirmed_at,
            422,
            'This parent account no longer requires confirmation.'
        );

        $user->forceFill([
            'enrollment_confirmed_at' => now(),
            'enrollment_confirmed_by' => $request->user()->id,
            'parent_activation_method' => 'director',
        ])->save();

        return response()->json(['data' => $this->payload($request)]);
    }

    public function resetParentPassword(Request $request, User $user)
    {
        $this->assertDirectorCanConfirm($request, (int) $user->club_id);
        abort_unless($user->profile_type === 'parent' && $user->isDirectorActivatedParent(), 422);

        $payload = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($payload['password']),
            'must_change_password' => false,
        ])->save();

        return response()->json(['message' => 'Parent password updated.']);
    }

    public function approveChildLink(Request $request, ParentChildLinkRequest $linkRequest)
    {
        $this->assertDirectorCanConfirm($request, (int) $linkRequest->club_id);
        $this->assertPendingChildLinkRequest($linkRequest);

        $parent = $linkRequest->parentUser()->firstOrFail();
        $detail = $this->identityMatcher->detail($linkRequest->member_type, (int) $linkRequest->id_data);
        abort_unless($detail, 404, 'The child record no longer exists.');
        $evaluation = $this->identityMatcher->evaluate($parent, $detail);
        abort_unless($evaluation['matched_count'] >= 2, 422, 'This request no longer has two matching identity factors.');

        $member = Member::query()
            ->whereIn('type', $linkRequest->member_type === 'adventurers'
                ? ['adventurers']
                : ['pathfinders', 'temp_pathfinder'])
            ->where('id_data', $detail->id)
            ->first();
        abort_if(
            $member?->parent_id && (int) $member->parent_id !== (int) $parent->id,
            409,
            'This child is already linked to another parent account.'
        );

        DB::transaction(function () use ($request, $linkRequest, $parent, $detail, $evaluation, &$member) {
            $member ??= Member::query()->create([
                'type' => $linkRequest->member_type,
                'id_data' => $detail->id,
                'club_id' => $detail->club_id,
                'class_id' => null,
                'assigned_staff_id' => null,
                'status' => 'active',
            ]);
            $member->forceFill(['parent_id' => $parent->id])->save();
            if ($detail instanceof MemberPathfinder && !$detail->member_id) {
                $detail->update(['member_id' => $member->id]);
            }
            $linkRequest->forceFill([
                'member_id' => $member->id,
                'status' => 'approved',
                'match_factors' => $evaluation['factors'],
                'matched_count' => $evaluation['matched_count'],
                'identity_snapshot' => $evaluation['snapshot'],
                'decided_at' => now(),
                'decided_by_user_id' => $request->user()->id,
                'decision_note' => null,
            ])->save();
        });

        return response()->json(['data' => $this->payload($request)]);
    }

    public function rejectChildLink(Request $request, ParentChildLinkRequest $linkRequest)
    {
        $this->assertDirectorCanConfirm($request, (int) $linkRequest->club_id);
        $this->assertPendingChildLinkRequest($linkRequest);
        $validated = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']]);

        $linkRequest->forceFill([
            'status' => 'rejected',
            'decided_at' => now(),
            'decided_by_user_id' => $request->user()->id,
            'decision_note' => $validated['decision_note'] ?? null,
        ])->save();

        return response()->json(['data' => $this->payload($request)]);
    }

    public function payload(Request $request): array
    {
        $clubIds = ClubHelper::clubIdsForUser($request->user());
        if ($clubIds->isEmpty()) {
            return ['total' => 0, 'parents' => [], 'director_activated_parents' => [], 'child_link_requests' => []];
        }

        ParentChildLinkRequest::query()
            ->whereIn('club_id', $clubIds)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'decided_at' => now()]);

        $clubs = Club::query()->whereIn('id', $clubIds)->pluck('club_name', 'id');
        $parents = User::query()
            ->where('profile_type', 'parent')
            ->whereIn('club_id', $clubIds)
            ->whereNotNull('secure_enrollment_link_id')
            ->whereNull('enrollment_confirmed_at')
            ->where('status', 'active')
            ->oldest()
            ->get(['id', 'name', 'email', 'club_id', 'created_at'])
            ->map(fn (User $parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
                'club_id' => $parent->club_id,
                'club_name' => $clubs[$parent->club_id] ?? '—',
                'email_status' => 'waiting',
                'requested_at' => $parent->created_at?->toIso8601String(),
            ])
            ->values();
        $directorActivatedParents = User::query()
            ->where('profile_type', 'parent')
            ->whereIn('club_id', $clubIds)
            ->where('parent_activation_method', 'director')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'club_id'])
            ->map(fn (User $parent) => [
                'id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
                'club_id' => $parent->club_id,
                'club_name' => $clubs[$parent->club_id] ?? '—',
            ])
            ->values();
        $childLinkRequests = ParentChildLinkRequest::query()
            ->with(['parentUser:id,name,email,email_verified_at', 'club:id,club_name'])
            ->whereIn('club_id', $clubIds)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->oldest('requested_at')
            ->get()
            ->map(function (ParentChildLinkRequest $linkRequest) {
                $detail = $this->identityMatcher->detail($linkRequest->member_type, (int) $linkRequest->id_data);

                return [
                    'id' => $linkRequest->id,
                    'parent_name' => $linkRequest->parentUser?->name,
                    'parent_email' => $linkRequest->parentUser?->email,
                    'parent_email_verified' => (bool) $linkRequest->parentUser?->email_verified_at,
                    'child_name' => $detail?->applicant_name ?: data_get($linkRequest->identity_snapshot, 'member_name', '—'),
                    'club_name' => $linkRequest->club?->club_name,
                    'matched_count' => $linkRequest->matched_count,
                    'match_factors' => $linkRequest->match_factors,
                    'requested_at' => $linkRequest->requested_at?->toIso8601String(),
                    'expires_at' => $linkRequest->expires_at?->toIso8601String(),
                ];
            })
            ->values();

        return [
            'total' => $parents->count(),
            'parents' => $parents,
            'director_activated_parents' => $directorActivatedParents,
            'child_link_requests' => $childLinkRequests,
        ];
    }

    private function assertPendingChildLinkRequest(ParentChildLinkRequest $linkRequest): void
    {
        if ($linkRequest->status === 'pending' && $linkRequest->expires_at?->isPast()) {
            $linkRequest->forceFill(['status' => 'expired', 'decided_at' => now()])->save();
        }

        abort_unless($linkRequest->status === 'pending', 422, 'This linking request is no longer pending.');
    }

    private function assertDirectorCanConfirm(Request $request, int $clubId): void
    {
        abort_unless(ClubHelper::clubIdsForUser($request->user())->contains($clubId), 403);
    }
}
