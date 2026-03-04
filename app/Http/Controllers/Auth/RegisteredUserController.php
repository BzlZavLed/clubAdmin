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
use Illuminate\Validation\Rule;
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

    public function storeBySuperadmin(Request $request)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Rules\Password::defaults()],
            'profile_type' => ['required', Rule::in(['superadmin', 'club_director', 'club_personal'])],
            'sub_role' => ['nullable', 'string'],
            'church_id' => ['nullable', 'exists:churches,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
        ]);

        $isSuperadmin = $validated['profile_type'] === 'superadmin';
        $church = null;

        if (!$isSuperadmin) {
            if (empty($validated['church_id'])) {
                return back()->withErrors(['church_id' => 'Church is required for this profile.'])->withInput();
            }
            $church = Church::find($validated['church_id']);
        }

        if (($validated['profile_type'] ?? null) === 'club_personal' && empty($validated['sub_role'])) {
            return back()->withErrors(['sub_role' => 'Sub role is required for club personal.'])->withInput();
        }

        if (!$isSuperadmin && !empty($validated['club_id'])) {
            $clubBelongsToChurch = Club::where('id', $validated['club_id'])
                ->where('church_id', $validated['church_id'])
                ->exists();

            if (!$clubBelongsToChurch) {
                return back()->withErrors(['club_id' => 'Selected club does not belong to selected church.'])->withInput();
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => $validated['profile_type'],
            'sub_role' => $validated['profile_type'] === 'club_personal' ? ($validated['sub_role'] ?? null) : null,
            'church_id' => $isSuperadmin ? null : $validated['church_id'],
            'church_name' => $isSuperadmin ? null : ($church?->church_name),
            'club_id' => $isSuperadmin ? null : ($validated['club_id'] ?? null),
            'status' => 'active',
        ]);

        if (!$isSuperadmin && !empty($validated['club_id'])) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $validated['club_id']],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            if ($validated['profile_type'] === 'club_director') {
                Club::where('id', $validated['club_id'])->update([
                    'user_id' => $user->id,
                    'director_name' => $user->name,
                    'church_id' => $validated['church_id'],
                    'church_name' => $church?->church_name,
                ]);
            }
        }

        return back()->with('success', 'User created successfully.');
    }

    public function updateBySuperadmin(Request $request, User $user)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Rules\Password::defaults()],
            'profile_type' => ['required', Rule::in(['superadmin', 'club_director', 'club_personal'])],
            'sub_role' => ['nullable', 'string'],
            'church_id' => ['nullable', 'exists:churches,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
        ]);

        $isSuperadmin = $validated['profile_type'] === 'superadmin';
        $church = null;

        if (!$isSuperadmin) {
            if (empty($validated['church_id'])) {
                return back()->withErrors(['church_id' => 'Church is required for this profile.'])->withInput();
            }
            $church = Church::find($validated['church_id']);
        }

        if (($validated['profile_type'] ?? null) === 'club_personal' && empty($validated['sub_role'])) {
            return back()->withErrors(['sub_role' => 'Sub role is required for club personal.'])->withInput();
        }

        if (!$isSuperadmin && !empty($validated['club_id'])) {
            $clubBelongsToChurch = Club::where('id', $validated['club_id'])
                ->where('church_id', $validated['church_id'])
                ->exists();

            if (!$clubBelongsToChurch) {
                return back()->withErrors(['club_id' => 'Selected club does not belong to selected church.'])->withInput();
            }
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->profile_type = $validated['profile_type'];
        $user->sub_role = $validated['profile_type'] === 'club_personal' ? ($validated['sub_role'] ?? null) : null;
        $user->church_id = $isSuperadmin ? null : $validated['church_id'];
        $user->church_name = $isSuperadmin ? null : ($church?->church_name);
        $user->club_id = $isSuperadmin ? null : ($validated['club_id'] ?? null);
        $user->status = $user->status ?: 'active';
        $user->save();

        DB::table('club_user')->where('user_id', $user->id)->delete();
        if (!$isSuperadmin && !empty($validated['club_id'])) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $validated['club_id']],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            if ($validated['profile_type'] === 'club_director') {
                Club::where('id', $validated['club_id'])->update([
                    'user_id' => $user->id,
                    'director_name' => $user->name,
                    'church_id' => $validated['church_id'],
                    'church_name' => $church?->church_name,
                ]);
            }
        }

        return back()->with('success', 'User updated successfully.');
    }

    public function deactivateBySuperadmin(User $user)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403);
        }

        if ($user->profile_type === 'club_director') {
            $ownsActiveClub = Club::where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if ($ownsActiveClub) {
                return back()->withErrors([
                    'user' => 'Cannot deactivate a director who owns an active club. Reassign the club first.',
                ]);
            }
        }

        $user->status = 'inactive';
        $user->save();

        DB::table('club_user')
            ->where('user_id', $user->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        return back()->with('success', 'User deactivated successfully.');
    }

    public function deleteBySuperadmin(User $user)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403);
        }

        if (auth()->id() === $user->id) {
            return back()->withErrors([
                'user' => 'You cannot delete your own superadmin account.',
            ]);
        }

        if ($user->profile_type === 'club_director') {
            $ownsActiveClub = Club::where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if ($ownsActiveClub) {
                return back()->withErrors([
                    'user' => 'Cannot delete a director who owns an active club. Reassign the club first.',
                ]);
            }
        }

        $user->status = 'deleted';
        $user->club_id = null;
        $user->save();

        DB::table('club_user')
            ->where('user_id', $user->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        return back()->with('success', 'User deleted successfully.');
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
