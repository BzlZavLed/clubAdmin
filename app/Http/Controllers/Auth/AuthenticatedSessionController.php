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
use App\Providers\RouteServiceProvider;
use Log;

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

        $clubIds = $user->clubs()->pluck('clubs.id')->toArray();
        

        session([
            'is_in_club' => count($clubIds) > 0,
            'user_club_ids' => $clubIds,
            'church_name' => $user->church_name,
            'user' => $user,
            'email' => $request->input('email'),
        ]);
        return redirect($this->redirectToBasedOnProfile(Auth::user()));
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

    private function redirectToBasedOnProfile($user): string
    {
        //dd($user->profile_type);
        return match ($user->profile_type) {
            'club_director' => '/club-director/dashboard',
            'club_personal' => '/club-personal/dashboard',
            'conference_manager' => '/conference/dashboard',
            'regional_manager' => '/regional/dashboard',
            'union_manager' => '/union/dashboard',
            'nad_manager' => '/nad/dashboard',
            'parent' => '/parent/apply',
            default => RouteServiceProvider::HOME,
        };
    }
}
