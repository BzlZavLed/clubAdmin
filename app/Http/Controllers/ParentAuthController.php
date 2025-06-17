<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Illuminate\Validation\Rules;
use Auth;
use App\Models\Church;
use App\Models\MemberAdventurer;
use App\Models\Club;
use App\Models\ParentMember;
class ParentAuthController extends Controller
{
    public function showRegistrationForm()
    {
        $churches = Church::select('id', 'church_name')->orderBy('church_name')->get();

        return Inertia::render('Auth/RegisterParent', [
            'churches' => $churches,
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
            'club_id' => 'required|exists:clubs,id', // ✅ Add this
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => 'parent',
            'church_id' => $validated['church_id'],
            'church_name' => $validated['church_name'],
            'club_id' => $validated['club_id'],
        ]);

        $club = Club::find($validated['club_id']);
        if (!$club) {
            return redirect()->back()->withErrors(['club_id' => 'Club not found.']);
        }

        $memberMatches = collect();

        switch ($club->club_type) {
            case 'adventurer':
                $memberMatches = MemberAdventurer::where([
                    ['parent_name', $validated['name']],
                    ['club_id', $club->id],
                ])->get();
                break;
            case 'pathfinder':
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
}
