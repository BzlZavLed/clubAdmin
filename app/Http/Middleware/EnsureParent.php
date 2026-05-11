<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureParent
{
    public function handle($request, Closure $next)
    {
        $currentUser = $request->user();

        if ($currentUser?->profile_type === 'superadmin') {
            $previewParentId = $request->session()->get('superadmin_parent_portal_user_id');

            if ($previewParentId) {
                $parent = User::query()
                    ->whereKey($previewParentId)
                    ->where('profile_type', 'parent')
                    ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))
                    ->first();

                if ($parent) {
                    $request->attributes->set('superadmin_parent_portal_actor_id', $currentUser->id);
                    Auth::setUser($parent);
                    $request->setUserResolver(fn () => $parent);

                    return $next($request);
                }

                $request->session()->forget([
                    'superadmin_parent_portal_user_id',
                    'superadmin_parent_portal_actor_id',
                    'superadmin_parent_portal_member_id',
                ]);
            }
        }

        $profileType = auth()->check() ? auth()->user()->profile_type : null;

        if (in_array($profileType, ['parent', 'superadmin'], true)) {
            return $next($request);
        }

        abort(403, 'Unauthorized');
    }
}
