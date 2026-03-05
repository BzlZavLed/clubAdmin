<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Staff;
use App\Models\ClubClass;

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

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn() => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'profile_type' => $user->profile_type,
                        'sub_role' => $user->sub_role,
                        'church_id' => $user->church_id,
                        'church_name' => $user->church_name,
                        'club_id' => $user->club_id,
                        'pastor_name' => optional($user->church)->pastor_name,
                        'conference_name' => optional($user->church)->conference,
                        'assigned_classes' => $assignedClasses->values(),
                        'assigned_class_id' => $assignedClassId,
                        'assigned_class_name' => $assignedClassName,
                        'clubs' => $user->clubs->map(fn($club) => [
                            'id' => $club->id,
                            'club_name' => $club->club_name,
                            'club_type' => $club->club_type,
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
                'is_in_club' => fn() => session('is_in_club', false),
                'user_club_ids' => fn() => session('user_club_ids', []),
            ],
        ]);
    }
}
