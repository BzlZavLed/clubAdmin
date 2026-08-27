<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        if ($user->profile_type === 'parent' && $user->canAccessParentPortal()) {
            return redirect()->intended(route('parent.dashboard', absolute: false));
        }

        return $user->hasVerifiedEmail()
            ? redirect()->intended(route('dashboard', absolute: false))
            : Inertia::render('Auth/VerifyEmail', [
                'status' => session('status'),
                'email' => $user->email,
                'is_parent' => $user->profile_type === 'parent',
            ]);
    }
}
