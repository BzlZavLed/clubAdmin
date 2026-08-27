<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureParentAccountActivated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->profile_type === 'parent' && ! $user->canAccessParentPortal()) {
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
