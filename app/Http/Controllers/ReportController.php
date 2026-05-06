<?php

namespace App\Http\Controllers;

use App\Models\ClassInvestitureRequirement;
use App\Models\ClassMemberAdventurer;
use App\Models\ClassMemberPathfinder;
use App\Models\ClassPlan;
use App\Models\ClubCarpetaClassActivation;
use App\Models\Member;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use App\Models\Club;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\StaffAdventurer;
use App\Models\ClubClass;
use App\Models\ScopeType;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Account;
use App\Models\TreasuryMovement;
use App\Models\ParentCarpetaRequirementEvidence;
use App\Models\InvestitureRequest;
use App\Models\PublicMemberEvidenceAccessCode;
use App\Models\UnionCarpetaRequirement;
use App\Models\UnionCarpetaYear;
use App\Models\UnionClassCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Barryvdh\DomPDF\Facade\Pdf;
use Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use App\Support\ClubHelper;
use App\Services\ClubTreasuryService;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use Illuminate\Support\Str;
use Inertia\Inertia;
class ReportController extends Controller
{
    public function investitureRequirementsReport(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));

        return Inertia::render('ClubDirector/Reports/InvestitureRequirements', [
            'auth_user' => $user,
            'club' => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'evaluation_system' => $club->evaluation_system,
            ],
            'report_type' => ($club->evaluation_system ?? 'honors') === 'carpetas' ? 'carpetas' : 'honors',
            'classes' => ($club->evaluation_system ?? 'honors') === 'carpetas'
                ? $this->buildClubCarpetaRequirementReport($club)
                : $this->buildClubInvestitureRequirementReport($club),
            'investitureRequests' => ($club->evaluation_system ?? 'honors') === 'carpetas'
                ? $this->clubInvestitureRequests($club)
                : [],
        ]);
    }

    public function investitureRequirementsReportPdf(Request $request, ClubLogoService $clubLogoService)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $classes = ($club->evaluation_system ?? 'honors') === 'carpetas'
            ? $this->buildClubCarpetaRequirementReport($club)
            : $this->buildClubInvestitureRequirementReport($club);
        $showPending = $request->boolean('show_pending');
        $itemLabelPlural = $club->club_type === 'adventurers' ? 'Honores' : 'Requisitos de investidura';
        $itemLabelSingular = $club->club_type === 'adventurers' ? 'Honor' : 'Requisito';

        $pdf = Pdf::loadView('pdf.investiture_requirements_report', [
            'generatedAt' => now()->toDateTimeString(),
            'club' => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'evaluation_system' => $club->evaluation_system,
            ],
            'classes' => $classes,
            'showPending' => $showPending,
            'itemLabelPlural' => $itemLabelPlural,
            'itemLabelSingular' => $itemLabelSingular,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
        ]);

        $filename = 'investiture-requirements-club-' . $club->id . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }

    public function carpetaMemberPdf(Request $request, Member $member, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $club = $this->resolveClubForUser($request->user(), $member->club_id);
        abort_unless((int) $member->club_id === (int) $club->id, 403);
        abort_unless(in_array($member->type, ['adventurers', 'pathfinders', 'temp_pathfinder'], true), 404);
        abort_unless(($club->evaluation_system ?? 'honors') === 'carpetas', 404);

        $member->load(['club.church', 'club.district.association.union']);
        $detail = in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)
            ? MemberPathfinder::query()
                ->where('member_id', $member->id)
                ->orWhere('id', $member->id_data)
                ->first(['id', 'member_id', 'applicant_name', 'birthdate', 'grade'])
            : MemberAdventurer::query()
                ->where('id', $member->id_data)
                ->first(['id', 'applicant_name', 'birthdate', 'grade', 'parent_name']);

        $requirements = $this->carpetaRequirementsForMember($member);
        $evidences = ParentCarpetaRequirementEvidence::query()
            ->where('member_id', $member->id)
            ->get()
            ->keyBy('union_carpeta_requirement_id');

        abort_if($evidences->isEmpty(), 404, 'No hay evidencias para generar la carpeta.');

        $documentRequirements = collect($requirements)
            ->map(function (array $requirement) use ($evidences) {
                $evidence = $evidences->get($requirement['id']);
                $evidencePayload = null;

                if ($evidence) {
                    $filePath = $evidence->file_path;
                    $absolutePath = $filePath ? storage_path('app/public/' . ltrim($filePath, '/')) : null;

                    $evidencePayload = [
                        'id' => $evidence->id,
                        'type' => $evidence->evidence_type,
                        'text_value' => $evidence->text_value,
                        'file_path' => $filePath,
                        'file_url' => $filePath ? url('/storage/' . ltrim($filePath, '/')) : null,
                        'absolute_path' => $absolutePath && file_exists($absolutePath) ? $absolutePath : null,
                        'is_image' => $this->isCarpetaImageEvidence($evidence),
                        'physical_completed' => (bool) $evidence->physical_completed,
                        'status' => $evidence->status,
                        'submitted_at' => optional($evidence->submitted_at)->format('Y-m-d H:i'),
                        'updated_at' => optional($evidence->updated_at)->toISOString(),
                    ];
                }

                return [
                    ...$requirement,
                    'evidence' => $evidencePayload,
                    'completed' => (bool) ($evidence && ($evidence->file_path || $evidence->text_value || $evidence->physical_completed)),
                ];
            })
            ->values()
            ->all();

        $generatedAt = now();
        $className = $this->carpetaClassNameForMember($member);
        $validation = $documentValidationService->create(
            documentType: 'carpeta_investidura',
            title: 'Carpeta de investidura',
            snapshot: [
                'member_id' => $member->id,
                'member_name' => $detail?->applicant_name,
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'class_name' => $className,
                'generated_at' => $generatedAt->toISOString(),
                'requirements' => collect($documentRequirements)->map(fn ($requirement) => [
                    'requirement_id' => $requirement['id'],
                    'title' => $requirement['title'],
                    'evidence_id' => $requirement['evidence']['id'] ?? null,
                    'evidence_type' => $requirement['evidence']['type'] ?? null,
                    'text_value' => $requirement['evidence']['text_value'] ?? null,
                    'file_path' => $requirement['evidence']['file_path'] ?? null,
                    'physical_completed' => $requirement['evidence']['physical_completed'] ?? false,
                    'updated_at' => $requirement['evidence']['updated_at'] ?? null,
                ])->all(),
            ],
            metadata: [
                'Adventurero' => $detail?->applicant_name ?? '—',
                'Club' => $club->club_name ?? '—',
                'Iglesia' => $club?->church?->church_name ?? $club->church_name ?? '—',
                'Distrito' => $club?->district?->name ?? '—',
                'Unión' => $club?->district?->association?->union?->name ?? '—',
                'Clase' => $className ?? '—',
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('pdf.parent_carpeta_portfolio', [
            'member' => $member,
            'detail' => $detail,
            'club' => $club,
            'church' => $club?->church,
            'district' => $club?->district,
            'association' => $club?->district?->association,
            'union' => $club?->district?->association?->union,
            'className' => $className,
            'requirements' => $documentRequirements,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('letter', 'portrait');

        return $pdf->download('carpeta-investidura-' . $member->id . '-' . $generatedAt->format('Ymd-His') . '.pdf');
    }

    public function createCarpetaMemberAccessCode(Request $request, Member $member)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $club = $this->resolveClubForUser($request->user(), $validated['club_id']);
        abort_unless((int) $member->club_id === (int) $club->id, 403);
        abort_unless(in_array($member->type, ['pathfinders', 'temp_pathfinder'], true), 404);
        abort_unless($club->club_type === 'pathfinders', 404);
        abort_unless(($club->evaluation_system ?? 'honors') === 'carpetas', 404);

        $plainCode = PublicMemberEvidenceAccessCode::makePlainCode();
        $accessCode = PublicMemberEvidenceAccessCode::query()->create([
            'member_id' => $member->id,
            'club_id' => $club->id,
            'code_hash' => PublicMemberEvidenceAccessCode::hashCode($plainCode),
            'code_encrypted' => Crypt::encryptString($plainCode),
            'label' => $validated['label'] ?? null,
            'expires_at' => $validated['expires_at'] ?? now()->addDays(30),
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $accessCode->id,
                'url' => route('public.member-evidence.show', ['code' => $plainCode]),
                'expires_at' => optional($accessCode->expires_at)->toDateTimeString(),
            ],
        ], 201);
    }

    public function revokeCarpetaMemberAccessCodes(Request $request, Member $member)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
        ]);

        $club = $this->resolveClubForUser($request->user(), $validated['club_id']);
        abort_unless((int) $member->club_id === (int) $club->id, 403);
        abort_unless(in_array($member->type, ['pathfinders', 'temp_pathfinder'], true), 404);
        abort_unless($club->club_type === 'pathfinders', 404);

        PublicMemberEvidenceAccessCode::query()
            ->where('member_id', $member->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'revoked']);
    }

    public function generateAssistancePDF($id, $date)
    {
        try {
            $parsedDate = Carbon::parse($date)->toDateString();

            $report = RepAssistanceAdv::with(['merits', 'staff', 'club'])
                ->where('id', $id)
                ->whereDate('date', $parsedDate)
                ->firstOrFail();

            return response()->json($report); // ✅ return raw JSON
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Report not found or failed.',
                'error_details' => $e->getMessage(),
            ], 404);
        }
    }

    public function assistanceReportsDirector(Request $request)
    {
        $validated = $request->validate([
            'report_type' => 'required|string',
            'club_id' => 'required|integer|exists:clubs,id',
        ]);

        $club = $this->resolveClubForUser($request->user(), $validated['club_id']);

        $query = RepAssistanceAdv::query()
            ->where('club_id', $club->id);

        $with = ['staff', 'club'];

        switch ($request->report_type) {
            case 'date':
                $request->validate(['date' => 'required|date']);
                $query->whereDate('date', $request->date);
                $with[] = 'merits';
                break;

            case 'range':
                $request->validate([
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after_or_equal:start_date',
                ]);
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
                $with[] = 'merits';
                break;

            case 'class':
                $request->validate(['class_id' => 'required|integer']);
                $query->where('class_id', $request->class_id);
                $with[] = 'merits';
                break;

            case 'member':
                $request->validate(['member_id' => 'required|integer']);

                $query->whereHas('merits', function ($q) use ($request) {
                    $q->where('mem_adv_id', $request->member_id);
                });

                $with['merits'] = function ($q) use ($request) {
                    $q->where('mem_adv_id', $request->member_id);
                };
                break;

            default:
                return response()->json(['message' => 'Invalid report type'], 400);
        }

        $reports = $query->with($with)->get();


        return response()->json(['reports' => $reports], 200);
    }

    public function financialReportPreload(Request $request)
    {
        $user = $request->user();

        // Allow selecting club via query param
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubs = ClubHelper::clubsForUser($user)
            ->sortBy('club_name')
            ->values()
            ->map(fn ($club) => [
                'id' => $club->id,
                'club_name' => $club->club_name,
            ]);


        // --- Catalogs: Scope Types ---
        $clubScopeTypes = ScopeType::active()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $globalScopeTypes = ScopeType::active()
            ->whereNull('club_id')
            ->whereNotIn('value', $clubScopeTypes->pluck('value'))
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $scopeTypes = $clubScopeTypes->concat($globalScopeTypes)->values();

        // --- Catalogs: Pay-To Options (from accounts) ---
        $payToOptions = Account::query()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'pay_to as value', 'label', 'club_id'])
            ->values();





        $concepts = PaymentConcept::query()
            ->where('club_id', $club->id)
            //->where('status', 'active')
            ->with([
                'scopes' => function ($q) {
                    $q->whereNull('deleted_at')
                        ->with(['club:id,club_name', 'class:id,class_name']);
                }
            ])
            ->orderBy('concept')
            ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id', 'reusable']);

        $scopes = PaymentConceptScope::query()
            ->whereNull('deleted_at')
            ->whereHas('concept', fn($q) => $q->where('club_id', $club->id))
            ->with([
                'club:id,club_name',
                'class:id,class_name',
                'concept:id,concept,club_id'
            ])
            ->orderBy('scope_type')
            ->get(['id', 'payment_concept_id', 'scope_type', 'club_id', 'class_id', 'member_id', 'staff_id']);

        // Members for this club
        $members = MemberAdventurer::query()
            ->where('club_id', $club->id)
            ->with([
                'clubClasses' => function ($q) {
                    $q->wherePivot('active', true)
                        ->select('club_classes.id', 'club_classes.class_name'); // columns from related table
                },
            ])
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id'])
            ->map(function ($m) {
                $current = $m->clubClasses->first(); // the active one (if any)
                return [
                    'id' => $m->id,
                    'applicant_name' => $m->applicant_name,
                    'club_id' => $m->club_id,
                    'current_class' => $current ? [
                        'id' => $current->id,
                        'class_name' => $current->class_name,
                    ] : null,
                ];
            })
            ->values();

        // Classes for this club
        $classes = ClubClass::query()
            ->where('club_id', $club->id)
            ->orderBy('class_name')
            ->get(['id', 'class_name', 'club_id']);

        // Staff for this club
        $staffColumns = ['id', 'name', 'email', 'club_id'];
        if (Schema::hasColumn('staff_adventurers', 'assigned_class')) {
            $staffColumns[] = 'assigned_class';
        }

        $staff = StaffAdventurer::query()
            ->where('club_id', $club->id)
            ->orderBy('name')
            ->get($staffColumns)
            ->map(function ($s) {
                if (!isset($s->assigned_class)) {
                    $s->assigned_class = null;
                    $s->class_warning = 'Staff class assignment missing';
                }
                return $s;
            });

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                'club_id' => $club->id,
                'clubs' => $clubs,
                'concepts' => $concepts,
                'scopes' => $scopes,
                'members' => $members,
                'classes' => $classes,
                'staff' => $staff,
                'scope_types' => $scopeTypes,
                'pay_to' => $payToOptions,
            ]
        ]);
    }

    /**
     * Resolve the active club from session or user. Adjust to your app’s logic.
     */
    protected function resolveClubFromUser($user): Club
    {
        return ClubHelper::clubForUser($user);
    }

    /**
     * Resolve a club that belongs to the user, optionally by explicit id.
     */
    protected function resolveClubForUser($user, $clubId = null): Club
    {
        return ClubHelper::clubForUser($user, $clubId);
    }

    protected function clubInvestitureRequests(Club $club): array
    {
        return InvestitureRequest::query()
            ->where('club_id', $club->id)
            ->withCount('members')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (InvestitureRequest $request) => [
                'id' => $request->id,
                'status' => $request->status,
                'carpeta_year' => $request->carpeta_year,
                'tentative_investiture_date' => optional($request->tentative_investiture_date)->toDateString(),
                'approved_investiture_date' => optional($request->approved_investiture_date)->toDateString(),
                'date_change_reason' => $request->date_change_reason,
                'date_change_requested_at' => optional($request->date_change_requested_at)->toDateTimeString(),
                'submitted_at' => optional($request->submitted_at)->toDateTimeString(),
                'assigned_evaluator_name' => $request->assigned_evaluator_name,
                'assigned_evaluator_email' => $request->assigned_evaluator_email,
                'authorization_person_name' => $request->authorization_person_name,
                'ceremony_representative_name' => $request->ceremony_representative_name,
                'ceremony_representative_email' => $request->ceremony_representative_email,
                'ceremony_representative_phone' => $request->ceremony_representative_phone,
                'authorized_at' => optional($request->authorized_at)->toDateTimeString(),
                'ceremony_completed_at' => optional($request->ceremony_completed_at)->toDateTimeString(),
                'members_count' => $request->members_count,
                'completed_at' => optional($request->completed_at)->toDateTimeString(),
            ])
            ->all();
    }

    protected function buildClubInvestitureRequirementReport(Club $club): array
    {
        $classes = ClubClass::query()
            ->where('club_id', (int) $club->id)
            ->orderByRaw('COALESCE(class_order, 999999)')
            ->orderBy('class_name')
            ->get(['id', 'class_name', 'class_order']);

        return $classes->map(function ($class) use ($club) {
            $members = ClubHelper::getMembersByClassAndClub((int) $club->id, (int) $class->id)
                ->filter(fn ($m) => !empty($m['id_data']))
                ->values();
            $memberIds = $members->pluck('id_data')->map(fn ($id) => (string) $id)->values();
            $memberNameById = $members->mapWithKeys(fn ($m) => [(string) $m['id_data'] => ($m['applicant_name'] ?? '—')]);

            $requirements = ClassInvestitureRequirement::query()
                ->where('club_class_id', (int) $class->id)
                ->orderByRaw('COALESCE(sort_order, 999999)')
                ->orderBy('id')
                ->get(['id', 'club_class_id', 'title', 'description', 'sort_order', 'is_active']);

            $requirementIds = $requirements->pluck('id')->map(fn ($id) => (int) $id)->all();

            $plans = empty($requirementIds)
                ? collect()
                : ClassPlan::query()
                    ->with(['event:id,date'])
                    ->where('class_id', (int) $class->id)
                    ->whereNotNull('investiture_requirement_id')
                    ->whereIn('investiture_requirement_id', $requirementIds)
                    ->whereIn('status', ['approved', 'submitted', 'changes_requested'])
                    ->get(['id', 'investiture_requirement_id', 'title', 'requested_date', 'workplan_event_id']);

            $reports = $memberIds->isEmpty()
                ? collect()
                : RepAssistanceAdv::query()
                    ->where('class_id', (int) $class->id)
                    ->orderBy('date')
                    ->get(['id', 'date']);

            $reportDateById = $reports->mapWithKeys(fn ($r) => [(int) $r->id => $this->normalizeReportDate($r->date)])->all();
            $reportIdByDate = $reports->mapWithKeys(fn ($r) => [$this->normalizeReportDate($r->date) => (int) $r->id])->all();
            $reportIds = $reports->pluck('id')->all();

            $planById = [];
            $activitiesByRequirement = [];
            foreach ($plans as $plan) {
                $meetingDate = $this->normalizeReportDate($plan->requested_date ?? $plan->event?->date);
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
                                'member_id' => (int) $memberId,
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

            $payloadRequirements = $requirements->map(function ($requirement) use ($completionsByRequirement, $activitiesByRequirement, $members) {
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
                    'pending_count' => max(0, $members->count() - count($rows)),
                    'activities_count' => count($activities),
                    'completions' => $rows,
                ];
            })->values()->all();

            return [
                'id' => (int) $class->id,
                'class_name' => $class->class_name,
                'class_order' => $class->class_order,
                'members_count' => $members->count(),
                'members' => $members->map(fn ($m) => [
                    'id' => (int) ($m['id_data'] ?? 0),
                    'name' => $m['applicant_name'] ?? '—',
                ])->values()->all(),
                'requirements_count' => count($payloadRequirements),
                'completed_requirements_count' => collect($payloadRequirements)->where('completed_count', '>', 0)->count(),
                'requirements' => $payloadRequirements,
            ];
        })->values()->all();
    }

    protected function buildClubCarpetaRequirementReport(Club $club): array
    {
        $club->loadMissing('district.association.union');
        $unionId = $club?->district?->association?->union?->id;

        if (!$unionId) {
            return [];
        }

        $classes = UnionClassCatalog::query()
            ->whereHas('clubCatalog', function ($query) use ($unionId, $club) {
                $query->where('union_id', $unionId);
            })
            ->where('status', 'active')
            ->with('clubCatalog:id,union_id,name,club_type')
            ->orderByRaw('COALESCE(sort_order, 999999)')
            ->orderBy('name')
            ->get(['id', 'union_club_catalog_id', 'name', 'sort_order'])
            ->filter(fn ($catalogClass) =>
                $this->normalizeCarpetaClubType($catalogClass->clubCatalog?->club_type ?: $catalogClass->clubCatalog?->name) === $this->normalizeCarpetaClubType($club->club_type)
            )
            ->values();

        if ($classes->isEmpty()) {
            return [];
        }

        $clubClasses = ClubClass::query()
            ->where('club_id', $club->id)
            ->whereIn('union_class_catalog_id', $classes->pluck('id'))
            ->get(['id', 'club_id', 'union_class_catalog_id', 'class_name', 'class_order'])
            ->keyBy('union_class_catalog_id');

        if ($club->club_type === 'pathfinders') {
            $memberAssignments = ClassMemberPathfinder::query()
                ->with('member:id,type,id_data,club_id,status')
                ->whereIn('club_class_id', $clubClasses->pluck('id')->filter()->values())
                ->where('active', true)
                ->get(['id', 'member_id', 'club_class_id', 'assigned_at', 'role']);

            $canonicalMembers = Member::query()
                ->where('club_id', $club->id)
                ->whereIn('type', ['pathfinders', 'temp_pathfinder'])
                ->whereIn('id', $memberAssignments->pluck('member_id')->filter()->unique()->values())
                ->get(['id', 'id_data', 'club_id', 'type', 'parent_id', 'status'])
                ->keyBy('id');

            $detailRows = MemberPathfinder::query()
                ->whereIn('member_id', $canonicalMembers->pluck('id')->filter()->values())
                ->orWhereIn('id', $canonicalMembers->pluck('id_data')->filter()->values())
                ->get(['id', 'member_id', 'applicant_name', 'birthdate', 'grade'])
                ->keyBy(fn ($row) => (int) ($row->member_id ?: 0) ?: ('legacy-' . $row->id));
        } else {
            $memberAssignments = ClassMemberAdventurer::query()
                ->with('member:id,applicant_name,birthdate,grade,club_id')
                ->whereIn('club_class_id', $clubClasses->pluck('id')->filter()->values())
                ->where('active', true)
                ->get(['id', 'members_adventurer_id', 'club_class_id', 'assigned_at', 'role']);

            $canonicalMembers = Member::query()
                ->where('club_id', $club->id)
                ->where('type', 'adventurers')
                ->whereIn('id_data', $memberAssignments->pluck('members_adventurer_id')->filter()->unique()->values())
                ->get(['id', 'id_data', 'club_id', 'type', 'parent_id', 'status'])
                ->keyBy('id_data');

            $detailRows = collect();
        }

        $evidences = ParentCarpetaRequirementEvidence::query()
            ->whereIn('member_id', $canonicalMembers->pluck('id')->filter()->values())
            ->get()
            ->keyBy(fn ($evidence) => $evidence->member_id . '|' . $evidence->union_carpeta_requirement_id);

        $year = UnionCarpetaYear::query()
            ->where('union_id', $unionId)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        $requirements = $year
            ? UnionCarpetaRequirement::query()
                ->where('union_carpeta_year_id', $year->id)
                ->where('status', 'active')
                ->orderByRaw('COALESCE(sort_order, 999999)')
                ->orderBy('id')
                ->get()
            : collect();

        return $classes->map(function (UnionClassCatalog $catalogClass) use ($clubClasses, $memberAssignments, $canonicalMembers, $detailRows, $evidences, $requirements, $club) {
            $clubClass = $clubClasses->get($catalogClass->id);
            $assignedMembers = $clubClass
                ? $memberAssignments->where('club_class_id', $clubClass->id)->values()
                : collect();

            $classRequirements = $requirements
                ->filter(fn ($requirement) =>
                    $this->normalizeCarpetaClubType($requirement->club_type) === $this->normalizeCarpetaClubType($club->club_type)
                    && $this->normalizeCarpetaValue($requirement->class_name) === $this->normalizeCarpetaValue($catalogClass->name)
                )
                ->map(fn ($requirement) => [
                    'id' => (int) $requirement->id,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'requirement_type' => $requirement->requirement_type,
                    'validation_mode' => $requirement->validation_mode,
                    'allowed_evidence_types' => $requirement->allowed_evidence_types ?: [],
                    'evidence_instructions' => $requirement->evidence_instructions,
                    'sort_order' => $requirement->sort_order,
                ])
                ->values();

            $members = $assignedMembers
                ->map(function ($assignment) use ($canonicalMembers, $detailRows, $classRequirements, $evidences, $club) {
                    if ($club->club_type === 'pathfinders') {
                        $member = $canonicalMembers->get($assignment->member_id);
                        $detail = $member
                            ? ($detailRows->get((int) $member->id) ?: $detailRows->get('legacy-' . $member->id_data))
                            : null;
                    } else {
                        $detail = $assignment->member;
                        $member = $canonicalMembers->get($assignment->members_adventurer_id);
                    }

                    if (!$detail || !$member) {
                        return null;
                    }

                    $requirements = $classRequirements
                        ->map(function (array $requirement) use ($member, $evidences) {
                            $evidence = $evidences->get($member->id . '|' . $requirement['id']);
                            $requirement['completed'] = (bool) ($evidence && ($evidence->file_path || $evidence->text_value || $evidence->physical_completed));
                            $requirement['evidence'] = $evidence ? [
                                'id' => (int) $evidence->id,
                                'evidence_type' => $evidence->evidence_type,
                                'text_value' => $evidence->text_value,
                                'file_path' => $evidence->file_path,
                                'file_url' => $evidence->file_path ? url('/storage/' . ltrim($evidence->file_path, '/')) : null,
                                'is_image' => $this->isCarpetaImageEvidence($evidence),
                                'physical_completed' => (bool) $evidence->physical_completed,
                                'status' => $evidence->status,
                                'submitted_at' => optional($evidence->submitted_at)->toDateTimeString(),
                            ] : null;

                            return $requirement;
                        })
                        ->values()
                        ->all();

                    $completedCount = collect($requirements)->where('completed', true)->count();

                    return [
                        'member_id' => (int) $member->id,
                        'id_data' => (int) $detail->id,
                        'name' => $detail->applicant_name ?? '—',
                        'birthdate' => optional($detail->birthdate)->toDateString(),
                        'grade' => $detail->grade,
                        'assigned_at' => optional($assignment->assigned_at)->toDateString(),
                        'requirements_count' => count($requirements),
                        'completed_count' => $completedCount,
                        'pending_count' => max(0, count($requirements) - $completedCount),
                        'has_evidence' => collect($requirements)->contains(fn ($requirement) => !empty($requirement['evidence'])),
                        'all_completed' => count($requirements) > 0 && collect($requirements)->every(fn ($requirement) => (bool) $requirement['completed']),
                        'print_url' => route('club.reports.investiture-requirements.member.pdf', ['member' => $member->id]),
                        'requirements' => $requirements,
                    ];
                })
                ->filter()
                ->sortBy(fn ($member) => mb_strtolower((string) $member['name']))
                ->values();

            return [
                'id' => (int) $catalogClass->id,
                'club_class_id' => $clubClass?->id,
                'class_name' => $catalogClass->name,
                'class_order' => $catalogClass->sort_order,
                'members_count' => $members->count(),
                'requirements_count' => $classRequirements->count(),
                'completed_requirements_count' => $members->sum('completed_count'),
                'members' => $members->all(),
                'requirements' => $classRequirements->all(),
            ];
        })->values()->all();
    }

    protected function carpetaRequirementsForMember(Member $member): array
    {
        $club = Club::query()
            ->with(['district.association.union'])
            ->find($member->club_id);
        $className = $this->carpetaClassNameForMember($member);
        $unionId = $club?->district?->association?->union?->id;

        if (!$club || !$className || !$unionId) {
            return [];
        }

        $year = UnionCarpetaYear::query()
            ->where('union_id', $unionId)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        if (!$year) {
            return [];
        }

        return UnionCarpetaRequirement::query()
            ->where('union_carpeta_year_id', $year->id)
            ->where('status', 'active')
            ->orderByRaw('COALESCE(sort_order, 999999)')
            ->orderBy('id')
            ->get()
            ->filter(fn ($requirement) =>
                $this->normalizeCarpetaClubType($requirement->club_type) === $this->normalizeCarpetaClubType($club->club_type)
                && $this->normalizeCarpetaValue($requirement->class_name) === $this->normalizeCarpetaValue($className)
            )
            ->map(fn ($requirement) => [
                'id' => (int) $requirement->id,
                'title' => $requirement->title,
                'description' => $requirement->description,
                'requirement_type' => $requirement->requirement_type,
                'validation_mode' => $requirement->validation_mode,
                'allowed_evidence_types' => $requirement->allowed_evidence_types ?: [],
                'evidence_instructions' => $requirement->evidence_instructions,
                'sort_order' => $requirement->sort_order,
            ])
            ->values()
            ->all();
    }

    protected function carpetaClassNameForMember(Member $member): ?string
    {
        $clubClass = $this->currentCarpetaClubClassForMember($member);

        if ($clubClass?->unionClassCatalog) {
            return $clubClass->unionClassCatalog->name;
        }

        return $clubClass?->class_name;
    }

    protected function currentCarpetaClubClassForMember(Member $member): ?ClubClass
    {
        if ($member->id_data || in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
            if (in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
                $assignment = ClassMemberPathfinder::query()
                    ->with('clubClass.unionClassCatalog')
                    ->where('member_id', $member->id)
                    ->where('active', true)
                    ->orderByDesc('assigned_at')
                    ->orderByDesc('id')
                    ->first();
            } else {
                $assignment = ClassMemberAdventurer::query()
                    ->with('clubClass.unionClassCatalog')
                    ->where('members_adventurer_id', $member->id_data)
                    ->where('active', true)
                    ->orderByDesc('assigned_at')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($assignment?->clubClass && (int) $assignment->clubClass->club_id === (int) $member->club_id) {
                return $assignment->clubClass;
            }
        }

        if (!$member->class_id) {
            return null;
        }

        $clubClass = ClubClass::query()
            ->with('unionClassCatalog')
            ->where('club_id', $member->club_id)
            ->where('id', $member->class_id)
            ->first();

        if ($clubClass) {
            return $clubClass;
        }

        $activation = ClubCarpetaClassActivation::query()
            ->with('unionClassCatalog')
            ->where('club_id', $member->club_id)
            ->where('id', $member->class_id)
            ->first();

        if (!$activation) {
            return null;
        }

        return ClubClass::firstOrCreate(
            [
                'club_id' => $member->club_id,
                'union_class_catalog_id' => $activation->union_class_catalog_id,
            ],
            [
                'class_order' => $activation->unionClassCatalog?->sort_order,
                'class_name' => $activation->unionClassCatalog?->name,
            ]
        );
    }

    protected function normalizeReportDate($value): ?string
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

    protected function isCarpetaImageEvidence(ParentCarpetaRequirementEvidence $evidence): bool
    {
        if ($evidence->evidence_type === 'photo') {
            return true;
        }

        $extension = mb_strtolower(pathinfo((string) $evidence->file_path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    protected function normalizeCarpetaValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function normalizeCarpetaClubType(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeCarpetaValue($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guia mayores', 'guia mayor avanzado' => 'master_guide',
            default => $normalized,
        };
    }

    public function financialReport(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubId = $club->id;

        // Base validation (shared)
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['concept', 'scope', 'account', 'date', 'member'])],
            'concept_id' => ['nullable', 'integer', Rule::exists('payment_concepts', 'id')->where(fn($q) => $q->where('club_id', $clubId))],
            'scope_type' => ['nullable', 'string'],
            'scope_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', Rule::in(['cash', 'bank'])],
            'paginate' => ['sometimes', 'boolean'],   // optional: to enable pagination
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $mode = $validated['mode'];
        $paginate = (bool) ($validated['paginate'] ?? false);
        $perPage = (int) ($validated['per_page'] ?? 100);
        $payTo = $validated['pay_to'] ?? null;
        if ($payTo) {
            $exists = Account::query()
                ->where('club_id', $club->id)
                ->where('pay_to', $payTo)
                ->exists();
            if (!$exists) {
                return response()->json(['message' => 'Cuenta invalida.'], 422);
            }
        }


        switch ($mode) {

            case 'concept': {
                // Ensure concept belongs to this club (and optionally active)
                $concept = PaymentConcept::query()
                    ->where('id', $validated['concept_id'] ?? 0)
                    ->where('club_id', $club->id)
                    // ->where('status', 'active')  // uncomment if you enforce active here
                    ->firstOrFail();

                $q = Payment::query()
                    ->where('club_id', $club->id)
                    ->where('payment_concept_id', $concept->id)
                    ->with([
                        'member:id,type,id_data',
                        'staff:id,type,id_data,user_id',
                        'staff.user:id,name',
                        'concept:id,concept,amount,reusable',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');
                if ($payTo) {
                    $q->where('pay_to', $payTo);
                }

                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $q->whereBetween('payment_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $q->whereDate('payment_date', $validated['date']);
                }

                if ($paginate) {
                    $page = $q->paginate($perPage);
                    $rows = $this->attachPaymentPayerNames(collect($page->items()));
                    $page->setCollection($rows);
                } else {
                    $rows = $this->attachPaymentPayerNames($q->get());
                    $page = null;
                }

                $summary = $this->buildSummaryFromRows($rows);

                return response()->json([
                    'data' => [
                        'mode' => 'concept',
                        'concept' => [
                            'id' => $concept->id,
                            'concept' => $concept->concept,
                            'amount' => $concept->amount,
                            'payment_expected_by' => $concept->payment_expected_by,
                            'reusable' => (bool) $concept->reusable,
                        ],
                        'payments' => $paginate ? $page : $rows,
                        'summary' => $summary,
                    ]
                ]);
            }

            case 'account': {
                $accountsReport = $this->buildFinancialAccountLedgerData($club, $validated)['accounts'];

                return response()->json([
                    'data' => [
                        'mode' => 'account',
                        'account' => $payTo ? ['pay_to' => $payTo] : null,
                        'accounts' => $accountsReport,
                    ]
                ]);
            }

            case 'scope': {
                // Extra validation for scope mode
                $request->validate([
                    'scope_type' => ['required', Rule::in(['club_wide', 'class', 'member', 'staff_wide', 'staff'])],
                    'scope_id' => ['nullable', 'integer'],
                ]);

                $scopeType = $validated['scope_type'];
                $scopeId = $validated['scope_id'] ?? null;
                $from = $validated['date_from'] ?? null;
                $to = $validated['date_to'] ?? null;

                // Normalize staff_wide → staff + staff_all=true rows
                $normalizedType = $scopeType === 'staff_wide' ? 'staff' : $scopeType;

                $baseScopeQ = PaymentConceptScope::query()
                    ->whereHas('concept', fn($q) => $q->where('club_id', $club->id)->where('status', 'active'))
                    ->where('scope_type', $normalizedType);

                switch ($normalizedType) {
                    case 'club_wide':
                        $baseScopeQ->where('club_id', $club->id);
                        break;

                    case 'class':
                        $scopeId ? $baseScopeQ->where('class_id', $scopeId)
                            : $baseScopeQ->whereNotNull('class_id');
                        break;

                    case 'member':
                        $scopeId ? $baseScopeQ->where('member_id', $scopeId)
                            : $baseScopeQ->whereNotNull('member_id');
                        break;

                    case 'staff':
                        if ($scopeId) {
                            // include staff-wide for this club OR specific staff
                            $baseScopeQ->where(function ($q) use ($club, $scopeId) {
                                $q->where(function ($qq) use ($club) {
                                    $qq->where('staff_all', true)
                                        ->where('club_id', $club->id);
                                })
                                    ->orWhere(function ($qq) use ($scopeId) {
                                        $qq->where('staff_all', false)
                                            ->where('staff_id', $scopeId);
                                    });
                            });
                        } else {
                            // Only staff-wide (club-level) if no staff chosen
                            $baseScopeQ->where('staff_all', true)->where('club_id', $club->id);
                        }
                        break;
                }

                $scopeRows = $baseScopeQ
                    ->with([
                        'concept:id,concept,amount,payment_expected_by,type,club_id,reusable',
                        'club:id,club_name',
                        'class:id,class_name',
                        'member:id,applicant_name',
                        'staff:id,name',
                    ])
                    ->orderBy('id')
                    ->get(['id', 'payment_concept_id', 'scope_type', 'club_id', 'class_id', 'member_id', 'staff_id', 'staff_all']);

                if ($scopeRows->isEmpty()) {
                    return response()->json([
                        'data' => [
                            'mode' => 'scope',
                            'scope' => ['type' => $scopeType, 'id' => $scopeId],
                            'scopes' => [],
                        ]
                    ]);
                }

                // Group by scope identity (e.g., class|<id>, staff_all|<club>, staff|<id>, etc.)
                $identityKey = function ($s) {
                    return match ($s->scope_type) {
                        'club_wide' => "club|{$s->club_id}",
                        'class' => "class|{$s->class_id}",
                        'member' => "member|{$s->member_id}",
                        'staff' => $s->staff_all ? "staff_all|{$s->club_id}" : "staff|{$s->staff_id}",
                        default => "{$s->scope_type}|{$s->id}",
                    };
                };

                $identityLabel = function ($s) {
                    return match ($s->scope_type) {
                        'club_wide' => 'Club wide' . ($s->club?->club_name ? " ({$s->club->club_name})" : ''),
                        'class' => 'Class: ' . ($s->class?->class_name ?? $s->class_id),
                        'member' => 'Member: ' . ($s->member?->applicant_name ?? $s->member_id),
                        'staff' => $s->staff_all
                        ? ('Staff wide' . ($s->club?->club_name ? " ({$s->club->club_name})" : ''))
                        : ('Staff: ' . ($s->staff?->name ?? $s->staff_id)),
                        default => ucfirst($s->scope_type),
                    };
                };

                $byIdentity = $scopeRows->groupBy(fn($s) => $identityKey($s));

                $allConceptIds = $scopeRows->pluck('payment_concept_id')->unique()->values();

                $concepts = PaymentConcept::query()
                    ->whereIn('id', $allConceptIds)
                    ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id', 'reusable'])
                    ->keyBy('id');

                $paymentsQ = Payment::query()
                    ->where('club_id', $club->id)
                    ->whereIn('payment_concept_id', $allConceptIds)
                    ->with([
                        'member:id,type,id_data',
                        'staff:id,type,id_data,user_id',
                        'staff.user:id,name',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');
                if ($payTo) {
                    $paymentsQ->where('pay_to', $payTo);
                }

                if ($from || $to) {
                    $paymentsQ->whereBetween('payment_date', [$from ?? '1900-01-01', $to ?? '2999-12-31']);
                }

                $paymentsByConcept = $this->attachPaymentPayerNames($paymentsQ->get())->groupBy('payment_concept_id');

                $scopeBlocks = $byIdentity->map(function ($rowsForIdentity) use ($identityKey, $identityLabel, $paymentsByConcept, $concepts) {

                    $conceptIds = $rowsForIdentity->pluck('payment_concept_id')->unique()->values();

                    $conceptReports = $conceptIds->map(function ($cid) use ($paymentsByConcept, $concepts, $identityKey) {
                        $rows = ($paymentsByConcept->get($cid) ?? collect())->values();

                        return [
                            'concept' => [
                                'id' => $cid,
                                'concept' => $concepts[$cid]->concept ?? '—',
                                'amount' => $concepts[$cid]->amount ?? null,
                                'payment_expected_by' => $concepts[$cid]->payment_expected_by ?? null,
                                'type' => $concepts[$cid]->type ?? null,
                                'reusable' => (bool) ($concepts[$cid]->reusable ?? false),
                            ],
                            'payments' => $rows,
                            'summary' => $this->buildSummaryFromRows($rows)
                        ];
                    })->values();

                    // Roll-up summary per scope identity
                    $scopeSummary = (function ($conceptReports) {
                        $acc = [
                            'payments_count' => 0,
                            'charges_count' => 0,
                            'amount_paid_sum' => 0.0,
                            'expected_sum' => 0.0,
                            'balance_remaining' => 0.0,
                            'by_payment_type' => ['cash' => 0.0, 'zelle' => 0.0, 'check' => 0.0, 'transfer' => 0.0],
                        ];
                        foreach ($conceptReports as $cr) {
                            $s = $cr['summary'];
                            $acc['payments_count'] += (int) ($s['payments_count'] ?? 0);
                            $acc['charges_count'] += (int) ($s['charges_count'] ?? 0);
                            $acc['amount_paid_sum'] += (float) ($s['amount_paid_sum'] ?? 0);
                            $acc['expected_sum'] += (float) ($s['expected_sum'] ?? 0);
                            $acc['balance_remaining'] += (float) ($s['balance_remaining'] ?? 0);
                            foreach (['cash', 'zelle', 'check', 'transfer'] as $t) {
                                $acc['by_payment_type'][$t] += (float) ($s['by_payment_type'][$t] ?? 0);
                            }
                        }
                        return $acc;
                    })($conceptReports->all());

                    $first = $rowsForIdentity->first();
                    return [
                        'scope' => [
                            'identity_key' => $identityKey($first),
                            'type' => $first->scope_type,
                            'label' => $identityLabel($first),
                            'club' => $first->club ? ['id' => $first->club->id, 'club_name' => $first->club->club_name] : null,
                            'class' => $first->class ? ['id' => $first->class->id, 'class_name' => $first->class->class_name] : null,
                            'member' => $first->member ? ['id' => $first->member->id, 'applicant_name' => $first->member->applicant_name] : null,
                            'staff' => $first->staff_all ? null : ($first->staff ? ['id' => $first->staff->id, 'name' => $first->staff->name] : null),
                            'staff_all' => (bool) $first->staff_all,
                        ],
                        'concepts' => $conceptReports,
                        'summary' => $scopeSummary,
                    ];
                })->values();

                return response()->json([
                    'data' => [
                        'mode' => 'scope',
                        'scope' => ['type' => $scopeType, 'id' => $scopeId],
                        'scopes' => $scopeBlocks,
                    ]
                ]);
            }

            default:
                return response()->json(['message' => 'Mode not implemented yet'], 400);
        }
    }

    public function financialReportPdf(Request $request, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $payload = $this->buildFinancialLedgerPdfPayload($request);
        $validation = $documentValidationService->create(
            documentType: 'financial_ledger',
            title: 'Reporte financiero por cuenta',
            snapshot: [
                'club_id' => $payload['club']->id,
                'filters' => $payload['filters'],
                'accounts' => collect($payload['accounts'])->map(fn ($account) => [
                    'pay_to' => $account['pay_to'] ?? null,
                    'label' => $account['label'] ?? null,
                    'totals' => $account['totals'] ?? [],
                    'entries' => collect($account['entries'] ?? [])->map(fn ($entry) => [
                        'date' => $entry['date'] ?? null,
                        'entry_type' => $entry['entry_type'] ?? null,
                        'location' => $entry['location'] ?? null,
                        'from_location' => $entry['from_location'] ?? null,
                        'to_location' => $entry['to_location'] ?? null,
                        'receipt_ref' => $entry['receipt_ref'] ?? null,
                        'concept' => $entry['concept'] ?? null,
                        'amount' => $entry['amount'] ?? null,
                    ])->all(),
                ])->all(),
            ],
            metadata: [
                'Club' => $payload['club']->club_name,
                'Documento' => 'Reporte financiero por cuenta',
                'Cuentas' => (string) count($payload['accounts']),
                'Movimientos' => (string) collect($payload['accounts'])->sum(fn ($account) => count($account['entries'] ?? [])),
            ],
            generatedBy: $request->user(),
            generatedAt: $payload['generatedAt'],
        );

        $pdf = Pdf::loadView('reports.financial_ledger_print', [
            'club' => $payload['club'],
            'accounts' => $payload['accounts'],
            'receipts' => $payload['receipts'],
            'filters' => $payload['filters'],
            'generatedAt' => $payload['generatedAt'],
            'clubLogoDataUri' => $clubLogoService->dataUri($payload['club']),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'landscape');

        $filename = 'financial-ledger-club-' . $payload['club']->id . '-' . now()->format('Ymd-His') . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    protected function buildFinancialLedgerPdfPayload(Request $request): array
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubId = $club->id;

        $validated = $request->validate([
            'concept_id' => ['nullable', 'integer', Rule::exists('payment_concepts', 'id')->where(fn($q) => $q->where('club_id', $clubId))],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', Rule::in(['cash', 'bank'])],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $payTo = $validated['pay_to'] ?? null;
        if ($payTo) {
            $exists = Account::query()
                ->where('club_id', $club->id)
                ->where('pay_to', $payTo)
                ->exists();
            if (!$exists) {
                abort(422, 'Cuenta invalida.');
            }
        }

        $report = $this->buildFinancialAccountLedgerData($club, $validated, true);
        $concept = null;
        if (!empty($validated['concept_id'])) {
            $concept = PaymentConcept::query()
                ->where('club_id', $club->id)
                ->where('id', $validated['concept_id'])
                ->first(['id', 'concept']);
        }

	        return [
	            'club' => $club,
	            'accounts' => $report['accounts'],
	            'receipts' => $report['receipts'],
	            'filters' => [
	                'pay_to' => $payTo,
	                'location' => $validated['location'] ?? null,
	                'concept' => $concept,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'date' => $validated['date'] ?? null,
            ],
            'generatedAt' => now(),
        ];
    }

    protected function buildFinancialAccountLedgerData(Club $club, array $filters = [], bool $includeReceipts = false): array
    {
	        $payTo = $filters['pay_to'] ?? null;
	        $conceptId = $filters['concept_id'] ?? null;
	        $location = $filters['location'] ?? null;
	        $receiptAnnexes = collect();
	        $treasuryService = app(ClubTreasuryService::class);

        $paymentsQ = Payment::query()
            ->where('club_id', $club->id)
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept,amount',
                'account:id,club_id,pay_to,label',
                'receivedBy:id,name',
            ]);

        if ($payTo) {
            $paymentsQ->where('pay_to', $payTo);
        }
	        if ($conceptId) {
	            $paymentsQ->where('payment_concept_id', $conceptId);
	        }
	        if ($location === TreasuryMovement::LOCATION_BANK) {
	            $paymentsQ->whereIn('payment_type', $treasuryService->electronicPaymentTypes());
	        } elseif ($location === TreasuryMovement::LOCATION_CASH) {
	            $paymentsQ->where(function ($query) {
	                $query->whereIn('payment_type', ['cash', 'initial'])
	                    ->orWhereNull('payment_type');
	            });
	        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $from = $filters['date_from'] ?? '1900-01-01';
            $to = $filters['date_to'] ?? '2999-12-31';
            $paymentsQ->whereBetween('payment_date', [$from, $to]);
        } elseif (!empty($filters['date'])) {
            $paymentsQ->whereDate('payment_date', $filters['date']);
        }

        $payments = $this->attachPaymentPayerNames($paymentsQ->get());

        $expensesQ = Expense::query()
            ->where('club_id', $club->id);

	        if ($payTo) {
	            $expensesQ->where('pay_to', $payTo);
	        }
	        if ($location === TreasuryMovement::LOCATION_BANK) {
	            $expensesQ->where('funds_location', TreasuryMovement::LOCATION_BANK);
	        } elseif ($location === TreasuryMovement::LOCATION_CASH) {
	            $expensesQ->where(function ($query) {
	                $query->where('funds_location', TreasuryMovement::LOCATION_CASH)
	                    ->orWhere(function ($nested) {
	                        $nested->whereNull('funds_location')
	                            ->where('pay_to', '!=', 'reimbursement_to');
	                    });
	            });
	        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $from = $filters['date_from'] ?? '1900-01-01';
            $to = $filters['date_to'] ?? '2999-12-31';
            $expensesQ->whereBetween('expense_date', [$from, $to]);
        } elseif (!empty($filters['date'])) {
            $expensesQ->whereDate('expense_date', $filters['date']);
        }

	        $expenses = $expensesQ
	            ->with(['settlementExpense:id,pay_to,expense_date'])
	            ->get([
	                'id',
	                'pay_to',
	                'funds_location',
	                'amount',
	                'expense_date',
	                'description',
	                'status',
	                'reimbursed_to',
	                'receipt_path',
	                'reimbursement_receipt_path',
	                'settles_expense_id',
	                'created_at',
	            ]);

	        $accountLabels = Account::query()
	            ->where('club_id', $club->id)
	            ->get(['pay_to', 'label'])
	            ->mapWithKeys(fn($a) => [$a->pay_to => $a->label])
	            ->all();
	        $locationBalances = $treasuryService
	            ->locationBalancesByAccount($club)
	            ->keyBy('account');

        $entriesByAccount = [];

	        foreach ($payments as $p) {
	            $key = $p->pay_to ?? $p->account?->pay_to ?? 'unassigned';
            $receiptRef = !empty($p->check_image_path)
                ? $this->receiptReference('payment', $p->id)
                : null;
            if ($includeReceipts && $receiptRef) {
                $receiptAnnexes->push($this->buildReceiptAnnex($receiptRef, $p->check_image_path, $p->id, 'Payment'));
            }
            $entriesByAccount[$key][] = [
                'entry_type' => 'payment',
                'id' => $p->id,
                'date' => $p->payment_date,
                'created_at' => $p->created_at?->format('Y-m-d H:i:s.u'),
	                'amount' => (float) $p->amount_paid,
	                'payment_type' => $p->payment_type,
	                'location' => $treasuryService->paymentLocation($p->payment_type),
	                'from_location' => null,
	                'to_location' => null,
	                'concept' => $p->concept?->concept ?? $p->concept_text ?? '—',
                'member' => $p->member?->applicant_name ?? null,
                'staff' => $p->staff?->name ?? null,
                'receipt_ref' => $receiptRef,
	                'receipt_refs' => $receiptRef ? [$receiptRef] : [],
	            ];
	        }

	        if (!$conceptId) {
	            $movementsQ = TreasuryMovement::query()
	                ->where('club_id', $club->id)
	                ->with(['event:id,title', 'eventClubSettlement:id,receipt_number']);

	            if ($payTo) {
	                $movementsQ->where('pay_to', $payTo);
	            }
	            if ($location) {
	                $movementsQ->where(function ($query) use ($location) {
	                    $query->where('from_location', $location)
	                        ->orWhere('to_location', $location);
	                });
	            }
	            if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
	                $from = $filters['date_from'] ?? '1900-01-01';
	                $to = $filters['date_to'] ?? '2999-12-31';
	                $movementsQ->whereBetween('movement_date', [$from, $to]);
	            } elseif (!empty($filters['date'])) {
	                $movementsQ->whereDate('movement_date', $filters['date']);
	            }

	            foreach ($movementsQ->get() as $movement) {
	                $key = $movement->pay_to ?? 'club_budget';
	                $receiptRef = $movement->eventClubSettlement?->receipt_number;

	                $entriesByAccount[$key][] = [
	                    'entry_type' => 'treasury_movement',
	                    'id' => $movement->id,
	                    'date' => $movement->movement_date,
	                    'created_at' => $movement->created_at?->format('Y-m-d H:i:s.u'),
	                    'amount' => (float) $movement->amount,
	                    'payment_type' => null,
	                    'location' => null,
	                    'from_location' => $movement->from_location,
	                    'to_location' => $movement->to_location,
	                    'concept' => $this->treasuryMovementLabel($movement) . ($movement->event?->title ? ': ' . $movement->event->title : ''),
	                    'member' => null,
	                    'staff' => null,
	                    'status' => null,
	                    'settlement_account' => null,
	                    'settlement_account_label' => null,
	                    'settlement_date' => null,
	                    'receipt_ref' => $receiptRef ?: $movement->reference,
	                    'receipt_refs' => $receiptRef ? [$receiptRef] : [],
	                ];
	            }
	        }

	        foreach ($expenses as $e) {
	            $key = $e->pay_to ?? 'unassigned';
	            $expenseLocation = $e->funds_location
	                ?: ($e->pay_to === 'reimbursement_to' ? 'internal' : TreasuryMovement::LOCATION_CASH);
	            $receiptRefs = [];
            if (!empty($e->receipt_path)) {
                $receiptRef = $this->receiptReference('expense', $e->id);
                $receiptRefs[] = $receiptRef;
                if ($includeReceipts) {
                    $receiptAnnexes->push($this->buildReceiptAnnex($receiptRef, $e->receipt_path, $e->id, 'Expense'));
                }
            }
            if (!empty($e->reimbursement_receipt_path)) {
                $reimbursementRef = $this->receiptReference('reimbursement', $e->id);
                $receiptRefs[] = $reimbursementRef;
                if ($includeReceipts) {
                    $receiptAnnexes->push($this->buildReceiptAnnex($reimbursementRef, $e->reimbursement_receipt_path, $e->id, 'Reimbursement'));
                }
            }
            $entriesByAccount[$key][] = [
                'entry_type' => 'expense',
                'id' => $e->id,
                'date' => $e->expense_date,
                'created_at' => $e->created_at?->format('Y-m-d H:i:s.u'),
	                'amount' => (float) $e->amount,
	                'payment_type' => null,
	                'location' => $expenseLocation,
	                'from_location' => null,
	                'to_location' => null,
	                'concept' => $e->description ?? '—',
                'member' => null,
                'staff' => $e->reimbursed_to,
                'status' => $e->status,
                'settlement_account' => $e->settlementExpense?->pay_to,
                'settlement_account_label' => $e->settlementExpense?->pay_to
                    ? ($accountLabels[$e->settlementExpense->pay_to] ?? $e->settlementExpense->pay_to)
                    : null,
                'settlement_date' => $e->settlementExpense?->expense_date,
                'receipt_ref' => count($receiptRefs) ? implode(', ', $receiptRefs) : null,
                'receipt_refs' => $receiptRefs,
            ];
        }

	        $accounts = collect($entriesByAccount)
	            ->map(function ($entries, $payToKey) use ($accountLabels, $locationBalances) {
                usort($entries, function ($a, $b) {
                    return [
                        $a['date'],
                        $a['created_at'] ?? '',
                        $a['id'],
                        $a['entry_type'],
                    ] <=> [
                        $b['date'],
                        $b['created_at'] ?? '',
                        $b['id'],
                        $b['entry_type'],
                    ];
                });

	                $paid = array_sum(array_map(fn($e) => $e['entry_type'] === 'payment' ? $e['amount'] : 0, $entries));
	                $spent = array_sum(array_map(fn($e) => $e['entry_type'] === 'expense' ? $e['amount'] : 0, $entries));
	                $movements = array_sum(array_map(fn($e) => $e['entry_type'] === 'treasury_movement' ? $e['amount'] : 0, $entries));
	                $currentLocation = $locationBalances->get($payToKey, []);

	                return [
                    'pay_to' => $payToKey,
                    'label' => $accountLabels[$payToKey] ?? ($payToKey === 'unassigned' ? 'Cuenta sin asignar' : $payToKey),
                    'totals' => [
	                        'paid' => $paid,
	                        'spent' => $spent,
	                        'movements' => $movements,
	                        'net' => $paid - $spent,
	                        'cash_balance' => (float) ($currentLocation['cash_balance'] ?? 0),
	                        'bank_balance' => (float) ($currentLocation['bank_balance'] ?? 0),
	                    ],
                    'entries' => array_values($entries),
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

	        return [
	            'accounts' => $accounts,
	            'receipts' => $receiptAnnexes->values(),
	        ];
	    }

	    protected function treasuryMovementLabel(TreasuryMovement $movement): string
	    {
	        return match ($movement->movement_type) {
	            TreasuryMovement::TYPE_CASH_DEPOSIT => 'Depósito de efectivo a banco',
	            TreasuryMovement::TYPE_CASH_WITHDRAWAL => 'Retiro de banco a efectivo',
	            TreasuryMovement::TYPE_EVENT_SETTLEMENT => 'Transferencia externa de evento',
	            default => $movement->movement_type,
	        };
	    }

	    protected function buildReceiptAnnex(string $ref, string $path, int $id, string $labelPrefix): array
    {
        $fullPath = storage_path('app/public/' . ltrim($path, '/'));
        $dataUri = null;
        if (file_exists($fullPath)) {
            $mime = mime_content_type($fullPath) ?: 'image/jpeg';
            $data = base64_encode(file_get_contents($fullPath));
            $dataUri = "data:$mime;base64,$data";
        }

        return [
            'ref' => $ref,
            'source' => $labelPrefix,
            'record_id' => $id,
            'data_uri' => $dataUri,
            'filename' => basename($path),
        ];
    }

    protected function receiptReference(string $kind, int|string $id): string
    {
        return match ($kind) {
            'payment' => 'PAY-' . $id,
            'expense' => 'EXP-' . $id,
            'reimbursement' => 'REIMB-' . $id,
            default => strtoupper($kind) . '-' . $id,
        };
    }

    protected function attachPaymentPayerNames(Collection $rows): Collection
    {
        return $rows->map(function ($p) {
            $memberDetail = ClubHelper::memberDetail($p->member);
            $staffDetail = ClubHelper::staffDetail($p->staff);
            $memberName = $memberDetail['name'] ?? null;
            $staffName = $staffDetail['name'] ?? ($p->staff?->user?->name ?? null);

            $p->setRelation('member', $p->member ? [
                'id' => $p->member->id,
                'applicant_name' => $memberName ?? '—',
            ] : null);

            $p->setRelation('staff', $p->staff ? [
                'id' => $p->staff->id,
                'name' => $staffName ?? '—',
            ] : null);

            return $p;
        });
    }

    protected function buildSummaryFromRows(Collection $rows): array
    {
        $totalPaid = (float) $rows->sum('amount_paid');

        $groups = $rows->groupBy(function ($p) {
            $payerKey = $p->member_id
                ? ('m:' . $p->member_id)
                : ('s:' . $p->staff_id);
            return $payerKey . '|c:' . $p->payment_concept_id;
        });

        $chargeSummaries = $groups->map(function ($paymentsForCharge) {
            $isReusable = (bool) data_get($paymentsForCharge->first(), 'concept.reusable', false);
            if ($isReusable) {
                return ['expected' => 0.0, 'paid' => (float) $paymentsForCharge->sum('amount_paid'), 'remaining' => 0.0, 'countable' => false];
            }
            $expected = (float) $paymentsForCharge->max('expected_amount');
            $paid = (float) $paymentsForCharge->sum('amount_paid');
            $remaining = max($expected - $paid, 0.0);
            return ['expected' => $expected, 'paid' => $paid, 'remaining' => $remaining, 'countable' => true];
        });

        $countableChargeSummaries = $chargeSummaries->filter(fn ($row) => !empty($row['countable']));

        $byType = $rows->groupBy('payment_type')
            ->mapWithKeys(fn($g, $t) => [$t => (float) $g->sum('amount_paid')])
            ->all();

        foreach (['cash', 'zelle', 'check', 'transfer', 'initial'] as $t) {
            $byType[$t] = (float) ($byType[$t] ?? 0.0);
        }

        return [
            'payments_count' => $rows->count(),
            'charges_count' => $countableChargeSummaries->count(),
            'amount_paid_sum' => $totalPaid,
            'expected_sum' => (float) $countableChargeSummaries->sum('expected'),
            'balance_remaining' => (float) $countableChargeSummaries->sum('remaining'),
            'by_payment_type' => $byType,
        ];
    }

    public function financialAccountBalancesPdf(Request $request, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $data = $this->buildAccountReportData($club);
        $generatedAt = now();
        $validation = $documentValidationService->create(
            documentType: 'financial_account_balances',
            title: 'Balance de cuentas',
            snapshot: [
                'club_id' => $club->id,
                'accounts' => $data['accounts'],
                'payments' => collect($data['payments'])->map(fn ($payment) => [
                    'id' => $payment['id'] ?? null,
                    'payment_date' => $payment['payment_date'] ?? null,
                    'amount_paid' => $payment['amount_paid'] ?? null,
                    'receipt_ref' => $payment['receipt_ref'] ?? null,
                    'account' => $payment['account'] ?? null,
                    'location' => $payment['location'] ?? null,
                    'zelle_phone' => $payment['zelle_phone'] ?? null,
                ])->all(),
                'expenses' => collect($data['expenses'])->map(fn ($expense) => [
                    'id' => $expense['id'] ?? null,
                    'expense_date' => $expense['expense_date'] ?? null,
                    'amount' => $expense['amount'] ?? null,
                    'receipt_ref' => $expense['receipt_ref'] ?? null,
                    'pay_to' => $expense['pay_to'] ?? null,
                    'location' => $expense['location'] ?? null,
                ])->all(),
            ],
            metadata: [
                'Club' => $club->club_name,
                'Documento' => 'Balance de cuentas',
                'Cuentas' => (string) count($data['accounts']),
                'Ingresos' => (string) count($data['payments']),
                'Gastos' => (string) count($data['expenses']),
            ],
            generatedBy: $user,
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('reports.account_balances', [
            'club' => $club,
            'accounts' => $data['accounts'],
            'payments' => $data['payments'],
            'expenses' => $data['expenses'],
            'receipts' => $data['receipts'] ?? [],
            'generatedAt' => $generatedAt,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'landscape');

        return $pdf->download('account-balances.pdf');
    }

    public function financialAccountBalances(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubs = Club::where('user_id', $user->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name']);

        $data = $this->buildAccountReportData($club);
        $data['club_id'] = $club->id;
        $data['clubs'] = $clubs;

        return response()->json(['data' => $data]);
    }

    protected function buildAccountReportData(Club $club): array
    {
        // Fetch label map for pay_to from accounts
        $payToLabelMap = Account::query()
            ->where('club_id', $club->id)
            ->get(['pay_to', 'label'])
            ->mapWithKeys(fn($a) => [$a->pay_to => $a->label])
            ->all();

        // Sum income by pay_to
        $incomeByAccount = Payment::query()
            ->where('payments.club_id', $club->id)
            ->selectRaw('COALESCE(payments.pay_to, \'unassigned\') as account, COALESCE(SUM(payments.amount_paid), 0) as total')
            ->groupBy('account')
            ->pluck('total', 'account')
            ->map(fn ($v) => (float) $v);

        // Sum expenses by pay_to.
        // For reimbursement_to: only count pending entries — completed ones are already
        // reflected as outflow expenses on the funding account created by markReimbursed.
        $expensesByAccount = Expense::query()
            ->where('club_id', $club->id)
            ->where(fn ($q) => $q
                ->where('pay_to', '!=', 'reimbursement_to')
                ->orWhere('status', 'pending_reimbursement')
            )
            ->selectRaw('pay_to as account, COALESCE(SUM(amount), 0) as total')
            ->groupBy('pay_to')
            ->pluck('total', 'account')
            ->map(fn ($v) => (float) $v);

        // Build summary from union of all account keys so expense-only accounts
        // (like reimbursement_to which never receives payments) are always shown.
        $allAccountKeys = $incomeByAccount->keys()->merge($expensesByAccount->keys())->unique();
        $treasuryService = app(ClubTreasuryService::class);
        $locationBalances = $treasuryService
            ->locationBalancesByAccount($club)
            ->keyBy('account');

        $entries = $allAccountKeys
            ->merge($locationBalances->keys())
            ->unique()
            ->map(function ($account) use ($incomeByAccount, $expensesByAccount, $payToLabelMap, $locationBalances) {
                $income = (float) ($incomeByAccount[$account] ?? 0.0);
                $expenses = (float) ($expensesByAccount[$account] ?? 0.0);
                $location = $locationBalances->get($account, []);

                return [
                    'account' => $account,
                    'label' => $payToLabelMap[$account] ?? ($account === 'unassigned' ? 'Cuenta sin asignar' : $account),
                    'entries' => $income,
                    'expenses' => $expenses,
                    'balance' => $income - $expenses,
                    'cash_balance' => (float) ($location['cash_balance'] ?? 0),
                    'bank_balance' => (float) ($location['bank_balance'] ?? 0),
                    'cash_income' => (float) ($location['cash_income'] ?? 0),
                    'bank_income' => (float) ($location['bank_income'] ?? 0),
                    'cash_expenses' => (float) ($location['cash_expenses'] ?? 0),
                    'bank_expenses' => (float) ($location['bank_expenses'] ?? 0),
                ];
            })->values();

        // Detailed payment rows for income table
        $payments = Payment::query()
            ->where('payments.club_id', $club->id)
            ->leftJoin('payment_concepts', 'payment_concepts.id', '=', 'payments.payment_concept_id')
            ->leftJoin('accounts as acc', 'acc.id', '=', 'payments.account_id')
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('payments.id')
            ->get([
                'payments.id',
                'payments.payment_date',
                'payments.amount_paid',
                'payments.payment_type',
                'payments.zelle_phone',
                'payments.member_id',
                'payments.staff_id',
                'payments.payment_concept_id',
                'payments.check_image_path',
                'payments.concept_text',
                DB::raw('COALESCE(payments.pay_to, acc.pay_to) as account'),
                'acc.label as account_label',
                'payment_concepts.concept as concept_name',
            ])
            ->map(function ($p) use ($payToLabelMap, $treasuryService) {
                $ref = null;
                $url = null;
                if ($p->check_image_path) {
                    $ref = $this->receiptReference('payment', $p->id);
                    $url = $this->toPublicUrl($p->check_image_path);
                }
                return [
                    'id' => $p->id,
                    'payment_date' => $p->payment_date,
                    'amount_paid' => (float) $p->amount_paid,
                    'payment_type' => $p->payment_type,
                    'zelle_phone' => $p->zelle_phone,
                    'location' => $treasuryService->paymentLocation($p->payment_type),
                    'account' => $p->account ?? 'unassigned',
                    'account_label' => $p->account_label ?? $payToLabelMap[$p->account] ?? (($p->account ?? null) === 'unassigned' ? 'Cuenta sin asignar' : ($p->account ?? 'Cuenta sin asignar')),
                    'concept' => $p->concept_name ?? $p->concept_text ?? '—',
                    'member' => $p->member ? ['id' => $p->member->id, 'applicant_name' => (ClubHelper::memberDetail($p->member)['name'] ?? '—')] : null,
                    'staff' => $p->staff ? ['id' => $p->staff->id, 'name' => (ClubHelper::staffDetail($p->staff)['name'] ?? ($p->staff->user?->name ?? '—'))] : null,
                    'receipt_path' => $p->check_image_path,
                    'receipt_ref' => $ref,
                    'receipt_url' => $url,
                ];
            })
            ->values();

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->with('event:id,title')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get(['id', 'event_id', 'pay_to', 'funds_location', 'amount', 'expense_date', 'description', 'reimbursed_to', 'status', 'receipt_path', 'reimbursement_receipt_path'])
            ->values();

        // Assign receipt references and map to DTOs
        $expenseRows = $expenses->map(function ($e) use ($payToLabelMap) {
            $ref = null;
            if ($e->receipt_path) {
                $ref = $this->receiptReference('expense', $e->id);
            }
            $reimburseRef = null;
            if ($e->reimbursement_receipt_path) {
                $reimburseRef = $this->receiptReference('reimbursement', $e->id);
            }
            return [
                'id' => $e->id,
                'pay_to' => $e->pay_to,
                'pay_to_label' => $payToLabelMap[$e->pay_to] ?? $e->pay_to,
                'location' => $e->funds_location,
                'amount' => (float) $e->amount,
                'expense_date' => $e->expense_date,
                'description' => $e->description,
                'event_id' => $e->event_id,
                'event_title' => $e->event?->title,
                'is_event_related' => !empty($e->event_id),
                'reimbursed_to' => $e->reimbursed_to,
                'status' => $e->status,
                'receipt_path' => $e->receipt_path,
                'receipt_ref' => $ref,
                'receipt_url' => $e->receipt_url ?? null,
                'reimbursement_receipt_path' => $e->reimbursement_receipt_path,
                'reimbursement_receipt_ref' => $reimburseRef,
                'reimbursement_receipt_url' => $e->reimbursement_receipt_url ?? null,
            ];
        });

        $buildAnnex = function ($ref, $path, $id, $labelPrefix) {
            $fullPath = storage_path('app/public/' . ltrim($path, '/'));
            $dataUri = null;
            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath) ?: 'image/jpeg';
                $data = base64_encode(file_get_contents($fullPath));
                $dataUri = "data:$mime;base64,$data";
            }
            return [
                'ref' => $ref,
                'source' => $labelPrefix,
                'record_id' => $id,
                'data_uri' => $dataUri,
                'filename' => basename($path),
            ];
        };

        $receiptAnnexes = collect();
        $expenseRows->filter(fn($e) => $e['receipt_path'])->each(function ($e) use (&$receiptAnnexes, $buildAnnex) {
            $receiptAnnexes->push($buildAnnex($e['receipt_ref'], $e['receipt_path'], $e['id'], 'Expense'));
        });
        $expenseRows->filter(fn($e) => $e['reimbursement_receipt_path'])->each(function ($e) use (&$receiptAnnexes, $buildAnnex) {
            $receiptAnnexes->push($buildAnnex($e['reimbursement_receipt_ref'], $e['reimbursement_receipt_path'], $e['id'], 'Reimbursement'));
        });

        $payments->filter(fn($p) => $p['receipt_path'])->each(function ($p) use (&$receiptAnnexes, $buildAnnex) {
            $receiptAnnexes->push($buildAnnex($p['receipt_ref'], $p['receipt_path'], $p['id'], 'Payment'));
        });

        return [
            'accounts' => $entries,
            'payments' => $payments,
            'expenses' => $expenseRows,
            'receipts' => $receiptAnnexes->values(),
        ];
    }

    protected function toPublicUrl(?string $path): ?string
    {
        if (!$path) return null;

        $url = Storage::disk('public')->url($path);
        $host = request()?->getSchemeAndHttpHost();

        if (Str::startsWith($url, ['http://', 'https://'])) {
            if (!$host) return $url;
            $parsed = parse_url($url);
            $combined = rtrim($host, '/');
            $combined .= $parsed['path'] ?? '';
            if (!empty($parsed['query'])) {
                $combined .= '?' . $parsed['query'];
            }
            return $combined;
        }

        $url = Str::start($url, '/');
        return $host ? rtrim($host, '/') . $url : url($url);
    }
}
