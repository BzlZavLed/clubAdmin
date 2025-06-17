<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next): Response
{
    if (Auth::check()) {
        \Log::info('User is authenticated. Redirecting...', [
            'profile_type' => Auth::user()->profile_type,
            'path' => $request->path()
        ]);

        return redirect(match (Auth::user()->profile_type) {
            'club_director' => '/club-director/dashboard',
            'parent' => '/parent/apply',
            default => '/dashboard'
        });
    }

    \Log::info('User is not authenticated. Showing guest page.');
    return $next($request);
}
}
