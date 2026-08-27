<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParentEmailVerificationController extends Controller
{
    public function __invoke(Request $request, User $user, string $hash): RedirectResponse
    {
        abort_unless($user->profile_type === 'parent', 404);
        abort_unless(hash_equals(sha1(mb_strtolower($user->email)), $hash), 403);

        if ($user->isDirectorActivatedParent()) {
            return redirect()->route('login')->with(
                'status',
                'This account was activated by the club director. Contact the director for password assistance.'
            );
        }

        if (! $user->hasVerifiedEmail()) {
            $activation = [
                'email_verified_at' => now(),
                'parent_activation_method' => 'email',
            ];

            if ($user->secure_enrollment_link_id) {
                $activation['enrollment_confirmed_at'] = now();
                $activation['enrollment_confirmed_by'] = null;
            }

            $user->forceFill($activation)->save();
            event(new Verified($user));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $clubIds = $user->clubs()->pluck('clubs.id')->all();
        $request->session()->put([
            'is_in_club' => count($clubIds) > 0,
            'user_club_ids' => $clubIds,
            'club_id' => $user->club_id ?: ($clubIds[0] ?? null),
            'church_name' => $user->church_name,
            'user' => $user,
            'email' => $user->email,
            'secure_enrollment_success' => (bool) $user->secure_enrollment_link_id,
        ]);

        return redirect()->route('parent.dashboard')->with('status', 'email-verified');
    }
}
