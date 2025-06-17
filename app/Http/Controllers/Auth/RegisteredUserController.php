<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use App\Providers\RouteServiceProvider;
use App\Models\Church;
class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $churches = Church::select('id', 'church_name')->orderBy('church_name')->get();

        return Inertia::render('Auth/Register', [
            'churches' => $churches,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'profile_type' => ['required', 'string'],
            'sub_role' => ['nullable', 'string'],
            'church_id' => 'required|exists:churches,id',
            'church_name' => 'required|string|max:255'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => $validated['profile_type'],
            'sub_role' => $validated['profile_type'] === 'club_personal' ? $validated['sub_role'] : null,
            'church_id' => $validated['church_id'],
            'church_name' => $validated['church_name']
        ]);

        auth()->login($user);

        return redirect($this->redirectToBasedOnProfile($user));
    }
    private function redirectToBasedOnProfile($user): string
    {
        return match ($user->profile_type) {
            'club_director' => '/club-director/dashboard',
            'club_personal' => '/club-personal/dashboard',
            'conference_manager' => '/conference/dashboard',
            'regional_manager' => '/regional/dashboard',
            'union_manager' => '/union/dashboard',
            'nad_manager' => '/nad/dashboard',
            default => RouteServiceProvider::HOME,
        };
    }
}
