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
            return redirect('/dashboard')->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
