<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $alreadyActivated = $user->hasVerifiedEmail()
            || ($user->profile_type === 'parent' && $user->isDirectorActivatedParent());

        if ($alreadyActivated) {
            $destination = $user->profile_type === 'parent' ? 'parent.dashboard' : 'dashboard';

            return redirect()->intended(route($destination, absolute: false));
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'email' => 'The verification email could not be sent. Try again or ask the club director to activate the account.',
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
