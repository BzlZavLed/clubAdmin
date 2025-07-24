<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\StaffAdventurer;
use App\Models\ClubClass;
use App\Models\Club;
use Inertia\Inertia;

class AssistanceReportController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Step 1: Confirm staff record exists
        $staff = StaffAdventurer::where('email', $user->email)->first();
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

        // Step 2: Confirm class assigned
        $assignedClass = ClubClass::where('assigned_staff_id', $staff->id)->first();
        if (!$assignedClass) {
            return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
                'auth_user' => $user,
                'staff' => $staff,
                'toast' => [
                    'type' => 'error',
                    'message' => 'No class assigned to you'
                ]
            ]);
        }

        // All good
        return Inertia::render('ClubPersonal/AssistanceReport', [
            'auth_user' => $user,
            'clubs' => Club::all(),
            'staff' => $staff
        ]);
    }
    
}
