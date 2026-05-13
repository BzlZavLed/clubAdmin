<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ClubClass;
use App\Models\ClubCarpetaClassActivation;
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
        $context = $this->resolveStaffAttendanceContext($user);
        $staff = $context['staff'];

        if (!$staff || !$context['assigned_class']) {
            return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
                'auth_user' => $user,
                'staff' => $staff,
                'toast' => [
                    'type' => 'error',
                    'message' => !$staff ? 'You are not registered as a staff member.' : 'No class assigned to you'
                ]
            ]);
        }

        $club = $context['club'];

        return Inertia::render('ClubPersonal/AssistanceReport', [
            'auth_user' => $user,
            'club' => $this->clubPayload($club),
            'staff' => $staff,
            'assigned_class' => $context['assigned_class'],
            'assigned_members' => $context['assigned_members'],
            'planned_requirement_activities' => $context['tracks_requirements']
                ? $this->plannedRequirementActivities((int) $staff->id, (int) $context['assigned_class']['id'], now()->toDateString())
                : [],
            'attendance_context' => $context['attendance_context'],
        ]);
    }

    public function directorIndex(Request $request)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $request->input('club_id'));

        if (($club->club_type ?? null) !== 'master_guide') {
            return Inertia::render('ClubDirectorDashboard', [
                'auth_user' => $user,
                'toast' => [
                    'type' => 'error',
                    'message' => 'La toma de asistencia desde direccion esta disponible para clubes de Guias Mayores.',
                ],
            ]);
        }

        $context = $this->clubwideMasterGuideContext($club, $user);

        return Inertia::render('ClubPersonal/AssistanceReport', [
            'auth_user' => $user,
            'club' => $this->clubPayload($club),
            'staff' => $context['staff'],
            'assigned_class' => $context['assigned_class'],
            'assigned_members' => $context['assigned_members'],
            'planned_requirement_activities' => [],
            'attendance_context' => $context['attendance_context'],
        ]);
    }

    public function requirementActivities(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $user = Auth::user();
        $context = $this->resolveStaffAttendanceContext($user);
        $staff = $context['staff'];
        $assignedClass = $context['assigned_class'];
        if (!$staff || !$assignedClass) {
            abort(422, 'No class assigned to current staff.');
        }

        return response()->json([
            'date' => $validated['date'],
            'activities' => $context['tracks_requirements']
                ? $this->plannedRequirementActivities((int) $staff->id, (int) $assignedClass['id'], $validated['date'])
                : [],
        ]);
    }

    private function resolveStaffAttendanceContext($user): array
    {
        [$staff, $assignedClass, $assignedClassId] = $this->resolveStaffAndClass($user);
        if (!$staff) {
            return [
                'staff' => null,
                'club' => null,
                'assigned_class' => null,
                'assigned_members' => collect(),
                'tracks_requirements' => false,
                'attendance_context' => [],
            ];
        }

        $club = Club::find($staff->club_id ?? $user->club_id);
        if (($club->club_type ?? null) === 'master_guide') {
            return $this->clubwideMasterGuideContext($club, $user, $staff);
        }

        if (!$assignedClass || !$assignedClassId) {
            return [
                'staff' => $staff,
                'club' => $club,
                'assigned_class' => null,
                'assigned_members' => collect(),
                'tracks_requirements' => false,
                'attendance_context' => [],
            ];
        }

        $assignedMembers = ClubHelper::getMembersByClassAndClub((int) $club->id, (int) $assignedClassId)
            ->map(fn ($m) => $this->memberPayload($m))
            ->values();

        return [
            'staff' => $staff,
            'club' => $club,
            'assigned_class' => ['id' => (int) $assignedClass->id, 'name' => $assignedClass->class_name],
            'assigned_members' => $assignedMembers,
            'tracks_requirements' => ($club->club_type ?? null) === 'adventurers',
            'attendance_context' => [
                'mode' => 'class',
                'club_type' => $club->club_type,
                'metrics' => $this->metricsForClubType($club->club_type),
            ],
        ];
    }

    private function clubwideMasterGuideContext(Club $club, $user, ?Staff $staff = null): array
    {
        $staffPayload = $staff ?: (object) [
            'id' => 0,
            'club_id' => $club->id,
            'type' => 'master_guide_director',
            'status' => 'active',
        ];

        return [
            'staff' => $staffPayload,
            'club' => $club,
            'assigned_class' => ['id' => 0, 'name' => 'Club completo'],
            'assigned_members' => ClubHelper::membersOfClub((int) $club->id)
                ->map(fn ($m) => $this->memberPayload($m))
                ->values(),
            'tracks_requirements' => false,
            'attendance_context' => [
                'mode' => 'clubwide',
                'club_type' => 'master_guide',
                'metrics' => $this->metricsForClubType('master_guide'),
                'director_entry' => !$staff,
            ],
        ];
    }

    private function memberPayload(array $member): array
    {
        return [
            'id' => $member['id_data'],
            'applicant_name' => $member['applicant_name'],
            'member_type' => $member['member_type'],
            'member_row_id' => $member['member_id'],
        ];
    }

    private function clubPayload(?Club $club): ?array
    {
        return $club ? [
            'id' => $club->id,
            'club_name' => $club->club_name,
            'club_type' => $club->club_type,
            'church_id' => $club->church_id,
            'church_name' => $club->church_name,
        ] : null;
    }

    private function metricsForClubType(?string $clubType): array
    {
        return match ($clubType) {
            'pathfinders' => ['asistencia', 'cuota'],
            'master_guide' => ['asistencia', 'cuota'],
            default => ['asistencia', 'puntualidad', 'uniforme', 'cuota'],
        };
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
        if (!$assignedClass && $staff->assigned_carpeta_class_activation_id) {
            $activation = ClubCarpetaClassActivation::query()
                ->with('unionClassCatalog:id,name,sort_order')
                ->find($staff->assigned_carpeta_class_activation_id);

            if ($activation) {
                $assignedClass = ClubClass::firstOrCreate(
                    [
                        'club_id' => $activation->club_id,
                        'union_class_catalog_id' => $activation->union_class_catalog_id,
                    ],
                    [
                        'class_order' => $activation->unionClassCatalog?->sort_order,
                        'class_name' => $activation->unionClassCatalog?->name,
                    ]
                );
                $assignedClassId = $assignedClass->id;
            }
        }

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
