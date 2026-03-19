<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Staff;
use App\Models\ClubClass;
use App\Models\Club;
use App\Models\Church;
use App\Support\ClubHelper;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request)
    {
        $user = $request->user()?->load(['church', 'clubs', 'staff.classes', 'staff.club']);
        $staffRecord = $user?->staff;
        $assignedClassId = null;
        $assignedClassName = null;
        $assignedClasses = collect();

        if ($staffRecord) {
            $assignedClassId = $staffRecord->assigned_class ?: $staffRecord->classes?->first()?->id;
            if ($assignedClassId) {
                $assignedClass = ClubClass::query()
                    ->where('id', $assignedClassId)
                    ->first(['id', 'class_name']);
                $assignedClassName = $assignedClass?->class_name ?: $staffRecord->classes?->first()?->class_name;
            } else {
                $assignedClassName = $staffRecord->classes?->first()?->class_name;
            }

            $assignedClasses = $staffRecord->classes?->pluck('class_name') ?? collect();
            if ($assignedClassName && !$assignedClasses->contains($assignedClassName)) {
                $assignedClasses->prepend($assignedClassName);
            }
        }

        if ($user) {
            $request->session()->put('assigned_class_id', $assignedClassId);
            $request->session()->put('assigned_class_name', $assignedClassName);
        }

        $isSuperadmin = $user?->profile_type === 'superadmin';
        $availableClubs = $user ? ClubHelper::clubsForUser($user) : collect();
        $activeClub = $user ? ClubHelper::activeClubForUser($user) : null;
        $effectiveClubId = $activeClub?->id ?: ($isSuperadmin ? $request->session()->get('superadmin_context.club_id') : ($request->session()->get('club_context.club_id') ?: $user?->club_id));
        $effectiveChurchId = $activeClub?->church_id ?: ($isSuperadmin
            ? $request->session()->get('superadmin_context.church_id')
            : ($request->session()->get('club_context.church_id') ?: $user?->church_id));

        $effectiveChurch = $effectiveChurchId
            ? Church::query()->where('id', $effectiveChurchId)->first(['id', 'church_name'])
            : null;
        $effectiveClub = $activeClub ?: ($effectiveClubId
            ? Club::query()->where('id', $effectiveClubId)->first(['id', 'club_name', 'club_type', 'church_id', 'church_name'])
            : null);
        $primaryDirectorClub = $user && in_array($user->profile_type, ['club_director', 'superadmin'], true)
            ? Club::query()
                ->where('user_id', $user->id)
                ->orderBy('club_name')
                ->first(['id', 'club_name', 'club_type', 'church_id', 'church_name'])
            : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn() => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'profile_type' => $user->profile_type,
                        'sub_role' => $user->sub_role,
                        'church_id' => $effectiveChurchId ?: $user->church_id,
                        'church_name' => $effectiveChurch?->church_name ?: $user->church_name,
                        'club_id' => $effectiveClubId ?: $user->club_id,
                        'club_name' => $effectiveClub?->club_name ?: null,
                        'club_type' => $effectiveClub?->club_type ?: null,
                        'pastor_name' => optional($user->church)->pastor_name,
                        'conference_name' => optional($user->church)->conference,
                        'assigned_classes' => $assignedClasses->values(),
                        'assigned_class_id' => $assignedClassId,
                        'assigned_class_name' => $assignedClassName,
                        'clubs' => $availableClubs->map(fn($club) => [
                            'id' => $club->id,
                            'club_name' => $club->club_name,
                            'club_type' => $club->club_type,
                            'church_id' => $club->church_id,
                            'church_name' => $club->church_name,
                        ]),
                        'staff' => $staffRecord ? [
                            'id' => $staffRecord->id,
                            'club_id' => $staffRecord->club_id,
                            'club_name' => optional($staffRecord->club)->club_name,
                            'assigned_class_id' => $assignedClassId,
                            'assigned_class_name' => $assignedClassName,
                            'status' => $staffRecord->status,
                        ] : null,
                    ]
                    : null,
                'active_club' => fn() => $effectiveClub ? [
                    'id' => $effectiveClub->id,
                    'club_name' => $effectiveClub->club_name,
                    'club_type' => $effectiveClub->club_type,
                    'church_id' => $effectiveClub->church_id,
                    'church_name' => $effectiveClub->church_name,
                ] : null,
                'active_church' => fn() => $effectiveChurch ? [
                    'id' => $effectiveChurch->id,
                    'church_name' => $effectiveChurch->church_name,
                ] : null,
                'primary_director_club' => fn() => $primaryDirectorClub ? [
                    'id' => $primaryDirectorClub->id,
                    'club_name' => $primaryDirectorClub->club_name,
                    'club_type' => $primaryDirectorClub->club_type,
                    'church_id' => $primaryDirectorClub->church_id,
                    'church_name' => $primaryDirectorClub->church_name,
                ] : null,
                'available_clubs' => fn() => $availableClubs->map(fn($club) => [
                    'id' => $club->id,
                    'club_name' => $club->club_name,
                    'club_type' => $club->club_type,
                    'church_id' => $club->church_id,
                    'church_name' => $club->church_name,
                ])->values(),
                'club_context' => fn() => !$isSuperadmin ? [
                    'church_id' => $effectiveChurchId ? (int) $effectiveChurchId : null,
                    'church_name' => $effectiveChurch?->church_name,
                    'club_id' => $effectiveClubId ? (int) $effectiveClubId : null,
                    'club_name' => $effectiveClub?->club_name,
                ] : null,
                'is_in_club' => fn() => session('is_in_club', false),
                'user_club_ids' => fn() => session('user_club_ids', []),
                'superadmin_context' => fn() => $isSuperadmin ? [
                    'church_id' => $effectiveChurchId ? (int) $effectiveChurchId : null,
                    'church_name' => $effectiveChurch?->church_name,
                    'club_id' => $effectiveClubId ? (int) $effectiveClubId : null,
                    'club_name' => $effectiveClub?->club_name,
                    'available_clubs' => $availableClubs->map(fn($club) => [
                        'id' => $club->id,
                        'club_name' => $club->club_name,
                        'club_type' => $club->club_type,
                        'church_id' => $club->church_id,
                        'church_name' => $club->church_name,
                    ])->values(),
                ] : null,
            ],
        ]);
    }
}
