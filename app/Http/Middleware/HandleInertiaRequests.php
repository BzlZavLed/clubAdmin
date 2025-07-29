<?php

namespace App\Http\Middleware;

use App\Models\StaffAdventurer;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
        $staff = StaffAdventurer::with('assignedClasses')
            ->where('email', $request->user()?->email)
            ->first();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn() => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'profile_type' => $request->user()->profile_type,
                        'church_id' => $request->user()->church_id,
                        'church_name' => $request->user()->church_name,
                        'club_id' => $request->user()->club_id,
                        'pastor_name' => optional($request->user()->church)->pastor_name,
                        'conference_name' => optional($request->user()->church)->conference,
                        'assigned_classes' => $staff?->assignedClasses->map(fn($class) => $class->class_name)->values() ?? [],
                        'clubs' => $request->user()->clubs->map(fn($club) => [
                            'id' => $club->id,
                            'club_name' => $club->club_name,
                            'club_type' => $club->club_type,
                            'church_name' => $club->church_name,
                        ]),
                    ]
                    : null,
                'is_in_club' => fn() => session('is_in_club', false),
                'user_club_ids' => fn() => session('user_club_ids', []),
            ],
        ]);
    }
}
