<?php

namespace App\Http\Controllers;

use App\Models\ParentMember;
use App\Models\Club;
use App\Models\MemberAdventurer; 
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Member;

class ParentMemberController extends Controller
{
    // View all parent-member links
    public function index()
    {
        $parentId = auth()->id();

        // Members table holds type + linkage to parent
        $memberLinks = Member::where('parent_id', $parentId)
            ->where('type', 'adventurers')
            ->get(['id', 'id_data', 'club_id', 'parent_id']);

        $adventurerIds = $memberLinks->pluck('id_data')->all();
        $clubMap = Club::whereIn('id', $memberLinks->pluck('club_id')->filter()->unique())
            ->pluck('club_name', 'id');

        $children = MemberAdventurer::whereIn('id', $adventurerIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($child) use ($memberLinks, $clubMap) {
                $member = $memberLinks->firstWhere('id_data', $child->id);
                $child->member_id = $member?->id;
                $child->club_id = $member?->club_id;
                $child->club_name = $member?->club_id ? ($clubMap[$member->club_id] ?? null) : null;
                return $child;
            });

        return inertia('Parent/Children', [
            'children' => $children,
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
