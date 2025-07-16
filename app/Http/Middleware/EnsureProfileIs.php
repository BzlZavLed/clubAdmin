<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIs
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->profile_type !== $role) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            return redirect('/dashboard')->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
