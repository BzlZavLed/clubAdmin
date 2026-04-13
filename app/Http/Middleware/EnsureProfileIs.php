<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Middleware\RedirectIfAuthenticated;

class EnsureProfileIs
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $profileType = $user?->profile_type;
        $roleKey = $user?->role_key;
        $allowedRoles = collect($roles)
            ->flatMap(fn($value) => explode(',', $value))
            ->map(fn($value) => trim($value))
            ->filter()
            ->values();

        $isSuperadmin = $profileType === 'superadmin' || $roleKey === 'superadmin';
        $hasRole = $allowedRoles->contains($profileType) || $allowedRoles->contains($roleKey);

        if (!$isSuperadmin && !$hasRole) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            return redirect(RedirectIfAuthenticated::redirectPath())->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
