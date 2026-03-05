<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ClubClass;
use App\Models\Club;
use App\Models\Staff;
use App\Models\ClassPlan;
use App\Support\ClubHelper;
use Inertia\Inertia;

class AssistanceReportController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        [$staff, $assignedClass, $assignedClassId] = $this->resolveStaffAndClass($user);
        if (!$staff || !$assignedClass || !$assignedClassId) {
            return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
                'auth_user' => $user,
                'staff' => $staff,
                'toast' => [
                    'type' => 'error',
                    'message' => !$staff ? 'You are not registered as a staff member.' : 'No class assigned to you'
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
            'planned_requirement_activities' => $this->plannedRequirementActivities((int) $staff->id, (int) $assignedClassId, now()->toDateString()),
        ]);
    }

    public function requirementActivities(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $user = Auth::user();
        [$staff, $assignedClass, $assignedClassId] = $this->resolveStaffAndClass($user);
        if (!$staff || !$assignedClass || !$assignedClassId) {
            abort(422, 'No class assigned to current staff.');
        }

        return response()->json([
            'date' => $validated['date'],
            'activities' => $this->plannedRequirementActivities((int) $staff->id, (int) $assignedClassId, $validated['date']),
        ]);
    }

    private function resolveStaffAndClass($user): array
    {
        $staff = Staff::with('classes')
            ->where('user_id', $user->id)
            ->first();
        if (!$staff) {
            $staff = Staff::whereHas('user', function ($q) use ($user) {
                $q->where('email', $user->email);
            })->with('classes')->first();
        }
        if (!$staff) {
            return [null, null, null];
        }

        $assignedClassId = $staff->assigned_class;
        if (!$assignedClassId && $staff->classes && $staff->classes->count()) {
            $assignedClassId = $staff->classes->first()->id;
        }

        $assignedClass = $assignedClassId ? ClubClass::find($assignedClassId) : null;
        if (!$assignedClassId || !$assignedClass) {
            return [$staff, null, null];
        }

        return [$staff, $assignedClass, $assignedClassId];
    }

    private function plannedRequirementActivities(int $staffId, int $classId, string $date): array
    {
        $plans = ClassPlan::query()
            ->with(['event:id,date,title,meeting_type', 'investitureRequirement:id,title,description,sort_order'])
            ->where('staff_id', $staffId)
            ->where('class_id', $classId)
            ->whereNotNull('investiture_requirement_id')
            ->whereIn('status', ['approved', 'submitted', 'changes_requested'])
            ->where(function ($query) use ($date) {
                $query->whereDate('requested_date', $date)
                    ->orWhereHas('event', fn ($q) => $q->whereDate('date', $date));
            })
            ->orderBy('id')
            ->get();

        return $plans->map(function ($plan) {
            return [
                'id' => $plan->id,
                'title' => $plan->title,
                'requirement_id' => $plan->investitureRequirement?->id,
                'requirement_title' => $plan->investitureRequirement?->title,
                'requirement_sort_order' => $plan->investitureRequirement?->sort_order,
                'event_title' => $plan->event?->title,
                'event_date' => optional($plan->event?->date)->toDateString(),
            ];
        })->values()->all();
    }
}
