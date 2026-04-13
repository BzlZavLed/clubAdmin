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
use Illuminate\Validation\ValidationException;
class RegisteredUserController extends Controller
{
    private function superadminManageableProfiles(): array
    {
        return [
            'superadmin',
            'club_director',
            'club_personal',
            'district_pastor',
            'district_secretary',
            'association_youth_director',
            'union_youth_director',
        ];
    }

    private function resolveManagedUserScope(array $validated): array
    {
        $profileType = $validated['profile_type'];

        if ($profileType === 'superadmin') {
            return [
                'role_key' => 'superadmin',
                'scope_type' => 'global',
                'scope_id' => null,
                'church_id' => null,
                'church_name' => null,
                'club_id' => null,
                'sub_role' => null,
            ];
        }

        if (in_array($profileType, ['district_pastor', 'district_secretary'], true)) {
            if (empty($validated['district_id'])) {
                throw ValidationException::withMessages([
                    'district_id' => 'District is required for this profile.',
                ]);
            }

            return [
                'role_key' => $profileType,
                'scope_type' => 'district',
                'scope_id' => (int) $validated['district_id'],
                'church_id' => null,
                'church_name' => null,
                'club_id' => null,
                'sub_role' => null,
            ];
        }

        if ($profileType === 'association_youth_director') {
            if (empty($validated['association_id'])) {
                throw ValidationException::withMessages([
                    'association_id' => 'Association is required for this profile.',
                ]);
            }

            return [
                'role_key' => $profileType,
                'scope_type' => 'association',
                'scope_id' => (int) $validated['association_id'],
                'church_id' => null,
                'church_name' => null,
                'club_id' => null,
                'sub_role' => null,
            ];
        }

        if ($profileType === 'union_youth_director') {
            if (empty($validated['union_id'])) {
                throw ValidationException::withMessages([
                    'union_id' => 'Union is required for this profile.',
                ]);
            }

            return [
                'role_key' => $profileType,
                'scope_type' => 'union',
                'scope_id' => (int) $validated['union_id'],
                'church_id' => null,
                'church_name' => null,
                'club_id' => null,
                'sub_role' => null,
            ];
        }

        if (empty($validated['church_id'])) {
            throw ValidationException::withMessages([
                'church_id' => 'Church is required for this profile.',
            ]);
        }

        $church = Church::find($validated['church_id']);

        return [
            'role_key' => $profileType,
            'scope_type' => !empty($validated['club_id']) ? 'club' : 'church',
            'scope_id' => !empty($validated['club_id']) ? (int) $validated['club_id'] : (int) $validated['church_id'],
            'church_id' => (int) $validated['church_id'],
            'church_name' => $church?->church_name,
            'club_id' => !empty($validated['club_id']) ? (int) $validated['club_id'] : null,
            'sub_role' => $profileType === 'club_personal' ? ($validated['sub_role'] ?? null) : null,
        ];
    }

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
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'scope_id' => null,
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

        $subRolesExist = SubRole::query()->exists();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Rules\Password::defaults()],
            'profile_type' => ['required', Rule::in($this->superadminManageableProfiles())],
            'sub_role' => ['nullable', 'string'],
            'church_id' => ['nullable', 'exists:churches,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'association_id' => ['nullable', 'exists:associations,id'],
            'union_id' => ['nullable', 'exists:unions,id'],
        ]);

        if (
            ($validated['profile_type'] ?? null) === 'club_personal'
            && $subRolesExist
            && empty($validated['sub_role'])
        ) {
                return back()->withErrors(['sub_role' => 'Sub role is required for club personal.'])->withInput();
        }

        if (in_array($validated['profile_type'], ['club_director', 'club_personal'], true) && !empty($validated['club_id'])) {
            $clubBelongsToChurch = Club::where('id', $validated['club_id'])
                ->where('church_id', $validated['church_id'])
                ->exists();

            if (!$clubBelongsToChurch) {
                return back()->withErrors(['club_id' => 'Selected club does not belong to selected church.'])->withInput();
            }
        }

        $scopeData = $this->resolveManagedUserScope($validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => $validated['profile_type'],
            'role_key' => $scopeData['role_key'],
            'scope_type' => $scopeData['scope_type'],
            'scope_id' => $scopeData['scope_id'],
            'sub_role' => $scopeData['sub_role'],
            'church_id' => $scopeData['church_id'],
            'church_name' => $scopeData['church_name'],
            'club_id' => $scopeData['club_id'],
            'status' => 'active',
        ]);

        if (in_array($validated['profile_type'], ['club_director', 'club_personal'], true) && !empty($validated['club_id'])) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $validated['club_id']],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            if ($validated['profile_type'] === 'club_director') {
                Club::where('id', $validated['club_id'])->update([
                    'user_id' => $user->id,
                    'director_name' => $user->name,
                    'church_id' => $validated['church_id'],
                    'church_name' => $scopeData['church_name'],
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

        $subRolesExist = SubRole::query()->exists();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Rules\Password::defaults()],
            'profile_type' => ['required', Rule::in($this->superadminManageableProfiles())],
            'sub_role' => ['nullable', 'string'],
            'church_id' => ['nullable', 'exists:churches,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'association_id' => ['nullable', 'exists:associations,id'],
            'union_id' => ['nullable', 'exists:unions,id'],
        ]);

        if (
            ($validated['profile_type'] ?? null) === 'club_personal'
            && $subRolesExist
            && empty($validated['sub_role'])
        ) {
                return back()->withErrors(['sub_role' => 'Sub role is required for club personal.'])->withInput();
        }

        if (in_array($validated['profile_type'], ['club_director', 'club_personal'], true) && !empty($validated['club_id'])) {
            $clubBelongsToChurch = Club::where('id', $validated['club_id'])
                ->where('church_id', $validated['church_id'])
                ->exists();

            if (!$clubBelongsToChurch) {
                return back()->withErrors(['club_id' => 'Selected club does not belong to selected church.'])->withInput();
            }
        }

        $scopeData = $this->resolveManagedUserScope($validated);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->profile_type = $validated['profile_type'];
        $user->role_key = $scopeData['role_key'];
        $user->scope_type = $scopeData['scope_type'];
        $user->scope_id = $scopeData['scope_id'];
        $user->sub_role = $scopeData['sub_role'];
        $user->church_id = $scopeData['church_id'];
        $user->church_name = $scopeData['church_name'];
        $user->club_id = $scopeData['club_id'];
        $user->status = $user->status ?: 'active';
        $user->save();

        DB::table('club_user')->where('user_id', $user->id)->delete();
        if (in_array($validated['profile_type'], ['club_director', 'club_personal'], true) && !empty($validated['club_id'])) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $user->id, 'club_id' => $validated['club_id']],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            if ($validated['profile_type'] === 'club_director') {
                Club::where('id', $validated['club_id'])->update([
                    'user_id' => $user->id,
                    'director_name' => $user->name,
                    'church_id' => $validated['church_id'],
                    'church_name' => $scopeData['church_name'],
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
            'role_key' => $validated['profile_type'],
            'scope_type' => $clubId ? 'club' : 'church',
            'scope_id' => $clubId ?: $validated['church_id'],
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
        $role = $user->role_key ?: $user->profile_type;

        return match ($role) {
            'club_director' => '/club-director/dashboard',
            'club_personal' => '/club-personal/dashboard',
            'district_pastor', 'district_secretary' => '/district/dashboard',
            'association_youth_director' => '/association/dashboard',
            'union_youth_director' => '/union/dashboard',
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
