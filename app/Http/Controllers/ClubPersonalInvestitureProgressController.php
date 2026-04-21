<?php

namespace App\Http\Controllers;

use App\Models\ClassInvestitureRequirement;
use App\Models\ClassPlan;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\ClubCarpetaClassActivation;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use App\Models\Staff;
use App\Models\UnionCarpetaYear;
use App\Services\ClubLogoService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ClubPersonalInvestitureProgressController extends Controller
{
    private function normalizeValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function normalizeClubType(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeValue($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guia mayores', 'guia mayor avanzado' => 'master_guide',
            default => $normalized,
        };
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $data = $this->buildProgressData($user);
        if (!$data) {
            [$staff, $assignedClass, $assignedClassId] = $this->resolveStaffAndClass($user);
            return Inertia::render('ClubPersonal/InvestitureRequirementsProgress', [
                'auth_user' => $user,
                'staff' => $staff,
                'assigned_class' => null,
                'members_count' => 0,
                'members' => [],
                'requirements' => [],
                'toast' => [
                    'type' => 'error',
                    'message' => !$staff ? 'You are not registered as a staff member.' : 'No class assigned to you',
                ],
            ]);
        }

        return Inertia::render('ClubPersonal/InvestitureRequirementsProgress', [
            'auth_user' => $user,
            'club' => $data['club'],
            'staff' => $data['staff'],
            'assigned_class' => $data['assigned_class'],
            'members_count' => count($data['members']),
            'members' => $data['members'],
            'requirements' => $data['requirements'],
        ]);
    }

    public function storeCompletion(Request $request)
    {
        $validated = $request->validate([
            'requirement_id' => ['required', 'integer'],
            'member_id' => ['required', 'integer'],
            'class_plan_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        [$staff, , $assignedClassId] = $this->resolveStaffAndClass($user);
        if (!$staff || !$assignedClassId) {
            return response()->json(['message' => 'No class assigned to current staff.'], 422);
        }

        $clubId = (int) ($staff->club_id ?? $user->club_id ?? 0);
        $members = ClubHelper::getMembersByClassAndClub($clubId, (int) $assignedClassId)
            ->filter(fn ($m) => !empty($m['id_data']))
            ->values();
        $memberExists = $members->contains(fn ($m) => (int) $m['id_data'] === (int) $validated['member_id']);
        if (!$memberExists) {
            return response()->json(['message' => 'Selected member does not belong to your class.'], 422);
        }

        $plan = ClassPlan::query()
            ->with(['event:id,date'])
            ->where('id', (int) $validated['class_plan_id'])
            ->where('class_id', (int) $assignedClassId)
            ->where('investiture_requirement_id', (int) $validated['requirement_id'])
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'Selected activity is not valid for this requirement/class.'], 422);
        }

        $meetingDate = $this->normalizeDate($plan->requested_date ?? $plan->event?->date);
        if (!$meetingDate) {
            return response()->json(['message' => 'Selected activity has no valid meeting date.'], 422);
        }

        $report = RepAssistanceAdv::query()
            ->where('class_id', (int) $assignedClassId)
            ->whereDate('date', $meetingDate)
            ->first();

        if (!$report) {
            return response()->json(['message' => 'No assistance report exists for the selected meeting date.'], 422);
        }

        $merit = RepAssistanceAdvMerit::query()
            ->where('report_id', (int) $report->id)
            ->where('mem_adv_id', (int) $validated['member_id'])
            ->first();

        if (!$merit) {
            return response()->json(['message' => 'Selected member does not exist in the assistance report for that date.'], 422);
        }

        if (!$merit->asistencia) {
            return response()->json(['message' => 'Cannot mark requirement completion when asistencia is not checked.'], 422);
        }

        $checks = is_array($merit->requirement_checks_json) ? $merit->requirement_checks_json : [];
        $checks[(string) $plan->id] = true;
        $merit->requirement_checks_json = $checks;
        $merit->save();

        return response()->json([
            'message' => 'Requirement completion recorded successfully.',
        ]);
    }

    private function resolveAssignedClassName($user, ?ClubClass $assignedClass = null): ?string
    {
        return $assignedClass?->class_name
            ?: $user?->staff?->assigned_class_name
            ?: $user?->assigned_class_name
            ?: session('assigned_class_name');
    }

    private function findPublishedCarpetaYear(?Club $club): ?UnionCarpetaYear
    {
        $unionId = $club?->district?->association?->union?->id;
        if (!$unionId) {
            return null;
        }

        return UnionCarpetaYear::query()
            ->with(['requirements' => fn ($query) => $query
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->where('union_id', $unionId)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();
    }

    public function pdf(Request $request, ClubLogoService $clubLogoService)
    {
        $user = $request->user();
        $data = $this->buildProgressData($user);
        if (!$data) {
            abort(422, 'No class assigned to current staff.');
        }
        $logoClub = !empty($data['club']['id'])
            ? Club::withoutGlobalScopes()->find((int) $data['club']['id'])
            : null;

        $pdf = Pdf::loadView('pdf.investiture_requirements_progress', [
            'generatedAt' => now()->toDateTimeString(),
            'club' => $data['club'],
            'staff' => $data['staff'],
            'assignedClass' => $data['assigned_class'],
            'membersCount' => count($data['members']),
            'requirements' => $data['requirements'],
            'clubLogoDataUri' => $clubLogoService->dataUri($logoClub),
        ]);

        $classId = (int) ($data['assigned_class']['id'] ?? 0);
        $filename = 'investiture-requirements-progress-class-' . $classId . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }

    private function resolveStaffAndClass($user): array
    {
        $staff = Staff::with(['classes', 'user'])
            ->where('user_id', $user->id)
            ->first();

        if (!$staff) {
            $staff = Staff::whereHas('user', function ($q) use ($user) {
                $q->where('email', $user->email);
            })->with(['classes', 'user'])->first();
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

    private function normalizeDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildProgressData($user): ?array
    {
        [$staff, $assignedClass, $assignedClassId] = $this->resolveStaffAndClass($user);
        if (!$staff) {
            return null;
        }

        $clubId = (int) ($staff->club_id ?? $user->club_id ?? $assignedClass->club_id ?? 0);
        $club = $clubId
            ? Club::query()
                ->with([
                    'district.association.union',
                    'carpetaClassActivations.unionClassCatalog',
                ])
                ->find($clubId)
            : null;

        if (($club?->evaluation_system ?? 'honors') === 'carpetas') {
            $assignedClassName = $this->resolveAssignedClassName($user, $assignedClass);
            if (!$assignedClassName) {
                return null;
            }

            $activation = $club?->carpetaClassActivations
                ?->first(function (ClubCarpetaClassActivation $activation) use ($assignedClassName) {
                    return $this->normalizeValue($activation->unionClassCatalog?->name) === $this->normalizeValue($assignedClassName);
                });

            if (!$activation) {
                return null;
            }

            $publishedYear = $this->findPublishedCarpetaYear($club);
            $requirements = collect($publishedYear?->requirements ?? [])
                ->filter(function ($requirement) use ($club, $activation) {
                    return $this->normalizeClubType($requirement->club_type) === $this->normalizeClubType($club?->club_type)
                        && $this->normalizeValue($requirement->class_name) === $this->normalizeValue($activation->unionClassCatalog?->name);
                })
                ->map(fn ($requirement) => [
                    'id' => (int) $requirement->id,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'sort_order' => $requirement->sort_order,
                    'requirement_type' => $requirement->requirement_type,
                    'validation_mode' => $requirement->validation_mode,
                    'allowed_evidence_types' => $requirement->allowed_evidence_types ?? [],
                    'evidence_instructions' => $requirement->evidence_instructions,
                    'completed_count' => null,
                    'completions' => [],
                    'activities' => [],
                    'completion_placeholder' => true,
                ])
                ->values()
                ->all();

            return [
                'club' => $club ? [
                    'id' => $club->id,
                    'club_name' => $club->club_name,
                    'evaluation_system' => $club->evaluation_system,
                    'published_carpeta_year' => $publishedYear ? [
                        'id' => $publishedYear->id,
                        'year' => $publishedYear->year,
                    ] : null,
                ] : null,
                'staff' => [
                    'id' => $staff->id,
                    'name' => $staff->user?->name ?? $user?->name,
                ],
                'assigned_class' => [
                    'id' => $activation->id,
                    'name' => $activation->unionClassCatalog?->name ?: $assignedClassName,
                    'order' => $activation->unionClassCatalog?->sort_order,
                ],
                'members' => [],
                'requirements' => $requirements,
            ];
        }

        if (!$assignedClass || !$assignedClassId) {
            return null;
        }

        $members = ClubHelper::getMembersByClassAndClub($clubId, (int) $assignedClassId)
            ->filter(fn ($m) => !empty($m['id_data']))
            ->values();
        $memberIds = $members->pluck('id_data')->map(fn ($id) => (string) $id)->values();
        $memberNameById = $members->mapWithKeys(fn ($m) => [(string) $m['id_data'] => ($m['applicant_name'] ?? '—')]);

        $requirements = ClassInvestitureRequirement::query()
            ->where('club_class_id', (int) $assignedClassId)
            ->orderByRaw('COALESCE(sort_order, 999999)')
            ->orderBy('id')
            ->get(['id', 'club_class_id', 'title', 'description', 'sort_order', 'is_active']);
        $requirementIds = $requirements->pluck('id')->map(fn ($id) => (int) $id)->all();

        $plans = ClassPlan::query()
            ->with(['event:id,date'])
            ->where('class_id', (int) $assignedClassId)
            ->whereNotNull('investiture_requirement_id')
            ->whereIn('investiture_requirement_id', $requirementIds)
            ->whereIn('status', ['approved', 'submitted', 'changes_requested'])
            ->get(['id', 'investiture_requirement_id', 'title', 'requested_date', 'workplan_event_id']);

        $reportDateById = [];
        $reportIdByDate = [];
        $reportIds = [];
        if ($memberIds->isNotEmpty()) {
            $reports = RepAssistanceAdv::query()
                ->where('staff_id', (int) $staff->id)
                ->where('class_id', (int) $assignedClassId)
                ->orderBy('date')
                ->get(['id', 'date']);

            $reportIds = $reports->pluck('id')->all();
            $reportDateById = $reports->mapWithKeys(fn ($r) => [(int) $r->id => $this->normalizeDate($r->date)])->all();
            $reportIdByDate = $reports->mapWithKeys(fn ($r) => [$this->normalizeDate($r->date) => (int) $r->id])->all();
        }

        $planById = [];
        $activitiesByRequirement = [];
        foreach ($plans as $plan) {
            $meetingDate = $this->normalizeDate($plan->requested_date ?? $plan->event?->date);
            $reportId = $meetingDate ? ($reportIdByDate[$meetingDate] ?? null) : null;
            $payload = [
                'id' => (int) $plan->id,
                'title' => $plan->title,
                'meeting_date' => $meetingDate,
                'report_id' => $reportId,
                'has_report' => (bool) $reportId,
            ];

            $planById[(string) $plan->id] = [
                'requirement_id' => (int) $plan->investiture_requirement_id,
                'plan_title' => $plan->title,
                'meeting_date' => $meetingDate,
            ];

            $activitiesByRequirement[(int) $plan->investiture_requirement_id][] = $payload;
        }

        $completionMap = [];
        if (!empty($reportIds) && !empty($planById) && $memberIds->isNotEmpty()) {
            $merits = RepAssistanceAdvMerit::query()
                ->whereIn('report_id', $reportIds)
                ->where('asistencia', true)
                ->whereIn('mem_adv_id', $memberIds->all())
                ->get(['report_id', 'mem_adv_id', 'requirement_checks_json']);

            foreach ($merits as $merit) {
                $memberId = (string) $merit->mem_adv_id;
                if (!isset($memberNameById[$memberId])) {
                    continue;
                }

                $checks = is_array($merit->requirement_checks_json) ? $merit->requirement_checks_json : [];
                foreach ($checks as $planId => $checked) {
                    if (!$checked || !isset($planById[(string) $planId])) {
                        continue;
                    }

                    $planInfo = $planById[(string) $planId];
                    $requirementId = (int) $planInfo['requirement_id'];
                    $date = $reportDateById[(int) $merit->report_id] ?? $planInfo['meeting_date'];
                    $key = $requirementId . '|' . $memberId;

                    if (!isset($completionMap[$key]) || ($date && $date < $completionMap[$key]['date'])) {
                        $completionMap[$key] = [
                            'requirement_id' => $requirementId,
                            'member_id' => $memberId,
                            'member_name' => $memberNameById[$memberId],
                            'date' => $date,
                            'activity_title' => $planInfo['plan_title'],
                        ];
                    }
                }
            }
        }

        $completionsByRequirement = collect($completionMap)
            ->groupBy('requirement_id')
            ->map(function ($rows) {
                return collect($rows)
                    ->sortBy([
                        ['member_name', 'asc'],
                        ['date', 'asc'],
                    ])
                    ->values()
                    ->all();
            });

        $payloadRequirements = $requirements->map(function ($requirement) use ($completionsByRequirement, $activitiesByRequirement) {
            $rows = $completionsByRequirement->get((int) $requirement->id, []);
            $activities = collect($activitiesByRequirement[(int) $requirement->id] ?? [])
                ->sortByDesc('meeting_date')
                ->values()
                ->all();

            return [
                'id' => (int) $requirement->id,
                'title' => $requirement->title,
                'description' => $requirement->description,
                'sort_order' => $requirement->sort_order,
                'is_active' => (bool) $requirement->is_active,
                'completed_count' => count($rows),
                'completions' => $rows,
                'activities' => $activities,
            ];
        })->values()->all();

        return [
            'club' => $club ? [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'evaluation_system' => $club->evaluation_system,
            ] : null,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->user?->name ?? $user?->name,
            ],
            'assigned_class' => [
                'id' => $assignedClass->id,
                'name' => $assignedClass->class_name,
                'order' => $assignedClass->class_order,
            ],
            'members' => $members->map(fn ($m) => [
                'id' => (int) $m['id_data'],
                'name' => $m['applicant_name'] ?? '—',
            ])->values()->all(),
            'requirements' => $payloadRequirements,
        ];
    }
}
