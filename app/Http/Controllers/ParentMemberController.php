<?php

namespace App\Http\Controllers;

use App\Models\ParentMember;
use App\Models\Club;
use App\Models\MemberAdventurer; 
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\TempMemberPathfinder;
use Carbon\Carbon;

class ParentMemberController extends Controller
{
    // View all parent-member links
    public function index()
    {
        $parentId = auth()->id();
        $parent = auth()->user();
        $parentEmail = $parent?->email ? strtolower($parent->email) : null;

        // Ensure links exist for known records by matching on parent email (legacy data)
        if ($parentEmail) {
            $linkedAdvIds = Member::where('parent_id', $parentId)
                ->where('type', 'adventurers')
                ->pluck('id_data')
                ->all();

            $emailMatchedAdventurers = MemberAdventurer::whereRaw('LOWER(email_address) = ?', [$parentEmail])
                ->whereNotIn('id', $linkedAdvIds)
                ->get();

            foreach ($emailMatchedAdventurers as $adv) {
                Member::firstOrCreate(
                    [
                        'type' => 'adventurers',
                        'id_data' => $adv->id,
                    ],
                    [
                        'club_id' => $adv->club_id,
                        'class_id' => null,
                        'parent_id' => $parentId,
                        'assigned_staff_id' => null,
                        'status' => 'active',
                    ]
                );
            }

            $linkedTempIds = Member::where('parent_id', $parentId)
                ->where('type', 'temp_pathfinder')
                ->pluck('id_data')
                ->all();

            $emailMatchedTemps = TempMemberPathfinder::whereRaw('LOWER(email) = ?', [$parentEmail])
                ->whereNotIn('id', $linkedTempIds)
                ->get();

            foreach ($emailMatchedTemps as $temp) {
                $member = Member::firstOrCreate(
                    [
                        'type' => 'temp_pathfinder',
                        'id_data' => $temp->id,
                    ],
                    [
                        'club_id' => $temp->club_id,
                        'class_id' => null,
                        'parent_id' => $parentId,
                        'assigned_staff_id' => null,
                        'status' => 'active',
                    ]
                );
                if (!$temp->member_id) {
                    $temp->update(['member_id' => $member->id]);
                }
            }
        }

        // Members table holds type + linkage to parent
        $memberLinks = Member::where('parent_id', $parentId)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->get(['id', 'id_data', 'club_id', 'parent_id', 'type']);

        $adventurerIds = $memberLinks->where('type', 'adventurers')->pluck('id_data')->all();
        $pathfinderIds = $memberLinks->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')->all();
        $clubMap = Club::whereIn('id', $memberLinks->pluck('club_id')->filter()->unique())
            ->pluck('club_name', 'id');

        $adventurerChildren = MemberAdventurer::whereIn('id', $adventurerIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($child) use ($memberLinks, $clubMap) {
                $member = $memberLinks->firstWhere('id_data', $child->id);
                $child->member_id = $member?->id;
                $child->club_id = $member?->club_id;
                $child->club_name = $member?->club_id ? ($clubMap[$member->club_id] ?? null) : null;
                $child->member_type = 'adventurers';
                return $child;
            });

        $pathfinderRows = TempMemberPathfinder::whereIn('id', $pathfinderIds)->get();
        $pathfinderChildren = $pathfinderRows->map(function ($row) use ($memberLinks, $clubMap) {
            $member = $memberLinks->firstWhere('id_data', $row->id);
            return [
                'id' => $row->id,
                'member_id' => $member?->id,
                'member_type' => 'temp_pathfinder',
                'club_id' => $member?->club_id,
                'club_name' => $member?->club_id ? ($clubMap[$member->club_id] ?? null) : null,
                'applicant_name' => $row->nombre,
                'birthdate' => $row->dob,
                'age' => $row->dob ? Carbon::parse($row->dob)->age : null,
                'grade' => null,
                'mailing_address' => null,
                'cell_number' => $row->phone,
                'emergency_contact' => null,
                'investiture_classes' => [],
                'allergies' => null,
                'physical_restrictions' => null,
                'health_history' => null,
                'parent_name' => $row->father_name,
                'parent_cell' => $row->father_phone,
                'home_address' => null,
                'email_address' => $row->email,
                'signature' => null,
                'status' => 'active',
            ];
        });

        $children = $adventurerChildren->concat($pathfinderChildren)->values();

        return inertia('Parent/Children', [
            'children' => $children,
        ]);
    }

    public function linkable()
    {
        $parent = auth()->user();
        if (!$parent) {
            abort(401);
        }
        $parentId = $parent->id;
        $parentName = strtolower($parent->name ?? '');
        $parentEmail = strtolower($parent->email ?? '');
        $churchId = $parent->church_id;

        if (!$churchId) {
            return response()->json([
                'linkable' => [],
            ]);
        }

        $clubIds = Club::where('church_id', $churchId)->pluck('id')->all();
        if (empty($clubIds)) {
            return response()->json([
                'linkable' => [],
            ]);
        }

        // Only exclude members already linked to any parent
        $linkedAdvIds = Member::whereNotNull('parent_id')
            ->where('type', 'adventurers')
            ->pluck('id_data')
            ->all();
        $linkedTempIds = Member::whereNotNull('parent_id')
            ->where('type', 'temp_pathfinder')
            ->pluck('id_data')
            ->all();

        // Adventurers: match on parent_name OR emergency_contact OR email_address
        $advCandidates = MemberAdventurer::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedAdvIds)
            ->where(function ($q) use ($parentName, $parentEmail) {
                $q->whereRaw('LOWER(parent_name) = ?', [$parentName])
                    ->orWhereRaw('LOWER(emergency_contact) = ?', [$parentName])
                    ->orWhereRaw('LOWER(email_address) = ?', [$parentEmail]);
            })
            ->limit(20)
            ->get()
            ->map(function ($row) {
                return [
                    'member_type' => 'adventurers',
                    'id_data' => $row->id,
                    'display_name' => $row->applicant_name,
                    'club_id' => $row->club_id,
                    'detail' => 'Adventurer',
                ];
            });

        // Pathfinder temp: match on father_name OR email
        $pathfinderCandidates = TempMemberPathfinder::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedTempIds)
            ->where(function ($q) use ($parentName, $parentEmail) {
                $q->whereRaw('LOWER(father_name) = ?', [$parentName])
                    ->orWhereRaw('LOWER(email) = ?', [$parentEmail]);
            })
            ->limit(20)
            ->get()
            ->map(function ($row) {
                return [
                    'member_type' => 'temp_pathfinder',
                    'id_data' => $row->id,
                    'display_name' => $row->nombre,
                    'club_id' => $row->club_id,
                    'detail' => 'Pathfinder (temp)',
                ];
            });

        $clubs = Club::whereIn('id', $advCandidates->pluck('club_id')
            ->merge($pathfinderCandidates->pluck('club_id'))
            ->filter()
            ->unique())->pluck('club_name', 'id');

        $payload = $advCandidates->concat($pathfinderCandidates)->map(function ($item) use ($clubs) {
            $item['club_name'] = $item['club_id'] ? ($clubs[$item['club_id']] ?? null) : null;
            return $item;
        })->values();

        return response()->json([
            'linkable' => $payload,
        ]);
    }

    public function link(Request $request)
    {
        $parent = auth()->user();
        if (!$parent) {
            abort(401);
        }

        $data = $request->validate([
            'member_type' => 'required|in:adventurers,temp_pathfinder,pathfinders',
            'id_data' => 'required|integer',
        ]);

        if ($data['member_type'] === 'adventurers') {
            $exists = MemberAdventurer::findOrFail($data['id_data']);
            $member = Member::firstOrCreate(
                [
                    'type' => 'adventurers',
                    'id_data' => $exists->id,
                ],
                [
                    'club_id' => $exists->club_id,
                    'class_id' => null,
                    'parent_id' => $parent->id,
                    'assigned_staff_id' => null,
                    'status' => 'active',
                ]
            );
            $member->parent_id = $parent->id;
            $member->save();
        } else {
            $exists = TempMemberPathfinder::findOrFail($data['id_data']);
            $member = Member::firstOrCreate(
                [
                    'type' => 'temp_pathfinder',
                    'id_data' => $exists->id,
                ],
                [
                    'club_id' => $exists->club_id,
                    'class_id' => null,
                    'parent_id' => $parent->id,
                    'assigned_staff_id' => null,
                    'status' => 'active',
                ]
            );
            $member->parent_id = $parent->id;
            $member->save();
            if (!$exists->member_id) {
                $exists->update(['member_id' => $member->id]);
            }
        }

        return response()->json([
            'message' => 'Member linked to your account.',
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

    public function update(Request $request, $id)
    {
        $parentId = auth()->id();
        $memberType = $request->input('member_type', 'adventurers');

        if ($memberType === 'temp_pathfinder' || $memberType === 'pathfinders') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'birthdate' => 'required|date',
                'cell_number' => 'required|string|max:255',
                'email_address' => 'required|email',
                'parent_name' => 'required|string|max:255',
                'parent_cell' => 'required|string|max:255',
            ]);

            $tempMember = TempMemberPathfinder::findOrFail($id);

            $link = Member::where('type', 'temp_pathfinder')
                ->where('id_data', $tempMember->id)
                ->where('parent_id', $parentId)
                ->firstOrFail();

            $tempMember->update([
                'nombre' => $validated['applicant_name'],
                'dob' => $validated['birthdate'],
                'phone' => $validated['cell_number'],
                'email' => $validated['email_address'],
                'father_name' => $validated['parent_name'],
                'father_phone' => $validated['parent_cell'],
            ]);

            return redirect()->back()->with('success', 'Child updated.');
        }

        $member = MemberAdventurer::findOrFail($id);

        $link = Member::where('type', 'adventurers')
            ->where('id_data', $member->id)
            ->where('parent_id', $parentId)
            ->firstOrFail();

        $validated = $request->validate([
            'applicant_name' => 'required|string|max:255',
            'birthdate' => 'required|date',
            'age' => 'required|integer|min:1|max:99',
            'grade' => 'required|string|max:20',
            'mailing_address' => 'required|string',
            'cell_number' => 'required|string',
            'emergency_contact' => 'required|string',
            'investiture_classes' => 'nullable|array',
            'allergies' => 'nullable|string',
            'physical_restrictions' => 'nullable|string',
            'health_history' => 'nullable|string',
            'parent_name' => 'required|string|max:255',
            'parent_cell' => 'required|string|max:255',
            'home_address' => 'required|string',
            'email_address' => 'required|email',
            'signature' => 'required|string|max:255',
        ]);

        $member->update($validated);

        return redirect()->back()->with('success', 'Child updated.');
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
