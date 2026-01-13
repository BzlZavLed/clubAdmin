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
use App\Models\Club;
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

    public function storeSuperadmin(Request $request)
    {
        if ($this->superadminExists()) {
            abort(403, 'Superadmin already exists.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => 'superadmin',
            'sub_role' => null,
            'church_id' => null,
            'church_name' => null,
            'club_id' => null,
            'status' => 'active',
        ]);

        auth()->login($user);

        return redirect(RouteServiceProvider::HOME);
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
            'club_id' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    $isDirector = $request->input('profile_type') === 'club_director';
                    if (!$isDirector && ($value === null || $value === 'new')) {
                        return $fail('Please select an existing club.');
                    }
                    if ($value === null || $value === 'new') {
                        return;
                    }
                    if (!is_numeric($value)) {
                        return $fail('The club id must be an integer.');
                    }
                    $exists = Club::where('id', $value)
                        ->where('church_id', $request->input('church_id'))
                        ->exists();
                    if (!$exists) {
                        return $fail('Selected club is not valid for this church.');
                    }
                },
            ],
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
            $clubId = (int) $clubIdInput;
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

        if ($clubId) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $clubId],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
        }

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
            'superadmin' => '/super-admin/dashboard',
            default => RouteServiceProvider::HOME,
        };
    }

    private function superadminExists(): bool
    {
        return User::where('profile_type', 'superadmin')->exists();
    }
}
