<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use App\Http\Middleware\RedirectIfAuthenticated;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();

        if ($user->status && $user->status !== 'active') {
            Auth::logout();
            return back()->withErrors([
                'email' => 'Your account is pending approval by the club director.',
            ]);
        }

        $clubIds = $user->clubs()->pluck('clubs.id')->toArray();
        $primaryClubId = $user->club_id ?: ($clubIds[0] ?? null);
        

        session([
            'is_in_club' => count($clubIds) > 0,
            'user_club_ids' => $clubIds,
            'club_id' => $primaryClubId,
            'church_name' => $user->church_name,
            'user' => $user,
            'email' => $request->input('email'),
        ]);
        return redirect(RedirectIfAuthenticated::redirectPath());
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
