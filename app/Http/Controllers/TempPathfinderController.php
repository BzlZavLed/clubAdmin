<?php

namespace App\Http\Controllers;

use App\Models\MemberPathfinder;
use App\Models\StaffMasterGuide;
use App\Models\StaffPathfinder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Support\ClubHelper;

class TempPathfinderController extends Controller
{
    public function listMembers($clubId)
    {
        $this->authorizeClub($clubId);
        $rows = MemberPathfinder::where('club_id', $clubId)->orderByDesc('id')->get();
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

        return DB::transaction(function () use ($data) {
            // Create temp row first
            $row = MemberPathfinder::create($data);

            // Create member pointing to temp row id_data
            $member = \App\Models\Member::create([
                'type' => 'pathfinders',
                'club_id' => $data['club_id'],
                'id_data' => $row->id,
                'class_id' => null,
                'parent_id' => auth()->id(),
                'assigned_staff_id' => null,
                'status' => 'active',
            ]);

            // Link back the temp row to the member id (if column exists)
            $row->member_id = $member->id;
            $row->save();

            return response()->json($row, 201);
        });
    }

    public function listStaff($clubId)
    {
        $club = $this->authorizeClub($clubId);

        if (($club->club_type ?? null) === 'master_guide') {
            $rows = StaffMasterGuide::where('club_id', $clubId)
                ->orderByDesc('id')
                ->get()
                ->map(fn (StaffMasterGuide $row) => [
                    'id' => $row->id,
                    'club_id' => $row->club_id,
                    'user_id' => $row->user_id,
                    'staff_name' => $row->staff_name,
                    'staff_dob' => null,
                    'staff_age' => null,
                    'staff_email' => $row->email,
                    'staff_phone' => $row->phone,
                    'emergency_contact_name' => $row->emergency_contact_name,
                    'emergency_contact_phone' => $row->emergency_contact_phone,
                    'emergency_contact_email' => $row->emergency_contact_email,
                ]);

            return response()->json($rows);
        }

        $rows = StaffPathfinder::where('club_id', $clubId)->orderByDesc('id')->get();
        return response()->json($rows);
    }

    public function storeStaff(Request $request)
    {
        $clubId = $request->input('club_id');
        $club = $this->authorizeClub($clubId);

        $data = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'staff_name' => 'required|string|max:255',
            'staff_dob' => 'nullable|date',
            'staff_age' => 'nullable|integer|min:0|max:120',
            'staff_email' => 'nullable|email|max:255',
            'staff_phone' => 'nullable|string|max:50',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'emergency_contact_email' => 'nullable|email|max:255',
        ]);

        DB::beginTransaction();
        try {
            $churchId = $club?->church_id;
            $churchName = $club?->church_name;
            $staffType = ($club?->club_type === 'master_guide') ? 'master_guide' : 'pathfinders';

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

            if ($userId) {
                $staffRecord = Staff::firstOrCreate(
                    [
                        'club_id' => $data['club_id'],
                        'user_id' => $userId,
                        'type' => $staffType,
                    ],
                    [
                        'id_data' => 0,
                        'status' => 'active',
                        'assigned_class' => null,
                    ]
                );
            } else {
                $staffRecord = Staff::create([
                    'club_id' => $data['club_id'],
                    'user_id' => null,
                    'type' => $staffType,
                    'id_data' => 0,
                    'status' => 'active',
                    'assigned_class' => null,
                ]);
            }

            if ($staffType === 'master_guide') {
                $row = StaffMasterGuide::updateOrCreate(
                    ['staff_id' => $staffRecord->id],
                    [
                        'club_id' => $data['club_id'],
                        'user_id' => $userId,
                        'staff_id' => $staffRecord->id,
                        'staff_name' => $data['staff_name'],
                        'phone' => $data['staff_phone'] ?? null,
                        'email' => $data['staff_email'] ?? null,
                        'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                        'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                        'emergency_contact_email' => $data['emergency_contact_email'] ?? null,
                        'status' => 'active',
                    ]
                );
            } else {
                $row = StaffPathfinder::updateOrCreate(
                    ['staff_id' => $staffRecord->id],
                    array_merge($data, [
                        'user_id' => $userId,
                        'staff_id' => $staffRecord->id,
                    ])
                );
            }

            $staffRecord->id_data = $row->id;
            $staffRecord->save();

            DB::commit();
            return response()->json($row, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create temp staff', 'error' => $e->getMessage()], 422);
        }
    }

    protected function authorizeClub($clubId): \App\Models\Club
    {
        $user = Auth::user();
        if (!$user) abort(401);

        return ClubHelper::clubForUser($user, $clubId);
    }
}
