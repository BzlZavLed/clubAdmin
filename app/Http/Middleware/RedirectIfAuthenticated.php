<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect(self::redirectPath());
            }
        }

        return $next($request);
    }

    public static function redirectPath(): string
    {
        $user = Auth::user();
        $role = $user?->role_key ?: $user?->profile_type;

        return match ($role) {
            'club_director' => '/club-director/dashboard',
            'club_personal' => '/club-personal/dashboard',
            'district_pastor', 'district_secretary' => '/district/dashboard',
            'association_youth_director' => '/association/dashboard',
            'union_youth_director' => '/union/dashboard',
            'conference_manager' => '/conference/dashboard',
            'regional_manager' => '/regional/dashboard',
            'union_manager' => '/union/dashboard',
            'nad_manager' => '/nad/dashboard',
            'parent' => '/parent/dashboard',
            'superadmin' => '/super-admin/dashboard',
            default => RouteServiceProvider::HOME,
        };
    }
}
