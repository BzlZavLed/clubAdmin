<?php

namespace App\Support;

use App\Models\Association;
use App\Models\Club;
use App\Models\Church;
use App\Models\District;
use App\Models\Staff;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\StaffAdventurer;
use App\Models\StaffPathfinder;
use App\Models\Union;
use Illuminate\Support\Collection;

class ClubHelper
{
    public static function roleKey($user): ?string
    {
        return $user?->role_key ?: $user?->profile_type;
    }

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
     * Churches the user can operate on as full models.
     */
    public static function churchesForUser($user): Collection
    {
        if (!$user) {
            return collect();
        }

        $churchIds = self::churchIdsForUser($user);
        if ($churchIds->isEmpty()) {
            return collect();
        }

        return Church::query()
            ->whereIn('id', $churchIds)
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'email']);
    }

    /**
     * Collect club ids the user can operate on: owned, pivot, explicit club_id.
     */
    public static function clubIdsForUser($user): Collection
    {
        $role = self::roleKey($user);

        if ($role === 'superadmin') {
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

        if (in_array($role, ['district_pastor', 'district_secretary', 'association_youth_director', 'union_youth_director'], true)) {
            $churchIds = self::churchIdsForUser($user);
            if ($churchIds->isEmpty()) {
                return collect();
            }

            return Club::query()
                ->whereIn('church_id', $churchIds)
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
     * Collect church ids the user can operate on.
     */
    public static function churchIdsForUser($user): Collection
    {
        if (!$user) {
            return collect();
        }

        $role = self::roleKey($user);

        if ($role === 'superadmin') {
            $contextClubId = session('superadmin_context.club_id');
            $contextChurchId = session('superadmin_context.church_id');

            if ($contextClubId) {
                $churchId = Club::query()->where('id', (int) $contextClubId)->value('church_id');
                return $churchId ? collect([(int) $churchId]) : collect();
            }

            if ($contextChurchId) {
                return collect([(int) $contextChurchId]);
            }

            return Church::query()
                ->pluck('id')
                ->unique()
                ->values();
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return Church::query()
                ->where('district_id', (int) $user->scope_id)
                ->pluck('id')
                ->unique()
                ->values();
        }

        if ($role === 'association_youth_director') {
            return Church::query()
                ->whereHas('district', fn($q) => $q->where('association_id', (int) $user->scope_id))
                ->pluck('id')
                ->unique()
                ->values();
        }

        if ($role === 'union_youth_director') {
            return Church::query()
                ->whereHas('district.association', fn($q) => $q->where('union_id', (int) $user->scope_id))
                ->pluck('id')
                ->unique()
                ->values();
        }

        $explicit = $user?->church_id ? collect([(int) $user->church_id]) : collect();
        $clubChurchIds = Club::query()
            ->whereIn('id', self::clubIdsForUser($user))
            ->pluck('church_id');

        return $explicit
            ->merge($clubChurchIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    public static function scopeSummaryForUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        $role = self::roleKey($user);
        $scopeType = $user->scope_type;
        $scopeId = $user->scope_id;

        if ($role === 'superadmin' || $scopeType === 'global') {
            return [
                'type' => 'global',
                'id' => null,
                'name' => 'Global',
            ];
        }

        if ($scopeType === 'district' && $scopeId) {
            $district = District::query()->with('association.union')->find($scopeId);
            return $district ? [
                'type' => 'district',
                'id' => $district->id,
                'name' => $district->name,
                'association_name' => $district->association?->name,
                'union_name' => $district->association?->union?->name,
            ] : null;
        }

        if ($scopeType === 'association' && $scopeId) {
            $association = Association::query()->with('union')->find($scopeId);
            return $association ? [
                'type' => 'association',
                'id' => $association->id,
                'name' => $association->name,
                'union_name' => $association->union?->name,
            ] : null;
        }

        if ($scopeType === 'union' && $scopeId) {
            $union = Union::query()->find($scopeId);
            return $union ? [
                'type' => 'union',
                'id' => $union->id,
                'name' => $union->name,
            ] : null;
        }

        if ($scopeType === 'church' && $scopeId) {
            $church = Church::query()->find($scopeId);
            return $church ? [
                'type' => 'church',
                'id' => $church->id,
                'name' => $church->church_name,
            ] : null;
        }

        if ($scopeType === 'club' && $scopeId) {
            $club = Club::query()->find($scopeId);
            return $club ? [
                'type' => 'club',
                'id' => $club->id,
                'name' => $club->club_name,
                'church_name' => $club->church_name,
            ] : null;
        }

        return null;
    }

    public static function hierarchyWidgetDataForUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        $role = self::roleKey($user);

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            $district = District::query()
                ->with([
                    'association.union:id,name',
                    'churches' => fn ($query) => $query->orderBy('church_name'),
                    'churches.clubs' => fn ($query) => $query->orderBy('club_name'),
                ])
                ->find($user->scope_id, ['id', 'association_id', 'name']);

            if (!$district) {
                return null;
            }

            return [
                'level' => 'district',
                'title' => $district->name,
                'association_name' => $district->association?->name,
                'union_name' => $district->association?->union?->name,
                'districts' => collect([$district])->map(fn ($item) => self::mapDistrictForWidget($item))->values()->all(),
                'summary' => [
                    'districts' => 1,
                    'churches' => $district->churches->count(),
                    'clubs' => $district->churches->flatMap->clubs->count(),
                ],
            ];
        }

        if ($role === 'association_youth_director') {
            $association = Association::query()
                ->with([
                    'union:id,name',
                    'districts' => fn ($query) => $query->orderBy('name'),
                    'districts.churches' => fn ($query) => $query->orderBy('church_name'),
                    'districts.churches.clubs' => fn ($query) => $query->orderBy('club_name'),
                ])
                ->find($user->scope_id, ['id', 'union_id', 'name']);

            if (!$association) {
                return null;
            }

            return [
                'level' => 'association',
                'title' => $association->name,
                'association_name' => $association->name,
                'union_name' => $association->union?->name,
                'districts' => $association->districts->map(fn ($item) => self::mapDistrictForWidget($item))->values()->all(),
                'summary' => [
                    'districts' => $association->districts->count(),
                    'churches' => $association->districts->flatMap->churches->count(),
                    'clubs' => $association->districts->flatMap->churches->flatMap->clubs->count(),
                ],
            ];
        }

        if ($role === 'union_youth_director') {
            $union = Union::query()
                ->with([
                    'associations' => fn ($query) => $query->orderBy('name'),
                    'associations.districts' => fn ($query) => $query->orderBy('name'),
                    'associations.districts.churches' => fn ($query) => $query->orderBy('church_name'),
                    'associations.districts.churches.clubs' => fn ($query) => $query->orderBy('club_name'),
                ])
                ->find($user->scope_id, ['id', 'name']);

            if (!$union) {
                return null;
            }

            return [
                'level' => 'union',
                'title' => $union->name,
                'association_name' => null,
                'union_name' => $union->name,
                'associations' => $union->associations->map(function ($association) {
                    return [
                        'id' => $association->id,
                        'name' => $association->name,
                        'districts_count' => $association->districts->count(),
                        'churches_count' => $association->districts->flatMap->churches->count(),
                        'clubs_count' => $association->districts->flatMap->churches->flatMap->clubs->count(),
                        'districts' => $association->districts->map(fn ($district) => self::mapDistrictForWidget($district))->values()->all(),
                    ];
                })->values()->all(),
                'summary' => [
                    'associations' => $union->associations->count(),
                    'districts' => $union->associations->flatMap->districts->count(),
                    'churches' => $union->associations->flatMap->districts->flatMap->churches->count(),
                    'clubs' => $union->associations->flatMap->districts->flatMap->churches->flatMap->clubs->count(),
                ],
            ];
        }

        return null;
    }

    private static function mapDistrictForWidget(District $district): array
    {
        return [
            'id' => $district->id,
            'name' => $district->name,
            'association_name' => $district->association?->name,
            'union_name' => $district->association?->union?->name,
            'churches_count' => $district->churches->count(),
            'clubs_count' => $district->churches->flatMap->clubs->count(),
            'churches' => $district->churches->map(function ($church) {
                return [
                    'id' => $church->id,
                    'name' => $church->church_name,
                    'clubs_count' => $church->clubs->count(),
                    'clubs' => $church->clubs->map(fn ($club) => [
                        'id' => $club->id,
                        'name' => $club->club_name,
                        'type' => $club->club_type,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
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

        if ($clubId) {
            return Club::query()
                ->whereIn('id', $ids)
                ->where('id', $clubId)
                ->firstOrFail();
        }

        return Club::query()->whereIn('id', $ids)->firstOrFail();
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
        $clubType = Club::query()->where('id', $clubId)->value('club_type');
        $query = Member::query()
            ->where('club_id', $clubId)
            ->where('status', 'active');

        if ($clubType === 'pathfinders') {
            $query->whereIn('type', ['temp_pathfinder', 'pathfinders']);
        } elseif ($clubType === 'adventurers') {
            $query->where('type', 'adventurers');
        }

        if ($classId !== null) {
            $query->where('class_id', $classId);
        }

        $memberRows = $query->get(['id', 'type', 'id_data', 'club_id', 'class_id', 'parent_id']);

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->filter()->values();
        $pathfinderMemberIds = $memberRows->whereIn('type', ['temp_pathfinder', 'pathfinders'])->pluck('id')->filter()->values();
        $pathfinderIds = $memberRows->whereIn('type', ['temp_pathfinder', 'pathfinders'])->pluck('id_data')->filter()->values();

        $adventurerNames = MemberAdventurer::query()
            ->whereIn('id', $adventurerIds)
            ->get(['id', 'applicant_name'])
            ->keyBy('id');

        $pathfinderRows = MemberPathfinder::query()
            ->when(
                $pathfinderMemberIds->isNotEmpty() || $pathfinderIds->isNotEmpty(),
                function ($query) use ($pathfinderMemberIds, $pathfinderIds) {
                    $query->where(function ($inner) use ($pathfinderMemberIds, $pathfinderIds) {
                        if ($pathfinderMemberIds->isNotEmpty()) {
                            $inner->whereIn('member_id', $pathfinderMemberIds);
                        }
                        if ($pathfinderIds->isNotEmpty()) {
                            $method = $pathfinderMemberIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                            $inner->{$method}('id', $pathfinderIds);
                        }
                    });
                }
            )
            ->get(['id', 'member_id', 'applicant_name']);

        $pathfindersById = $pathfinderRows->keyBy('id');
        $pathfindersByMemberId = $pathfinderRows->filter(fn ($row) => !empty($row->member_id))->keyBy('member_id');

        $normalizedRows = $memberRows->map(function ($m) use ($adventurerNames, $pathfindersById, $pathfindersByMemberId, $clubType) {
            $name = null;
            $resolvedPathfinderId = null;

            if ($clubType === 'pathfinders' || in_array($m->type, ['temp_pathfinder', 'pathfinders'], true)) {
                $resolvedPathfinder = $pathfindersByMemberId->get($m->id)
                    ?? ($m->id_data ? $pathfindersById->get($m->id_data) : null);
                $resolvedPathfinderId = $resolvedPathfinder?->id;
                $name = $resolvedPathfinder?->applicant_name;
            } elseif ($m->type === 'adventurers') {
                $name = $adventurerNames[$m->id_data]->applicant_name ?? null;
            }

            return [
                'member_id' => $m->id,
                'member_type' => $m->type,
                'id_data' => $resolvedPathfinderId ?? $m->id_data,
                'class_id' => $m->class_id,
                'club_id' => $m->club_id,
                'parent_id' => $m->parent_id,
                'applicant_name' => $name ?? '—',
                'resolved_pathfinder_id' => $resolvedPathfinderId,
            ];
        });

        return $normalizedRows
            ->sortByDesc(function ($row) {
                if ($row['member_type'] === 'pathfinders') return 2;
                if ($row['member_type'] === 'temp_pathfinder') return 1;
                return 0;
            })
            ->unique(function ($row) {
                if (in_array($row['member_type'], ['temp_pathfinder', 'pathfinders'], true) && !empty($row['resolved_pathfinder_id'])) {
                    return 'pathfinder:' . $row['resolved_pathfinder_id'];
                }

                return 'member:' . $row['member_id'];
            })
            ->map(function ($row) {
                unset($row['resolved_pathfinder_id']);
                return $row;
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

        if (in_array($type, ['temp_pathfinder', 'pathfinders'], true)) {
            $row = MemberPathfinder::query()
                ->where('member_id', $member->id)
                ->first();

            if (!$row && $idData) {
                $row = MemberPathfinder::query()->find($idData);
            }

            return [
                'member_id' => $member->id,
                'type' => $type,
                'id_data' => $row?->id ?? $idData,
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

        if (in_array($type, ['temp_pathfinder', 'pathfinders'], true) && $idData) {
            $row = StaffPathfinder::query()->find($idData);
            return [
                'staff_id' => $staff->id,
                'type' => 'pathfinders',
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
