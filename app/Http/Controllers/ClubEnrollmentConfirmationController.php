<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Member;
use App\Models\User;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class ClubEnrollmentConfirmationController extends Controller
{
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
        ])->save();

        return response()->json(['data' => $this->payload($request)]);
    }

    public function confirmMember(Request $request, Member $member)
    {
        $this->assertDirectorCanConfirm($request, (int) $member->club_id);
        abort_unless(
            $member->secure_enrollment_link_id && !$member->enrollment_confirmed_at,
            422,
            'This member no longer requires confirmation.'
        );

        $member->forceFill([
            'enrollment_confirmed_at' => now(),
            'enrollment_confirmed_by' => $request->user()->id,
        ])->save();

        return response()->json(['data' => $this->payload($request)]);
    }

    public function payload(Request $request): array
    {
        $clubIds = ClubHelper::clubIdsForUser($request->user());
        if ($clubIds->isEmpty()) {
            return ['total' => 0, 'parents' => [], 'members' => []];
        }

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
                'requested_at' => $parent->created_at?->toIso8601String(),
            ])
            ->values();
        $members = Member::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotNull('secure_enrollment_link_id')
            ->whereNull('enrollment_confirmed_at')
            ->where('status', 'active')
            ->with('parentUser:id,name,email')
            ->oldest()
            ->get()
            ->map(function (Member $member) use ($clubs) {
                $detail = ClubHelper::memberDetail($member);

                return [
                    'id' => $member->id,
                    'name' => $detail['name'] ?? '—',
                    'type' => $member->type,
                    'club_id' => $member->club_id,
                    'club_name' => $clubs[$member->club_id] ?? '—',
                    'parent_name' => $member->parentUser?->name,
                    'created_at' => $member->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return [
            'total' => $parents->count() + $members->count(),
            'parents' => $parents,
            'members' => $members,
        ];
    }

    private function assertDirectorCanConfirm(Request $request, int $clubId): void
    {
        abort_unless(ClubHelper::clubIdsForUser($request->user())->contains($clubId), 403);
    }
}
