<?php

namespace App\Support;

use App\Models\Club;
use App\Models\Staff;
use App\Models\MemberAdventurer;
use Illuminate\Support\Collection;

class ClubHelper
{
    /**
     * Collect club ids the user can operate on: owned, pivot, explicit club_id.
     */
    public static function clubIdsForUser($user): Collection
    {
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
     * Members for a club (adventurers detail).
     */
    public static function membersOfClub(int $clubId): Collection
    {
        return MemberAdventurer::query()
            ->where('club_id', $clubId)
            ->with(['clubClasses' => function ($q) {
                $q->wherePivot('active', true);
            }])
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id']);
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
}
