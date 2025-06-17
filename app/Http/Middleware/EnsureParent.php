<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureParent
{
    public function handle($request, Closure $next)
    {
        if (auth()->check() && auth()->user()->profile_type === 'parent') {
            return $next($request);
        }

        abort(403, 'Unauthorized');
    }
}
