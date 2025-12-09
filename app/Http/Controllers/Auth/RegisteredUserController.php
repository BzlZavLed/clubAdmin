<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SubRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use App\Providers\RouteServiceProvider;
use App\Models\Church;
use App\Models\ChurchInviteCode;
use Illuminate\Support\Facades\DB;
class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $churches = Church::select('id', 'church_name')
            ->orderBy('church_name')
            ->get();
        $clubs = \App\Models\Club::select('id', 'club_name', 'church_id')
            ->get()
            ->map(function ($club) {
                $club->director_exists = \App\Models\User::where('club_id', $club->id)
                    ->where('profile_type', 'club_director')
                    ->where('status', '!=', 'deleted')
                    ->exists();
                return $club;
            });
        $subRoles = SubRole::all();


        return Inertia::render('Auth/Register', [
            'churches' => $churches,
            'clubs' => $clubs,
            'subRoles' => $subRoles,
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
            'church_name' => 'required|string|max:255',
            'club_id' => ['nullable', 'string'],
            'invite_code' => ['required', 'string'],
        ]);

        $invite = ChurchInviteCode::where('code', $validated['invite_code'])
            ->where('church_id', $validated['church_id'])
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->first();

        if (!$invite || ($invite->uses_left === 0)) {
            return back()->withErrors(['invite_code' => 'Invalid, expired, or fully used invite code.'])->withInput();
        }

        $clubIdInput = $request->input('club_id');
        $clubId = null;
        if ($clubIdInput && $clubIdInput !== 'new') {
            $clubExists = \App\Models\Club::where('id', $clubIdInput)
                ->where('church_id', $validated['church_id'])
                ->exists();
            if (!$clubExists) {
                return back()->withErrors(['club_id' => 'Selected club is not valid for this church.'])->withInput();
            }
            $clubId = $clubIdInput;
        }

        $status = $validated['profile_type'] === 'club_director' ? 'active' : 'pending';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => $validated['profile_type'],
            'sub_role' => $validated['profile_type'] === 'club_personal' ? $validated['sub_role'] : null,
            'church_id' => $validated['church_id'],
            'church_name' => $validated['church_name'],
            'club_id' => $clubId,
            'status' => $status,
        ]);

        // decrement uses_left if applicable
        if (!is_null($invite->uses_left) && $invite->uses_left > 0) {
            $invite->decrement('uses_left');
        }

        if ($status === 'active') {
            auth()->login($user);
            return redirect($this->redirectToBasedOnProfile($user));
        }

        return redirect()->route('login')->with('status', 'Registration submitted. Await director approval before logging in.');
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
