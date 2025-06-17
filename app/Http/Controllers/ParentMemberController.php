<?php

namespace App\Http\Controllers;

use App\Models\ParentMember;
use App\Models\Club;
use App\Models\MemberAdventurer; 
use App\Models\User;
use Illuminate\Http\Request;

class ParentMemberController extends Controller
{
    // View all parent-member links
    public function index()
    {
        $links = ParentMember::with(['user', 'club', 'church'])->get();

        return inertia('ParentLinks/Index', [
            'links' => $links,
        ]);
    }

    // View all parent links for a specific member
    public function show($memberId)
    {
        $links = ParentMember::with(['user', 'club', 'church'])
            ->where('member_id', $memberId)
            ->get();

        return inertia('ParentLinks/Show', [
            'links' => $links,
        ]);
    }

    // Manually link a parent to a member
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'member_id' => 'required|integer',
            'club_id' => 'required|exists:clubs,id',
            'church_id' => 'required|exists:churches,id',
        ]);

        ParentMember::firstOrCreate($validated);

        return back()->with('success', 'Parent linked to member successfully.');
    }

    // Remove a link
    public function destroy($id)
    {
        $link = ParentMember::findOrFail($id);
        $link->delete();

        return back()->with('success', 'Link removed successfully.');
    }
}
