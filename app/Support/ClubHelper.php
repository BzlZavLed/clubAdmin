<?php

namespace App\Support;

use App\Models\Club;
use App\Models\Staff;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\StaffAdventurer;
use App\Models\TempStaffPathfinder;
use Illuminate\Support\Collection;

class ClubHelper
{
    /**
     * Clubs the user can operate on as full models.
     */
    public static function clubsForUser($user): Collection
    {
        if (!$user) {
            return collect();
        }

        if (($user->profile_type ?? null) === 'superadmin') {
            return Club::query()
                ->orderBy('club_name')
                ->get(['id', 'club_name', 'club_type', 'church_id', 'church_name', 'user_id']);
        }

        $clubIds = self::clubIdsForUser($user);
        if ($clubIds->isEmpty()) {
            return collect();
        }

        return Club::query()
            ->whereIn('id', $clubIds)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'church_id', 'church_name', 'user_id']);
    }

    /**
     * Collect club ids the user can operate on: owned, pivot, explicit club_id.
     */
    public static function clubIdsForUser($user): Collection
    {
        if (($user?->profile_type ?? null) === 'superadmin') {
            $contextClubId = session('superadmin_context.club_id');
            $contextChurchId = session('superadmin_context.church_id');

            if ($contextClubId) {
                return collect([(int) $contextClubId]);
            }

            if ($contextChurchId) {
                return Club::query()
                    ->where('church_id', (int) $contextChurchId)
                    ->pluck('id')
                    ->unique()
                    ->values();
            }

            return Club::query()
                ->pluck('id')
                ->unique()
                ->values();
        }

        $pivotIds = $user?->clubs?->pluck('id') ?? collect();
        $explicit = $user?->club_id ? collect([$user->club_id]) : collect();

        return Club::query()
            ->where('user_id', $user?->id ?? 0)
            ->orWhereIn('id', $pivotIds)
            ->orWhereIn('id', $explicit)
            ->pluck('id')
            ->unique()
            ->values();
    }

    /**
     * Resolve the active club context for a user.
     */
    public static function activeClubForUser($user): ?Club
    {
        if (!$user) {
            return null;
        }

        if (($user->profile_type ?? null) === 'superadmin') {
            $contextClubId = session('superadmin_context.club_id');

            if ($contextClubId) {
                return Club::query()->find($contextClubId, ['id', 'club_name', 'club_type', 'church_id', 'church_name', 'user_id']);
            }

            return null;
        }

        $clubs = self::clubsForUser($user);
        if ($clubs->isEmpty()) {
            return null;
        }

        $sessionClubId = session('club_context.club_id');
        if ($sessionClubId) {
            $sessionClub = $clubs->firstWhere('id', (int) $sessionClubId);
            if ($sessionClub) {
                return $sessionClub;
            }
        }

        if ($user->club_id) {
            $explicit = $clubs->firstWhere('id', (int) $user->club_id);
            if ($explicit) {
                return $explicit;
            }
        }

        return $clubs->first();
    }

    /**
     * Resolve a single club for the user, optionally honoring a preferred club_id.
     */
    public static function clubForUser($user, $clubId = null): Club
    {
        $ids = self::clubIdsForUser($user);

        $query = Club::query()->whereIn('id', $ids);
        if ($clubId) {
            $query->where('id', $clubId);
        }

        $club = $query->first();

        if (!$club) {
            $club = Club::query()->whereIn('id', $ids)->firstOrFail();
        }

        return $club;
    }

    /**
     * Active clubs for a church (by church_id).
     */
    public static function churchClubs(int $churchId): Collection
    {
        return Club::query()
            ->where('church_id', $churchId)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'church_id']);
    }

    /**
     * Members for a club (unified via members table).
     *
     * Returns a normalized collection of arrays:
     * [
     *   'member_id' => (int) members.id,
     *   'member_type' => (string) members.type,
     *   'id_data' => (int|null) members.id_data,
     *   'class_id' => (int|null) members.class_id,
     *   'club_id' => (int) members.club_id,
     *   'applicant_name' => (string) resolved from detail tables,
     * ]
     */
    public static function membersOfClub(int $clubId): Collection
    {
        return self::membersByClubAndClass($clubId, null);
    }

    /**
     * Members for a club filtered by a specific class (unified via members table).
     * If $classId is null, returns all active members for the club.
     */
    public static function membersByClubAndClass(int $clubId, ?int $classId): Collection
    {
        $query = Member::query()
            ->where('club_id', $clubId)
            ->where('status', 'active');

        if ($classId !== null) {
            $query->where('class_id', $classId);
        }

        $memberRows = $query->get(['id', 'type', 'id_data', 'club_id', 'class_id', 'parent_id']);

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->filter()->values();
        $pathfinderIds = $memberRows->whereIn('type', ['temp_pathfinder', 'pathfinders'])->pluck('id_data')->filter()->values();

        $adventurerNames = MemberAdventurer::query()
            ->whereIn('id', $adventurerIds)
            ->get(['id', 'applicant_name'])
            ->keyBy('id');

        $pathfinderNames = MemberPathfinder::query()
            ->whereIn('id', $pathfinderIds)
            ->get(['id', 'applicant_name'])
            ->keyBy('id');

        return $memberRows
            ->map(function ($m) use ($adventurerNames, $pathfinderNames) {
                $name = null;
                if ($m->type === 'adventurers') {
                    $name = $adventurerNames[$m->id_data]->applicant_name ?? null;
                } elseif (in_array($m->type, ['temp_pathfinder', 'pathfinders'], true)) {
                    $name = $pathfinderNames[$m->id_data]->applicant_name ?? null;
                }

                return [
                    'member_id' => $m->id,
                    'member_type' => $m->type,
                    'id_data' => $m->id_data,
                    'class_id' => $m->class_id,
                    'club_id' => $m->club_id,
                    'parent_id' => $m->parent_id,
                    'applicant_name' => $name ?? '—',
                ];
            })
            ->values();
    }

    /**
     * Backwards-compatible alias: members by class+club.
     */
    public static function getMembersByClassAndClub(int $clubId, int $classId): Collection
    {
        return self::membersByClubAndClass($clubId, $classId);
    }

    /**
     * Staff for a club (general staff table).
     */
    public static function staffOfClub(int $clubId): Collection
    {
        return Staff::query()
            ->where('club_id', $clubId)
            ->with(['user:id,name,email', 'classes:id,class_name'])
            ->orderBy('id')
            ->get(['id', 'user_id', 'club_id', 'assigned_class', 'type', 'status']);
    }

    /**
     * Resolve member detail data from members.type + members.id_data.
     */
    public static function memberDetail(?Member $member): ?array
    {
        if (!$member) return null;

        $type = $member->type;
        $idData = $member->id_data;

        if ($type === 'adventurers' && $idData) {
            $row = MemberAdventurer::query()->find($idData);
            return [
                'member_id' => $member->id,
                'type' => $type,
                'id_data' => $idData,
                'name' => $row?->applicant_name,
            ];
        }

        if (in_array($type, ['temp_pathfinder', 'pathfinders'], true) && $idData) {
            $row = MemberPathfinder::query()->find($idData);
            return [
                'member_id' => $member->id,
                'type' => $type,
                'id_data' => $idData,
                'name' => $row?->applicant_name,
            ];
        }

        return [
            'member_id' => $member->id,
            'type' => $type,
            'id_data' => $idData,
            'name' => null,
        ];
    }

    /**
     * Resolve staff detail data from staff.type + staff.id_data.
     */
    public static function staffDetail(?Staff $staff): ?array
    {
        if (!$staff) return null;

        $type = $staff->type;
        $idData = $staff->id_data;

        // Prefer related user name (works for most)
        $fallbackName = $staff->user?->name;

        if ($type === 'adventurers' && $idData) {
            $row = StaffAdventurer::query()->find($idData);
            return [
                'staff_id' => $staff->id,
                'type' => $type,
                'id_data' => $idData,
                'name' => $row?->name ?? $fallbackName,
            ];
        }

        if ($type === 'temp_pathfinder' && $idData) {
            $row = TempStaffPathfinder::query()->find($idData);
            return [
                'staff_id' => $staff->id,
                'type' => $type,
                'id_data' => $idData,
                'name' => $row?->staff_name ?? $fallbackName,
            ];
        }

        return [
            'staff_id' => $staff->id,
            'type' => $type,
            'id_data' => $idData,
            'name' => $fallbackName,
        ];
    }
}
