<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureParent
{
    public function handle($request, Closure $next)
    {
        $profileType = auth()->check() ? auth()->user()->profile_type : null;

        if (in_array($profileType, ['parent', 'superadmin'], true)) {
            return $next($request);
        }

        abort(403, 'Unauthorized');
    }
}
