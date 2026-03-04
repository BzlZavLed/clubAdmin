<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Middleware\RedirectIfAuthenticated;

class EnsureProfileIs
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $profileType = $request->user()?->profile_type;
        $isSuperadmin = $profileType === 'superadmin';
        $hasRole = $profileType === $role;

        if (!$isSuperadmin && !$hasRole) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            return redirect(RedirectIfAuthenticated::redirectPath())->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
