<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Illuminate\Validation\Rules;
use Auth;
use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\MemberAdventurer;
use App\Models\Club;
use App\Models\ParentMember;
class ParentAuthController extends Controller
{
    public function showRegistrationForm()
    {
        return Inertia::render('Auth/RegisterParent');
    }

    public function resolveInvite(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => ['required', 'string'],
        ]);

        $invite = $this->validInviteQuery($validated['invite_code'])
            ->with('church.district.association.union')
            ->first();

        if (!$invite || $invite->uses_left === 0) {
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'church_id' => 'required|exists:churches,id',
            'church_name' => 'required|string|max:255',
            'club_id' => 'required|exists:clubs,id',
            'invite_code' => ['required', 'string'],
        ]);

        $invite = $this->validInviteQuery($validated['invite_code'])
            ->where('church_id', (int) $validated['church_id'])
            ->first();

        if (!$invite || $invite->uses_left === 0) {
            return back()->withErrors(['invite_code' => 'Invalid, expired, or fully used invite code.'])->withInput();
        }

        $church = Church::query()->findOrFail($validated['church_id']);
        if ($church->church_name !== $validated['church_name']) {
            return back()->withErrors(['church_name' => 'Church does not match the invite code.'])->withInput();
        }

        $club = Club::query()
            ->withoutGlobalScopes()
            ->where('id', (int) $validated['club_id'])
            ->where('church_id', (int) $validated['church_id'])
            ->where('status', 'active')
            ->first();

        if (!$club) {
            return back()->withErrors(['club_id' => 'Selected club is not valid for this church.'])->withInput();
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => 'parent',
            'church_id' => $validated['church_id'],
            'church_name' => $validated['church_name'],
            'club_id' => $validated['club_id'],
        ]);

        if ($invite->uses_left !== null) {
            $invite->decrement('uses_left');
        }

        $memberMatches = collect();

        switch ($club->club_type) {
            case 'adventurer':
            case 'adventurers':
                $memberMatches = MemberAdventurer::where([
                    ['parent_name', $validated['name']],
                    ['club_id', $club->id],
                ])->get();
                break;
            case 'pathfinder':
            case 'pathfinders':
                // Future: $memberMatches = MemberPathfinder::where(...)->get();
                break;
            case 'guide':
                // Future support
                break;
        }

        foreach ($memberMatches as $member) {
            ParentMember::firstOrCreate([
                'user_id' => $user->id,
                'member_id' => $member->id,
                'club_id' => $club->id,
                'church_id' => $validated['church_id'],
            ]);
        }

        return redirect()->route('parent.apply');
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
}
