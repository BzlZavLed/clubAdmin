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
        $isTreasurer = $profileType === 'treasurer' || $roleKey === 'treasurer';

        if (!$hasRole && $isTreasurer && $allowedRoles->contains('club_director') && $this->isTreasurerFinanceRoute($request)) {
            $hasRole = true;
        }

        if (!$isSuperadmin && !$hasRole) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            return redirect(RedirectIfAuthenticated::redirectPath())->with('error', 'Access denied.');
        }

        return $next($request);
    }

    private function isTreasurerFinanceRoute(Request $request): bool
    {
        $routeName = (string) ($request->route()?->getName() ?? '');
        if ($routeName === '') {
            return false;
        }

        return str_starts_with($routeName, 'club.director.finance.')
            || str_starts_with($routeName, 'club.finance-engine.')
            || str_starts_with($routeName, 'club.director.treasury')
            || str_starts_with($routeName, 'club.director.event-settlements.')
            || str_starts_with($routeName, 'club.director.payments')
            || str_starts_with($routeName, 'club.director.expenses')
            || str_starts_with($routeName, 'club.director.accounting-corrections')
            || in_array($routeName, [
                'club.my-club-finances',
                'club.reports.finances',
                'club.reports.accounts',
            ], true);
    }
}
