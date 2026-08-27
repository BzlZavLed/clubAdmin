<?php

namespace App\Http\Controllers;

use App\Models\ParentMember;
use App\Models\Club;
use App\Models\MemberAdventurer; 
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\MemberPathfinder;
use App\Models\ParentChildLinkRequest;
use App\Services\ParentChildIdentityMatcher;
use Carbon\Carbon;

class ParentMemberController extends Controller
{
    public function __construct(private readonly ParentChildIdentityMatcher $identityMatcher)
    {
    }

    // View all parent-member links
    public function index()
    {
        $parentId = auth()->id();
        $parent = auth()->user();
        $parentEmail = $parent?->email ? strtolower($parent->email) : null;
        $homeClubIds = $parent?->church_id
            ? Club::query()->where('church_id', $parent->church_id)->pluck('id')->all()
            : [];

        // Safely attach legacy records in the account church only when all identity fields match.
        if ($parentEmail && !empty($homeClubIds)) {
            $linkedAdvIds = Member::where('parent_id', $parentId)
                ->where('type', 'adventurers')
                ->pluck('id_data')
                ->all();

            $emailMatchedAdventurers = MemberAdventurer::whereRaw('LOWER(email_address) = ?', [$parentEmail])
                ->whereIn('club_id', $homeClubIds)
                ->whereNotIn('id', $linkedAdvIds)
                ->get()
                ->filter(fn (MemberAdventurer $row) => $this->identityMatcher->evaluate($parent, $row)['can_link_immediately']);

            foreach ($emailMatchedAdventurers as $adv) {
                $member = Member::firstOrNew([
                    'type' => 'adventurers',
                    'id_data' => $adv->id,
                ]);
                if (!$member->exists || !$member->parent_id) {
                    $member->fill([
                        'club_id' => $adv->club_id,
                        'class_id' => null,
                        'parent_id' => $parentId,
                        'assigned_staff_id' => null,
                        'status' => 'active',
                    ])->save();
                }
            }

            $linkedTempIds = Member::where('parent_id', $parentId)
                ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
                ->pluck('id_data')
                ->all();

            $emailMatchedTemps = MemberPathfinder::query()
                ->whereIn('club_id', $homeClubIds)
                ->where(function ($query) use ($parentEmail) {
                    $query->whereRaw('LOWER(father_guardian_email) = ?', [$parentEmail])
                        ->orWhereRaw('LOWER(mother_guardian_email) = ?', [$parentEmail]);
                })
                ->whereNotIn('id', $linkedTempIds)
                ->get()
                ->filter(fn (MemberPathfinder $row) => $this->identityMatcher->evaluate($parent, $row)['can_link_immediately']);

            foreach ($emailMatchedTemps as $temp) {
                $member = Member::firstOrNew([
                    'type' => 'pathfinders',
                    'id_data' => $temp->id,
                ]);
                if (!$member->exists || !$member->parent_id) {
                    $member->fill([
                        'club_id' => $temp->club_id,
                        'class_id' => null,
                        'parent_id' => $parentId,
                        'assigned_staff_id' => null,
                        'status' => 'active',
                    ])->save();
                }
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
        $clubMap = Club::with('church:id,church_name')
            ->whereIn('id', $memberLinks->pluck('club_id')->filter()->unique())
            ->get(['id', 'club_name', 'church_id', 'church_name'])
            ->keyBy('id');

        $adventurerChildren = MemberAdventurer::whereIn('id', $adventurerIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($child) use ($memberLinks, $clubMap) {
                $member = $memberLinks->firstWhere('id_data', $child->id);
                $club = $member?->club_id ? $clubMap->get($member->club_id) : null;
                $child->member_id = $member?->id;
                $child->club_id = $member?->club_id;
                $child->club_name = $club?->club_name;
                $child->church_name = $club?->church?->church_name ?: $club?->church_name;
                $child->member_type = 'adventurers';
                return $child;
            });

        $pathfinderRows = MemberPathfinder::whereIn('id', $pathfinderIds)->get();
        $pathfinderChildren = $pathfinderRows->map(function ($row) use ($memberLinks, $clubMap) {
            $member = $memberLinks->firstWhere('id_data', $row->id);
            $club = $member?->club_id ? $clubMap->get($member->club_id) : null;
            return [
                'id' => $row->id,
                'member_id' => $member?->id,
                'member_type' => 'temp_pathfinder',
                'club_id' => $member?->club_id,
                'club_name' => $club?->club_name,
                'church_name' => $club?->church?->church_name ?: $club?->church_name,
                'applicant_name' => $row->applicant_name,
                'birthdate' => $row->birthdate,
                'age' => $row->birthdate ? Carbon::parse($row->birthdate)->age : null,
                'grade' => null,
                'mailing_address' => null,
                'cell_number' => $row->cell_number,
                'emergency_contact' => null,
                'investiture_classes' => [],
                'allergies' => null,
                'physical_restrictions' => null,
                'health_history' => null,
                'parent_name' => $row->father_guardian_name,
                'parent_cell' => $row->father_guardian_phone,
                'home_address' => null,
                'email_address' => $row->email_address,
                'signature' => null,
                'status' => 'active',
            ];
        });

        $children = $adventurerChildren->concat($pathfinderChildren)->values();

        return inertia('Parent/Children', [
            'children' => $children,
            'link_requests' => $this->parentLinkRequests($parent),
        ]);
    }

    public function linkable()
    {
        $parent = auth()->user();
        if (!$parent) {
            abort(401);
        }
        $parentId = $parent->id;
        $churchId = $parent->church_id;
        $searchName = strtolower(trim(request()->input('name', '')));

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
            ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
            ->pluck('id_data')
            ->all();

        $advCandidates = MemberAdventurer::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedAdvIds)
            ->when($searchName !== '', function ($q) use ($searchName) {
                $q->whereRaw('LOWER(applicant_name) LIKE ?', ['%' . $searchName . '%']);
            })
            ->get()
            ->map(function (MemberAdventurer $row) use ($parent) {
                $evaluation = $this->identityMatcher->evaluate($parent, $row);
                if (!$evaluation['eligible']) return null;

                return [
                    'member_type' => 'adventurers',
                    'id_data' => $row->id,
                    'display_name' => $row->applicant_name,
                    'club_id' => $row->club_id,
                    'detail' => 'Adventurer',
                    'match_factors' => $evaluation['factors'],
                    'matched_count' => $evaluation['matched_count'],
                    'requires_director_approval' => $evaluation['requires_director_approval'],
                ];
            })
            ->filter()
            ->take(20)
            ->values();

        $pathfinderCandidates = MemberPathfinder::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedTempIds)
            ->when($searchName !== '', function ($q) use ($searchName) {
                $q->whereRaw('LOWER(applicant_name) LIKE ?', ['%' . $searchName . '%']);
            })
            ->get()
            ->map(function (MemberPathfinder $row) use ($parent) {
                $evaluation = $this->identityMatcher->evaluate($parent, $row);
                if (!$evaluation['eligible']) return null;

                return [
                    'member_type' => 'temp_pathfinder',
                    'id_data' => $row->id,
                    'display_name' => $row->applicant_name,
                    'club_id' => $row->club_id,
                    'detail' => 'Pathfinder',
                    'match_factors' => $evaluation['factors'],
                    'matched_count' => $evaluation['matched_count'],
                    'requires_director_approval' => $evaluation['requires_director_approval'],
                ];
            })
            ->filter()
            ->take(20)
            ->values();

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
            $memberType = 'adventurers';
        } else {
            $exists = MemberPathfinder::findOrFail($data['id_data']);
            $memberType = 'pathfinders';
        }

        abort_unless(
            $parent->church_id
                && Club::query()
                    ->whereKey($exists->club_id)
                    ->where('church_id', $parent->church_id)
                    ->exists(),
            403,
            'Parents may manually link children only within their account church.'
        );
        $evaluation = $this->identityMatcher->evaluate($parent, $exists);
        abort_unless($evaluation['eligible'], 422, 'At least two verified identity factors must match.');

        $member = Member::query()
            ->whereIn('type', $memberType === 'pathfinders'
                ? ['pathfinders', 'temp_pathfinder']
                : ['adventurers'])
            ->where('id_data', $exists->id)
            ->first();
        abort_if(
            $member?->parent_id && (int) $member->parent_id !== (int) $parent->id,
            409,
            'This child is already linked to another parent account.'
        );

        if (!$evaluation['can_link_immediately']) {
            ParentChildLinkRequest::query()
                ->where('parent_user_id', $parent->id)
                ->where('member_type', $memberType)
                ->where('id_data', $exists->id)
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'decided_at' => now()]);

            $latestRejected = ParentChildLinkRequest::query()
                ->where('parent_user_id', $parent->id)
                ->where('member_type', $memberType)
                ->where('id_data', $exists->id)
                ->where('status', 'rejected')
                ->latest('decided_at')
                ->first();
            abort_if(
                $latestRejected
                    && $latestRejected->match_factors === $evaluation['factors']
                    && $latestRejected->identity_snapshot === $evaluation['snapshot'],
                409,
                'This request was rejected. Update the mismatched information or contact the club director before trying again.'
            );

            $linkRequest = ParentChildLinkRequest::query()->firstOrCreate(
                [
                    'parent_user_id' => $parent->id,
                    'member_type' => $memberType,
                    'id_data' => $exists->id,
                    'status' => 'pending',
                ],
                [
                    'member_id' => $member?->id,
                    'club_id' => $exists->club_id,
                    'match_factors' => $evaluation['factors'],
                    'matched_count' => $evaluation['matched_count'],
                    'identity_snapshot' => $evaluation['snapshot'],
                    'requested_at' => now(),
                    'expires_at' => now()->addDays(30),
                ]
            );

            return response()->json([
                'message' => 'A director confirmation request was created.',
                'status' => 'pending',
                'request_id' => $linkRequest->id,
            ], 202);
        }

        $this->linkResolvedMember($parent, $exists, $memberType, $member);

        return response()->json([
            'message' => 'Member linked to your account.',
        ]);
    }

    private function linkResolvedMember(
        User $parent,
        MemberAdventurer|MemberPathfinder $detail,
        string $memberType,
        ?Member $member = null
    ): Member
    {
        if (!$member) {
            $member = Member::query()->create([
                'type' => $memberType,
                'id_data' => $detail->id,
                'club_id' => $detail->club_id,
                'class_id' => null,
                'parent_id' => $parent->id,
                'assigned_staff_id' => null,
                'status' => 'active',
            ]);
        }
        $member->forceFill(['parent_id' => $parent->id])->save();

        if ($detail instanceof MemberPathfinder && !$detail->member_id) {
            $detail->update(['member_id' => $member->id]);
        }

        return $member;
    }

    private function parentLinkRequests(User $parent): array
    {
        ParentChildLinkRequest::query()
            ->where('parent_user_id', $parent->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'decided_at' => now()]);

        return ParentChildLinkRequest::query()
            ->with(['club:id,club_name'])
            ->where('parent_user_id', $parent->id)
            ->latest('requested_at')
            ->limit(20)
            ->get()
            ->map(function (ParentChildLinkRequest $linkRequest) {
                $detail = $this->identityMatcher->detail($linkRequest->member_type, $linkRequest->id_data);

                return [
                    'id' => $linkRequest->id,
                    'child_name' => $detail?->applicant_name ?: data_get($linkRequest->identity_snapshot, 'member_name', '—'),
                    'club_name' => $linkRequest->club?->club_name,
                    'status' => $linkRequest->status,
                    'matched_count' => $linkRequest->matched_count,
                    'match_factors' => $linkRequest->match_factors,
                    'requested_at' => $linkRequest->requested_at?->toIso8601String(),
                    'expires_at' => $linkRequest->expires_at?->toIso8601String(),
                    'decided_at' => $linkRequest->decided_at?->toIso8601String(),
                    'decision_note' => $linkRequest->decision_note,
                ];
            })
            ->values()
            ->all();
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

            $tempMember = MemberPathfinder::findOrFail($id);

            $link = Member::whereIn('type', ['temp_pathfinder', 'pathfinders'])
                ->where('id_data', $tempMember->id)
                ->where('parent_id', $parentId)
                ->firstOrFail();

            $tempMember->update([
                'applicant_name' => $validated['applicant_name'],
                'birthdate' => $validated['birthdate'],
                'cell_number' => $validated['cell_number'],
                'email_address' => $validated['email_address'],
                'father_guardian_name' => $validated['parent_name'],
                'father_guardian_phone' => $validated['parent_cell'],
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
