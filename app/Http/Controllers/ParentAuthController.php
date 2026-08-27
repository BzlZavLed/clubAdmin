<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\Club;
use App\Models\ClubParentEnrollmentLink;
use App\Models\MemberAdventurer;
use App\Models\ParentMember;
use App\Models\User;
use Auth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class ParentAuthController extends Controller
{
    public function showRegistrationForm()
    {
        return Inertia::render('Auth/RegisterParent');
    }

    public function showSecureRegistrationForm(string $token)
    {
        $link = $this->activeSecureLink($token);
        $club = $link->club;
        $church = $club->church?->loadMissing('district.association.union');

        return Inertia::render('Auth/RegisterParent', [
            'secure_enrollment' => [
                'submit_url' => route('parent.register.secure.store', ['token' => $token]),
                'church' => [
                    'id' => $church?->id,
                    'church_name' => $church?->church_name ?: $club->church_name,
                    'district_name' => $church?->district?->name,
                    'association_name' => $church?->district?->association?->name,
                    'union_name' => $church?->district?->association?->union?->name,
                    'evaluation_system' => $church?->district?->association?->union?->evaluation_system ?: 'honors',
                ],
                'club' => $club->only(['id', 'club_name', 'club_type', 'evaluation_system']),
            ],
        ]);
    }

    public function registerSecure(Request $request, string $token)
    {
        $link = $this->activeSecureLink($token);
        $club = $link->club;
        $this->normalizeRegistrationInput($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (User::query()->where('email', $value)->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))->exists()) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);
        try {
            $user = DB::transaction(function () use ($validated, $club, $link) {
                $userData = [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'profile_type' => 'parent',
                    'role_key' => 'parent',
                    'scope_type' => 'club',
                    'scope_id' => $club->id,
                    'church_id' => $club->church_id,
                    'church_name' => $club->church?->church_name ?: $club->church_name,
                    'club_id' => $club->id,
                    'status' => 'active',
                    'email_verified_at' => null,
                    'parent_activation_method' => null,
                    'secure_enrollment_link_id' => $link->id,
                    'enrollment_confirmed_at' => null,
                    'enrollment_confirmed_by' => null,
                ];
                $user = User::query()
                    ->where('email', $validated['email'])
                    ->where('status', 'deleted')
                    ->lockForUpdate()
                    ->first() ?: new User;
                $user->forceFill($userData)->save();

                DB::table('club_user')->updateOrInsert(
                    ['user_id' => $user->id, 'club_id' => $club->id],
                    ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
                $link->update(['last_used_at' => now()]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'is_in_club' => true,
            'user_club_ids' => [$club->id],
            'club_id' => $club->id,
            'church_name' => $user->church_name,
            'user' => $user,
            'email' => $user->email,
        ]);

        try {
            $user->sendEmailVerificationNotification();
            $status = 'verification-link-sent';
        } catch (Throwable $exception) {
            report($exception);
            $status = 'verification-delivery-failed';
        }

        return redirect()->route('verification.notice')->with('status', $status);
    }

    public function resolveInvite(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => ['required', 'string', 'max:32'],
        ]);

        $invite = $this->validInviteQuery($validated['invite_code'])
            ->with('church.district.association.union')
            ->first();

        if (! $invite || ($invite->uses_left !== null && $invite->uses_left <= 0)) {
            return response()->json(['message' => 'Invalid, expired, or fully used invite code.'], 422);
        }

        $church = $invite->church;
        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->where('church_id', $church->id)
            ->where('status', 'active')
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'evaluation_system']);

        return response()->json([
            'church' => [
                'id' => $church->id,
                'church_name' => $church->church_name,
                'district_name' => $church->district?->name,
                'association_name' => $church->district?->association?->name,
                'union_name' => $church->district?->association?->union?->name,
                'evaluation_system' => $church->district?->association?->union?->evaluation_system ?: 'honors',
            ],
            'clubs' => $clubs,
        ]);
    }

    public function register(Request $request)
    {
        $this->normalizeRegistrationInput($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'church_id' => 'required|exists:churches,id',
            'church_name' => 'required|string|max:255',
            'club_id' => 'required|exists:clubs,id',
            'invite_code' => ['required', 'string', 'max:32'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $invite = $this->validInviteQuery($validated['invite_code'])
                    ->where('church_id', (int) $validated['church_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $invite || ($invite->uses_left !== null && $invite->uses_left <= 0)) {
                    throw ValidationException::withMessages([
                        'invite_code' => 'Invalid, expired, or fully used invite code.',
                    ]);
                }

                $church = Church::query()->findOrFail($validated['church_id']);
                if ($church->church_name !== $validated['church_name']) {
                    throw ValidationException::withMessages([
                        'church_name' => 'Church does not match the invite code.',
                    ]);
                }

                $club = Club::query()
                    ->withoutGlobalScopes()
                    ->where('id', (int) $validated['club_id'])
                    ->where('church_id', (int) $validated['church_id'])
                    ->where('status', 'active')
                    ->first();

                if (! $club) {
                    throw ValidationException::withMessages([
                        'club_id' => 'Selected club is not valid for this church.',
                    ]);
                }

                $existingUser = User::query()
                    ->where('email', $validated['email'])
                    ->lockForUpdate()
                    ->first();

                if ($existingUser && $existingUser->status !== 'deleted') {
                    throw ValidationException::withMessages([
                        'email' => 'The email has already been taken.',
                    ]);
                }

                $userData = [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'profile_type' => 'parent',
                    'role_key' => 'parent',
                    'scope_type' => 'club',
                    'scope_id' => $club->id,
                    'church_id' => $church->id,
                    'church_name' => $church->church_name,
                    'club_id' => $club->id,
                    'status' => 'pending',
                ];
                $user = $existingUser ?: new User;
                $user->forceFill($userData)->save();

                DB::table('club_user')->updateOrInsert(
                    ['user_id' => $user->id, 'club_id' => $club->id],
                    ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );

                $memberMatches = match ($club->club_type) {
                    'adventurer', 'adventurers' => MemberAdventurer::query()
                        ->where('parent_name', $validated['name'])
                        ->where('club_id', $club->id)
                        ->get(),
                    default => collect(),
                };

                foreach ($memberMatches as $member) {
                    ParentMember::firstOrCreate([
                        'user_id' => $user->id,
                        'member_id' => $member->id,
                        'club_id' => $club->id,
                        'church_id' => $church->id,
                    ]);
                }

                if ($invite->uses_left !== null) {
                    $invite->decrement('uses_left');
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Registration submitted. Await director approval before logging in.');
    }

    protected function validInviteQuery(string $code)
    {
        return ChurchInviteCode::query()
            ->where('code', strtoupper(trim($code)))
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }

    private function normalizeRegistrationInput(Request $request): void
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'invite_code' => strtoupper(trim((string) $request->input('invite_code'))),
        ]);
    }

    private function activeSecureLink(string $token): ClubParentEnrollmentLink
    {
        return ClubParentEnrollmentLink::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->with('club.church.district.association.union')
            ->firstOrFail();
    }
}
