<?php

namespace App\Http\Controllers;

use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Redirect;
use App\Models\Church;
class ClubController extends Controller
{
    use AuthorizesRequests;
    public function store(Request $request)
    {
        if (auth()->user()->profile_type !== 'club_director') {
            abort(403, 'Only club directors can create a club.');
        }
        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_name' => 'required|string|max:255',
            'director_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'church_id' => 'required|exists:churches,id',
        ]);

        $club = Club::create(array_merge($validated, [
            'user_id' => auth()->id(),
        ]));
        // Link user to this club in pivot table with status
        $club->users()->attach(auth()->id(), ['status' => 'active']);

        $user = auth()->user();
        $user->club_id = $club->id;
        $user->save();

        return redirect()->route('club.my-club')
            ->with('success', 'Club created successfully!');
    }

    public function show()
    {
        $club = Club::where('user_id', auth()->id())->firstOrFail();

        $this->authorize('view', $club);

        return response()->json($club);
    }

    public function update(Request $request)
    {
        // Remove policy if not using it
        $club = Club::where('user_id', auth()->id())->firstOrFail();

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
        ]);

        $club->update($validated);

        // Inertia expects a redirect with optional flash messages
        return redirect()->back()->with('success', 'Club updated successfully.');
    }


    public function destroy(Request $request)
    {
        $clubId = $request->input('id');

        $club = Club::findOrFail($clubId);

        // Confirm the user belongs to this club
        if (!$club->users()->where('user_id', auth()->id())->exists()) {
            abort(403);
        }

        $club->update(['status' => 'deleted']);

        return response()->json(['message' => 'Club deleted successfully.']);
    }
    public function getByIds(Request $request)
    {
        $ids = (array) $request->input('ids', []);

        $clubs = Club::whereIn('id', $ids)->get();

        return response()->json($clubs);
    }

    public function getByChurchNames(Request $request)
    {
        $input = $request->input('church_name', []);

        // Normalize to array
        $names = is_array($input) ? $input : [$input];

        $clubs = Club::whereIn('church_name', $names)->get();

        return response()->json($clubs);
    }

    public function getByChurch(Church $church)
    {
        return $church->clubs()->select('id', 'club_name', 'club_type')->orderBy('club_name')->get();
    }
}
