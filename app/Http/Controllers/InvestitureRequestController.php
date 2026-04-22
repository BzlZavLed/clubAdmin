<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\District;
use App\Models\InvestitureRequest;
use App\Models\Member;
use App\Models\ParentCarpetaRequirementEvidence;
use App\Models\UnionCarpetaRequirement;
use App\Models\UnionCarpetaYear;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvestitureRequestController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer'],
            'director_notes' => ['nullable', 'string', 'max:2000'],
            'tentative_investiture_date' => ['nullable', 'date'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.member_id' => ['required', 'integer', 'exists:members,id'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.class_name' => ['nullable', 'string', 'max:255'],
            'members.*.requirements' => ['nullable', 'array'],
            'members.*.requirements.*.id' => ['required', 'integer', 'exists:union_carpeta_requirements,id'],
            'members.*.requirements.*.evidence.id' => ['nullable', 'integer', 'exists:parent_carpeta_requirement_evidences,id'],
            'members.*.requirements.*.completed' => ['nullable', 'boolean'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), (int) $validated['club_id'])
            ->loadMissing('district.association.union');

        abort_unless(($club->evaluation_system ?? 'honors') === 'carpetas', 422, 'Solo los clubes con sistema de carpetas pueden solicitar investidura.');

        $union = $club->district?->association?->union;
        abort_unless($union, 422, 'El club no tiene jerarquía completa de unión/asociación/distrito.');

        $carpetaYear = UnionCarpetaYear::query()
            ->where('union_id', $union->id)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        abort_unless($carpetaYear, 422, 'La unión no tiene un ciclo de carpeta publicado.');

        $hasOpenRequest = InvestitureRequest::query()
            ->where('club_id', $club->id)
            ->where('union_carpeta_year_id', $carpetaYear->id)
            ->whereIn('status', [
                InvestitureRequest::STATUS_SUBMITTED,
                InvestitureRequest::STATUS_ASSIGNED,
                InvestitureRequest::STATUS_IN_REVIEW,
                InvestitureRequest::STATUS_COMPLETED,
                InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
                InvestitureRequest::STATUS_RETURNED,
                InvestitureRequest::STATUS_AUTHORIZED,
            ])
            ->exists();

        abort_if($hasOpenRequest, 422, 'Ya existe una solicitud de investidura abierta para este ciclo de carpeta.');

        $memberIds = collect($validated['members'])->pluck('member_id')->map(fn ($id) => (int) $id)->unique()->values();
        $validMemberIds = Member::query()
            ->where('club_id', $club->id)
            ->whereIn('id', $memberIds)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_if($validMemberIds->count() !== $memberIds->count(), 422, 'Algunos miembros no pertenecen al club activo.');

        $requirementIds = collect($validated['members'])
            ->flatMap(fn ($member) => collect($member['requirements'] ?? [])->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $validRequirementIds = UnionCarpetaRequirement::query()
            ->where('union_carpeta_year_id', $carpetaYear->id)
            ->where('status', 'active')
            ->whereIn('id', $requirementIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_if($validRequirementIds->count() !== $requirementIds->count(), 422, 'Algunos requisitos no pertenecen al ciclo de carpeta publicado.');

        $evidenceIds = collect($validated['members'])
            ->flatMap(fn ($member) => collect($member['requirements'] ?? [])->pluck('evidence.id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $validEvidenceIds = ParentCarpetaRequirementEvidence::query()
            ->whereIn('id', $evidenceIds)
            ->whereIn('member_id', $memberIds)
            ->whereIn('union_carpeta_requirement_id', $requirementIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_if($validEvidenceIds->count() !== $evidenceIds->count(), 422, 'Algunas evidencias no corresponden al miembro o requisito enviado.');

        $investitureRequest = DB::transaction(function () use ($validated, $request, $club, $union, $carpetaYear) {
            $record = InvestitureRequest::query()->create([
                'union_id' => $union->id,
                'association_id' => $club->district->association_id,
                'district_id' => $club->district_id,
                'club_id' => $club->id,
                'union_carpeta_year_id' => $carpetaYear->id,
                'carpeta_year' => $carpetaYear->year,
                'club_type' => $club->club_type,
                'status' => InvestitureRequest::STATUS_SUBMITTED,
                'director_notes' => $validated['director_notes'] ?? null,
                'tentative_investiture_date' => $validated['tentative_investiture_date'] ?? null,
                'requested_by' => $request->user()?->id,
                'submitted_at' => now(),
            ]);

            foreach ($validated['members'] as $memberPayload) {
                $requirements = collect($memberPayload['requirements'] ?? []);
                $requestMember = $record->members()->create([
                    'member_id' => (int) $memberPayload['member_id'],
                    'member_name' => $memberPayload['name'],
                    'class_name' => $memberPayload['class_name'] ?? null,
                    'requirements_count' => $requirements->count(),
                    'completed_requirements_count' => $requirements->where('completed', true)->count(),
                    'status' => 'pending_review',
                ]);

                foreach ($requirements as $requirementPayload) {
                    $requestMember->requirementReviews()->create([
                        'union_carpeta_requirement_id' => (int) $requirementPayload['id'],
                        'parent_carpeta_requirement_evidence_id' => data_get($requirementPayload, 'evidence.id'),
                        'status' => 'pending',
                    ]);
                }
            }

            return $record->loadCount('members');
        });

        return back()->with('success', "Solicitud de investidura #{$investitureRequest->id} creada.");
    }

    public function associationIndex(Request $request)
    {
        $association = $this->resolveScopedAssociation($request)->loadMissing('union');

        $requests = InvestitureRequest::query()
            ->where('association_id', $association->id)
            ->with([
                'club:id,club_name,church_name,district_id',
                'district:id,name,pastor_name,pastor_email,is_evaluator',
                'members:id,investiture_request_id,status,requirements_count,completed_requirements_count',
            ])
            ->latest('submitted_at')
            ->latest('id')
            ->get()
            ->map(fn (InvestitureRequest $investitureRequest) => $this->serializeRequest($investitureRequest));

        return Inertia::render('Association/InvestitureRequests', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $association->union?->evaluation_system ?? 'honors',
            ],
            'requests' => $requests,
        ]);
    }

    public function assignDistrictPastor(Request $request, InvestitureRequest $investitureRequest)
    {
        $association = $this->resolveScopedAssociation($request);

        abort_if((int) $investitureRequest->association_id !== (int) $association->id, 403);
        abort_unless(in_array($investitureRequest->status, [
            InvestitureRequest::STATUS_SUBMITTED,
            InvestitureRequest::STATUS_ASSIGNED,
            InvestitureRequest::STATUS_RETURNED,
        ], true), 422, 'Esta solicitud no puede ser reasignada en su estado actual.');

        $district = District::query()->findOrFail($investitureRequest->district_id);
        abort_unless($district->pastor_name || $district->pastor_email, 422, 'El distrito no tiene pastor configurado para asignar la solicitud.');

        $investitureRequest->forceFill([
            'status' => InvestitureRequest::STATUS_ASSIGNED,
            'assigned_evaluator_type' => 'district_pastor',
            'assigned_evaluator_id' => $district->id,
            'assigned_evaluator_name' => $district->pastor_name,
            'assigned_evaluator_email' => $district->pastor_email,
            'assigned_at' => now(),
            'assigned_by' => $request->user()?->id,
        ])->save();

        return back()->with('success', 'Solicitud asignada al pastor distrital.');
    }

    public function authorizeInvestiture(Request $request, InvestitureRequest $investitureRequest)
    {
        $association = $this->resolveScopedAssociation($request);

        abort_if((int) $investitureRequest->association_id !== (int) $association->id, 403);
        abort_unless($investitureRequest->status === InvestitureRequest::STATUS_COMPLETED, 422, 'Solo se pueden autorizar solicitudes completadas por el evaluador.');

        $validated = $request->validate([
            'authorization_person_name' => ['required', 'string', 'max:255'],
            'ceremony_representative_name' => ['required', 'string', 'max:255'],
            'ceremony_representative_email' => ['nullable', 'email', 'max:255'],
            'ceremony_representative_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $investitureRequest->forceFill([
            'status' => InvestitureRequest::STATUS_AUTHORIZED,
            'authorized_by' => $request->user()?->id,
            'authorized_at' => now(),
            'authorization_person_name' => $validated['authorization_person_name'],
            'ceremony_representative_name' => $validated['ceremony_representative_name'],
            'ceremony_representative_email' => $validated['ceremony_representative_email'] ?? null,
            'ceremony_representative_phone' => $validated['ceremony_representative_phone'] ?? null,
            'approved_investiture_date' => $investitureRequest->tentative_investiture_date,
            'date_change_reason' => null,
            'date_change_requested_at' => null,
            'date_change_requested_by' => null,
        ])->save();

        return back()->with('success', 'Investidura autorizada por la asociación.');
    }

    public function requestNewDate(Request $request, InvestitureRequest $investitureRequest)
    {
        $association = $this->resolveScopedAssociation($request);

        abort_if((int) $investitureRequest->association_id !== (int) $association->id, 403);
        abort_unless($investitureRequest->status === InvestitureRequest::STATUS_COMPLETED, 422, 'Solo se puede pedir nueva fecha cuando la evaluación está completada.');

        $validated = $request->validate([
            'date_change_reason' => ['required', 'string', 'max:2000'],
        ]);

        $investitureRequest->forceFill([
            'status' => InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
            'date_change_reason' => $validated['date_change_reason'],
            'date_change_requested_at' => now(),
            'date_change_requested_by' => $request->user()?->id,
        ])->save();

        return back()->with('success', 'Se solicitó al club proponer una nueva fecha.');
    }

    public function updateTentativeDate(Request $request, InvestitureRequest $investitureRequest)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer'],
            'tentative_investiture_date' => ['required', 'date'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), (int) $validated['club_id']);

        abort_if((int) $investitureRequest->club_id !== (int) $club->id, 403);
        abort_unless(in_array($investitureRequest->status, [
            InvestitureRequest::STATUS_SUBMITTED,
            InvestitureRequest::STATUS_ASSIGNED,
            InvestitureRequest::STATUS_IN_REVIEW,
            InvestitureRequest::STATUS_COMPLETED,
            InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
            InvestitureRequest::STATUS_RETURNED,
        ], true), 422, 'Esta solicitud no permite editar la fecha tentativa.');

        $nextStatus = $investitureRequest->status === InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED
            ? InvestitureRequest::STATUS_COMPLETED
            : $investitureRequest->status;

        $investitureRequest->forceFill([
            'status' => $nextStatus,
            'tentative_investiture_date' => $validated['tentative_investiture_date'],
            'date_change_reason' => null,
            'date_change_requested_at' => null,
            'date_change_requested_by' => null,
        ])->save();

        return back()->with('success', 'Nueva fecha propuesta enviada a la asociación.');
    }

    public function updateCurrentTentativeDate(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['required', 'integer'],
            'tentative_investiture_date' => ['required', 'date'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), (int) $validated['club_id'])
            ->loadMissing('district.association.union');

        $union = $club->district?->association?->union;
        abort_unless($union, 422, 'El club no tiene jerarquía completa de unión/asociación/distrito.');

        $carpetaYear = UnionCarpetaYear::query()
            ->where('union_id', $union->id)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        abort_unless($carpetaYear, 422, 'La unión no tiene un ciclo de carpeta publicado.');

        $investitureRequest = InvestitureRequest::query()
            ->where('club_id', $club->id)
            ->where('union_carpeta_year_id', $carpetaYear->id)
            ->latest('id')
            ->firstOrFail();

        return $this->updateTentativeDateForClubRequest($request, $investitureRequest, $club, $validated['tentative_investiture_date']);
    }

    public function districtIndex(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->loadMissing('association.union');

        $requests = InvestitureRequest::query()
            ->where('district_id', $district->id)
            ->where('assigned_evaluator_type', 'district_pastor')
            ->where('assigned_evaluator_id', $district->id)
            ->whereIn('status', [
                InvestitureRequest::STATUS_ASSIGNED,
                InvestitureRequest::STATUS_IN_REVIEW,
                InvestitureRequest::STATUS_COMPLETED,
                InvestitureRequest::STATUS_AUTHORIZED,
                InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
                InvestitureRequest::STATUS_RETURNED,
            ])
            ->with([
                'club:id,club_name,church_name,district_id',
                'members:id,investiture_request_id,status,requirements_count,completed_requirements_count',
            ])
            ->latest('assigned_at')
            ->latest('id')
            ->get()
            ->map(fn (InvestitureRequest $investitureRequest) => $this->serializeRequest($investitureRequest));

        return Inertia::render('District/InvestitureRequests', [
            'district' => [
                'id' => $district->id,
                'name' => $district->name,
                'pastor_name' => $district->pastor_name,
                'pastor_email' => $district->pastor_email,
            ],
            'association' => [
                'id' => $district->association?->id,
                'name' => $district->association?->name,
            ],
            'union' => [
                'id' => $district->association?->union?->id,
                'name' => $district->association?->union?->name,
                'evaluation_system' => $district->association?->union?->evaluation_system ?? 'honors',
            ],
            'requests' => $requests,
        ]);
    }

    public function districtShow(Request $request, InvestitureRequest $investitureRequest)
    {
        $district = $this->resolveScopedDistrict($request)->loadMissing('association.union');
        $this->assertDistrictCanEvaluate($district, $investitureRequest);

        $investitureRequest->load([
            'club:id,club_name,church_name,district_id',
            'district:id,name,pastor_name,pastor_email,is_evaluator',
            'members' => fn ($query) => $query->orderBy('class_name')->orderBy('member_name'),
            'members.requirementReviews' => fn ($query) => $query->orderBy('id'),
            'members.requirementReviews.requirement:id,title,description,requirement_type,validation_mode,allowed_evidence_types,evidence_instructions,sort_order',
            'members.requirementReviews.evidence',
        ]);

        return Inertia::render('District/InvestitureEvaluation', [
            'district' => [
                'id' => $district->id,
                'name' => $district->name,
                'pastor_name' => $district->pastor_name,
                'pastor_email' => $district->pastor_email,
            ],
            'association' => [
                'id' => $district->association?->id,
                'name' => $district->association?->name,
            ],
            'union' => [
                'id' => $district->association?->union?->id,
                'name' => $district->association?->union?->name,
                'evaluation_system' => $district->association?->union?->evaluation_system ?? 'honors',
            ],
            'request' => $this->serializeEvaluationRequest($investitureRequest),
        ]);
    }

    public function updateRequirementReview(Request $request, InvestitureRequest $investitureRequest, \App\Models\InvestitureRequirementReview $review)
    {
        $district = $this->resolveScopedDistrict($request);
        $this->assertDistrictCanEvaluate($district, $investitureRequest);

        $review->loadMissing('requestMember');
        abort_if((int) $review->requestMember->investiture_request_id !== (int) $investitureRequest->id, 403);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,approved,rejected'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($validated, $request, $investitureRequest, $review) {
            $review->forceFill([
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => $validated['status'] === 'pending' ? null : now(),
            ])->save();

            $requestMember = $review->requestMember()->with('requirementReviews')->firstOrFail();
            $reviews = $requestMember->requirementReviews;

            $memberStatus = match (true) {
                $reviews->contains(fn ($item) => $item->status === 'rejected') => 'returned',
                $reviews->every(fn ($item) => $item->status === 'approved') => 'ready',
                $reviews->contains(fn ($item) => $item->status === 'approved') => 'in_review',
                default => 'pending_review',
            };

            $requestMember->forceFill([
                'status' => $memberStatus,
                'reviewed_by' => $memberStatus === 'ready' ? $request->user()?->id : null,
                'reviewed_at' => $memberStatus === 'ready' ? now() : null,
            ])->save();

            $requestMembers = $investitureRequest->members()->get();
            $requestStatus = match (true) {
                $requestMembers->isNotEmpty() && $requestMembers->every(fn ($member) => $member->status === 'ready') => InvestitureRequest::STATUS_COMPLETED,
                $requestMembers->contains(fn ($member) => $member->status === 'returned') => InvestitureRequest::STATUS_RETURNED,
                default => InvestitureRequest::STATUS_IN_REVIEW,
            };

            $investitureRequest->forceFill([
                'status' => $requestStatus,
                'completed_by' => $requestStatus === InvestitureRequest::STATUS_COMPLETED ? $request->user()?->id : null,
                'completed_at' => $requestStatus === InvestitureRequest::STATUS_COMPLETED ? now() : null,
            ])->save();
        });

        return back()->with('success', 'Evaluación actualizada.');
    }

    protected function serializeRequest(InvestitureRequest $investitureRequest): array
    {
        $members = $investitureRequest->members ?? collect();
        $requirementsCount = $members->sum('requirements_count');
        $completedRequirementsCount = $members->sum('completed_requirements_count');

        return [
            'id' => $investitureRequest->id,
            'status' => $investitureRequest->status,
            'carpeta_year' => $investitureRequest->carpeta_year,
            'club_type' => $investitureRequest->club_type,
            'director_notes' => $investitureRequest->director_notes,
            'tentative_investiture_date' => optional($investitureRequest->tentative_investiture_date)->toDateString(),
            'approved_investiture_date' => optional($investitureRequest->approved_investiture_date)->toDateString(),
            'submitted_at' => optional($investitureRequest->submitted_at)->toDateTimeString(),
            'assigned_at' => optional($investitureRequest->assigned_at)->toDateTimeString(),
            'completed_at' => optional($investitureRequest->completed_at)->toDateTimeString(),
            'authorized_at' => optional($investitureRequest->authorized_at)->toDateTimeString(),
            'authorization_person_name' => $investitureRequest->authorization_person_name,
            'ceremony_representative_name' => $investitureRequest->ceremony_representative_name,
            'ceremony_representative_email' => $investitureRequest->ceremony_representative_email,
            'ceremony_representative_phone' => $investitureRequest->ceremony_representative_phone,
            'date_change_reason' => $investitureRequest->date_change_reason,
            'date_change_requested_at' => optional($investitureRequest->date_change_requested_at)->toDateTimeString(),
            'assigned_evaluator_type' => $investitureRequest->assigned_evaluator_type,
            'assigned_evaluator_name' => $investitureRequest->assigned_evaluator_name,
            'assigned_evaluator_email' => $investitureRequest->assigned_evaluator_email,
            'members_count' => $members->count(),
            'requirements_count' => $requirementsCount,
            'completed_requirements_count' => $completedRequirementsCount,
            'club' => $investitureRequest->club ? [
                'id' => $investitureRequest->club->id,
                'club_name' => $investitureRequest->club->club_name,
                'church_name' => $investitureRequest->club->church_name,
            ] : null,
            'district' => $investitureRequest->district ? [
                'id' => $investitureRequest->district->id,
                'name' => $investitureRequest->district->name,
                'pastor_name' => $investitureRequest->district->pastor_name,
                'pastor_email' => $investitureRequest->district->pastor_email,
                'is_evaluator' => (bool) $investitureRequest->district->is_evaluator,
            ] : null,
        ];
    }

    protected function updateTentativeDateForClubRequest(Request $request, InvestitureRequest $investitureRequest, $club, string $date)
    {
        abort_if((int) $investitureRequest->club_id !== (int) $club->id, 403);
        abort_unless(in_array($investitureRequest->status, [
            InvestitureRequest::STATUS_SUBMITTED,
            InvestitureRequest::STATUS_ASSIGNED,
            InvestitureRequest::STATUS_IN_REVIEW,
            InvestitureRequest::STATUS_COMPLETED,
            InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
            InvestitureRequest::STATUS_RETURNED,
        ], true), 422, 'Esta solicitud no permite editar la fecha tentativa.');

        $nextStatus = $investitureRequest->status === InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED
            ? InvestitureRequest::STATUS_COMPLETED
            : $investitureRequest->status;

        $investitureRequest->forceFill([
            'status' => $nextStatus,
            'tentative_investiture_date' => $date,
            'date_change_reason' => null,
            'date_change_requested_at' => null,
            'date_change_requested_by' => null,
        ])->save();

        return back()->with('success', 'Nueva fecha propuesta enviada a la asociación.');
    }

    protected function serializeEvaluationRequest(InvestitureRequest $investitureRequest): array
    {
        return [
            ...$this->serializeRequest($investitureRequest),
            'members' => $investitureRequest->members->map(function ($member) {
                return [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'member_name' => $member->member_name,
                    'class_name' => $member->class_name,
                    'status' => $member->status,
                    'requirements_count' => $member->requirements_count,
                    'completed_requirements_count' => $member->completed_requirements_count,
                    'evaluator_notes' => $member->evaluator_notes,
                    'reviewed_at' => optional($member->reviewed_at)->toDateTimeString(),
                    'requirements' => $member->requirementReviews
                        ->sortBy(fn ($review) => $review->requirement?->sort_order ?? $review->id)
                        ->values()
                        ->map(fn ($review) => [
                            'review_id' => $review->id,
                            'status' => $review->status,
                            'notes' => $review->notes,
                            'reviewed_at' => optional($review->reviewed_at)->toDateTimeString(),
                            'requirement' => $review->requirement ? [
                                'id' => $review->requirement->id,
                                'title' => $review->requirement->title,
                                'description' => $review->requirement->description,
                                'requirement_type' => $review->requirement->requirement_type,
                                'validation_mode' => $review->requirement->validation_mode,
                                'allowed_evidence_types' => $review->requirement->allowed_evidence_types ?: [],
                                'evidence_instructions' => $review->requirement->evidence_instructions,
                                'sort_order' => $review->requirement->sort_order,
                            ] : null,
                            'evidence' => $review->evidence ? [
                                'id' => $review->evidence->id,
                                'evidence_type' => $review->evidence->evidence_type,
                                'text_value' => $review->evidence->text_value,
                                'file_path' => $review->evidence->file_path,
                                'file_url' => $review->evidence->file_path ? url('/storage/' . ltrim($review->evidence->file_path, '/')) : null,
                                'is_image' => $this->isImageEvidence($review->evidence),
                                'physical_completed' => (bool) $review->evidence->physical_completed,
                                'submitted_at' => optional($review->evidence->submitted_at)->toDateTimeString(),
                            ] : null,
                        ]),
                ];
            }),
        ];
    }

    protected function resolveScopedAssociation(Request $request): Association
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();
            abort_unless(($context['role'] ?? null) === 'association_youth_director' && !empty($context['association_id']), 403);

            return Association::query()->findOrFail((int) $context['association_id']);
        }

        abort_unless($user->profile_type === 'association_youth_director' && $user->scope_type === 'association' && !empty($user->scope_id), 403);

        return Association::query()->findOrFail((int) $user->scope_id);
    }

    protected function resolveScopedDistrict(Request $request): District
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();
            abort_unless(in_array(($context['role'] ?? null), ['district_pastor', 'district_secretary'], true) && !empty($context['district_id']), 403);

            return District::query()->findOrFail((int) $context['district_id']);
        }

        abort_unless(in_array($user->profile_type, ['district_pastor', 'district_secretary'], true) && $user->scope_type === 'district' && !empty($user->scope_id), 403);

        return District::query()->findOrFail((int) $user->scope_id);
    }

    protected function assertDistrictCanEvaluate(District $district, InvestitureRequest $investitureRequest): void
    {
        abort_unless(
            (int) $investitureRequest->district_id === (int) $district->id
            && $investitureRequest->assigned_evaluator_type === 'district_pastor'
            && (int) $investitureRequest->assigned_evaluator_id === (int) $district->id
            && in_array($investitureRequest->status, [
                InvestitureRequest::STATUS_ASSIGNED,
                InvestitureRequest::STATUS_IN_REVIEW,
                InvestitureRequest::STATUS_RETURNED,
                InvestitureRequest::STATUS_COMPLETED,
                InvestitureRequest::STATUS_AUTHORIZED,
                InvestitureRequest::STATUS_DATE_CHANGE_REQUESTED,
            ], true),
            403
        );
    }

    protected function isImageEvidence(ParentCarpetaRequirementEvidence $evidence): bool
    {
        if ($evidence->evidence_type === 'photo') {
            return true;
        }

        $extension = mb_strtolower(pathinfo((string) $evidence->file_path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}
