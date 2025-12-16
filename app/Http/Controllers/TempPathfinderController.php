<?php

namespace App\Http\Controllers;

use App\Models\TempMemberPathfinder;
use App\Models\TempStaffPathfinder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        DB::beginTransaction();
        try {
            $club = \App\Models\Club::find($data['club_id']);
            $churchId = $club?->church_id;
            $churchName = $club?->church_name;

            // create or find user by email
            $userId = null;
            if (!empty($data['staff_email'])) {
                $user = User::firstOrCreate(
                    ['email' => $data['staff_email']],
                    [
                        'name' => $data['staff_name'],
                        'church_id' => $churchId,
                        'church_name' => $churchName,
                        'club_id' => $data['club_id'],
                        'profile_type' => 'club_personal',
                        'sub_role' => 'staff',
                        'status' => 'active',
                        'password' => bcrypt(Str::random(12)),
                    ]
                );
                $userId = $user->id;

                DB::table('club_user')->updateOrInsert(
                    ['user_id' => $userId, 'club_id' => $data['club_id']],
                    ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
            }

            // Create temp staff record first (needs user_id if available)
            $row = TempStaffPathfinder::create(array_merge($data, ['user_id' => $userId]));

            // Also create a staff record (pending) so it can be selected elsewhere
            if ($userId) {
                $staffRecord = Staff::updateOrCreate(
                    [
                        'club_id' => $data['club_id'],
                        'user_id' => $userId,
                        'type' => 'temp_pathfinder',
                    ],
                    [
                        'status' => 'active',
                        'assigned_class' => null,
                        'id_data' => $row->id,
                    ]
                );
                // If staff_id column exists, store it for easier lookup
                if (Schema::hasColumn('temp_staff_pathfinder', 'staff_id')) {
                    $row->staff_id = $staffRecord->id;
                    $row->save();
                }
            }
            DB::commit();
            return response()->json($row, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create temp staff', 'error' => $e->getMessage()], 422);
        }
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
