<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\ParentChildLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentChildLinkService
{
    public function __construct(private readonly ParentChildIdentityMatcher $identityMatcher) {}

    public function linkManually(User $parent, string $requestedType, int $detailId): array
    {
        return DB::transaction(function () use ($parent, $requestedType, $detailId) {
            [$detail, $memberType, $memberTypes] = $this->lockedDetail($requestedType, $detailId);

            abort_unless(
                $parent->church_id
                    && Club::query()
                        ->whereKey($detail->club_id)
                        ->where('church_id', $parent->church_id)
                        ->exists(),
                403,
                'Parents may manually link children only within their account church.'
            );

            $evaluation = $this->identityMatcher->evaluate($parent, $detail);
            abort_unless($evaluation['eligible'], 422, 'At least two verified identity factors must match.');

            $member = Member::query()
                ->whereIn('type', $memberTypes)
                ->where('id_data', $detail->id)
                ->lockForUpdate()
                ->first();

            abort_if(
                $member?->parent_id && (int) $member->parent_id !== (int) $parent->id,
                409,
                'This child is already linked to another parent account.'
            );

            if (! $evaluation['can_link_immediately']) {
                return $this->createApprovalRequest($parent, $detail, $memberType, $member, $evaluation);
            }

            $member = $this->attach($parent, $detail, $memberType, $member);

            return [
                'status' => 'linked',
                'message' => 'Member linked to your account.',
                'member' => $member,
            ];
        }, 3);
    }

    public function parentIdForDirectorCreatedMember(
        ?User $actor,
        MemberAdventurer|MemberPathfinder $detail
    ): ?int {
        if (! in_array($actor?->profile_type, ['club_director', 'superadmin'], true)) {
            return null;
        }

        $emails = $detail instanceof MemberAdventurer
            ? [$detail->email_address]
            : [$detail->father_guardian_email, $detail->mother_guardian_email];
        $emails = collect($emails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return null;
        }

        // Director-entered records may belong to a family whose account was
        // created through another club. Email identifies the candidate parent;
        // a shared surname prevents an email typo from attaching the child.
        $parents = User::query()
            ->where('profile_type', 'parent')
            ->where('status', 'active')
            ->whereIn(DB::raw('LOWER(email)'), $emails->all())
            ->get();

        $parent = $parents->first(fn (User $candidate) => $this->identityMatcher->lastNameMatches(
            $candidate->name,
            $detail->applicant_name
        ));

        return $parent ? (int) $parent->id : null;
    }

    private function lockedDetail(string $requestedType, int $detailId): array
    {
        if ($requestedType === 'adventurers') {
            return [
                MemberAdventurer::query()->lockForUpdate()->findOrFail($detailId),
                'adventurers',
                ['adventurers'],
            ];
        }

        return [
            MemberPathfinder::query()->lockForUpdate()->findOrFail($detailId),
            'pathfinders',
            ['pathfinders', 'temp_pathfinder'],
        ];
    }

    private function createApprovalRequest(
        User $parent,
        MemberAdventurer|MemberPathfinder $detail,
        string $memberType,
        ?Member $member,
        array $evaluation
    ): array {
        ParentChildLinkRequest::query()
            ->where('parent_user_id', $parent->id)
            ->where('member_type', $memberType)
            ->where('id_data', $detail->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'decided_at' => now()]);

        $latestRejected = ParentChildLinkRequest::query()
            ->where('parent_user_id', $parent->id)
            ->where('member_type', $memberType)
            ->where('id_data', $detail->id)
            ->where('status', 'rejected')
            ->latest('decided_at')
            ->first();
        abort_if(
            $latestRejected
                && $latestRejected->match_factors === $evaluation['factors']
                && $latestRejected->identity_snapshot === $evaluation['snapshot'],
            409,
            'This request was rejected. Update the mismatched information or contact the club director before trying again.'
        );

        $linkRequest = ParentChildLinkRequest::query()->firstOrCreate(
            [
                'parent_user_id' => $parent->id,
                'member_type' => $memberType,
                'id_data' => $detail->id,
                'status' => 'pending',
            ],
            [
                'member_id' => $member?->id,
                'club_id' => $detail->club_id,
                'match_factors' => $evaluation['factors'],
                'matched_count' => $evaluation['matched_count'],
                'identity_snapshot' => $evaluation['snapshot'],
                'requested_at' => now(),
                'expires_at' => now()->addDays(30),
            ]
        );

        return [
            'status' => 'pending',
            'message' => 'A director confirmation request was created.',
            'request_id' => $linkRequest->id,
        ];
    }

    private function attach(
        User $parent,
        MemberAdventurer|MemberPathfinder $detail,
        string $memberType,
        ?Member $member
    ): Member {
        $member ??= new Member([
            'type' => $memberType,
            'id_data' => $detail->id,
            'club_id' => $detail->club_id,
            'class_id' => null,
            'assigned_staff_id' => null,
            'status' => 'active',
        ]);
        $member->forceFill(['parent_id' => $parent->id])->save();

        if ($detail instanceof MemberPathfinder && ! $detail->member_id) {
            $detail->update(['member_id' => $member->id]);
        }

        return $member;
    }
}
