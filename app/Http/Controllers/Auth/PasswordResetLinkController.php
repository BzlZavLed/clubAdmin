<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\User;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);
        $request->merge(['email' => mb_strtolower($request->string('email')->toString())]);

        $user = User::query()
            ->where('email', $request->string('email')->lower()->toString())
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))
            ->first();

        if ($user?->profile_type === 'parent' && ! $user->canSelfServiceCredentials()) {
            throw ValidationException::withMessages([
                'email' => ['This parent account cannot use email recovery. Ask the club director to issue a new password.'],
            ]);
        }

        $broker = $user?->profile_type === 'parent' ? Password::broker('parents') : Password::broker();
        $status = $broker->sendResetLink($request->only('email'));

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
