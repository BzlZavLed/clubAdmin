<?php

namespace App\Http\Controllers;

use App\Models\TempMemberPathfinder;
use App\Models\TempStaffPathfinder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TempPathfinderController extends Controller
{
    public function listMembers($clubId)
    {
        $this->authorizeClub($clubId);
        $rows = TempMemberPathfinder::where('club_id', $clubId)->orderByDesc('id')->get();
        return response()->json($rows);
    }

    public function storeMember(Request $request)
    {
        $clubId = $request->input('club_id');
        $this->authorizeClub($clubId);

        $data = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'nombre' => 'required|string|max:255',
            'dob' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'father_name' => 'nullable|string|max:255',
            'father_phone' => 'nullable|string|max:50',
        ]);

        $row = TempMemberPathfinder::create($data);
        return response()->json($row, 201);
    }

    public function listStaff($clubId)
    {
        $this->authorizeClub($clubId);
        $rows = TempStaffPathfinder::where('club_id', $clubId)->orderByDesc('id')->get();
        return response()->json($rows);
    }

    public function storeStaff(Request $request)
    {
        $clubId = $request->input('club_id');
        $this->authorizeClub($clubId);

        $data = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'staff_name' => 'required|string|max:255',
            'staff_dob' => 'nullable|date',
            'staff_age' => 'nullable|integer|min:0|max:120',
            'staff_email' => 'nullable|email|max:255',
            'staff_phone' => 'nullable|string|max:50',
        ]);

        $row = TempStaffPathfinder::create($data);
        return response()->json($row, 201);
    }

    protected function authorizeClub($clubId): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        $allowed = $user->clubs()->pluck('clubs.id')->toArray();
        if (!in_array((int)$clubId, $allowed, true)) {
            abort(403, 'Unauthorized');
        }
    }
}
