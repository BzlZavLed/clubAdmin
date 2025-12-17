<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ClubClass;
use App\Models\Club;
use App\Models\Staff;
use App\Support\ClubHelper;
use Inertia\Inertia;

class AssistanceReportController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Step 1: Confirm staff record exists (staff table, fallback by email)
        $staff = Staff::with('classes')
            ->where('user_id', $user->id)
            ->first();
        if (!$staff) {
            $staff = Staff::whereHas('user', function ($q) use ($user) {
                $q->where('email', $user->email);
            })->with('classes')->first();
        }

        if (!$staff) {
            return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
                'auth_user' => $user,
                'staff' => $staff,
                'toast' => [
                    'type' => 'error',
                    'message' => 'You are not registered as a staff member.'
                ]
            ]);
        }

        // Step 2: Confirm class assigned (staff.assigned_class or first from pivot)
        $assignedClassId = $staff->assigned_class;
        if (!$assignedClassId && $staff->classes && $staff->classes->count()) {
            $assignedClassId = $staff->classes->first()->id;
        }

        $assignedClass = null;
        if ($assignedClassId) {
            $assignedClass = ClubClass::find($assignedClassId);
        }

        if (!$assignedClassId || !$assignedClass) {
            return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
                'auth_user' => $user,
                'staff' => $staff,
                'toast' => [
                    'type' => 'error',
                    'message' => 'No class assigned to you'
                ]
            ]);
        }

        // Step 3: Load assigned members from members table for this club+class.
        $clubId = $staff->club_id ?? $user->club_id;
        $club = $clubId ? Club::find($clubId) : null;
        $assignedMembers = ClubHelper::getMembersByClassAndClub((int)$clubId, (int)$assignedClassId)
            ->map(function ($m) {
                return [
                    // keep ids aligned with id_data so existing report payload works (mem_adv_id)
                    'id' => $m['id_data'],
                    'applicant_name' => $m['applicant_name'],
                    'member_type' => $m['member_type'],
                    'member_row_id' => $m['member_id'],
                ];
            })
            ->values();

        // All good
        return Inertia::render('ClubPersonal/AssistanceReport', [
            'auth_user' => $user,
            'club' => $club ? ['id' => $club->id, 'club_name' => $club->club_name, 'club_type' => $club->club_type] : null,
            'staff' => $staff,
            'assigned_class' => ['id' => $assignedClass->id, 'name' => $assignedClass->class_name],
            'assigned_members' => $assignedMembers,
        ]);
    }
    
}
