<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Association;
use App\Models\Club;
use App\Models\Account;
use App\Models\Church;
use App\Models\District;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\Member;
use App\Models\ClubClass;
use App\Models\User;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\Payment;
use App\Models\Union;
use App\Services\ClubLogoService;
use App\Services\EventFinanceService;
use App\Services\EventTaskAssignmentService;
use App\Services\EventTaskTemplateService;
use App\Services\SerpApiUsageService;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Event::class);

        $user = $request->user();
        $query = $this->accessibleEventsQuery($user);

        if ($request->filled('status')) {
            $status = (string) $request->string('status');
            $now = now();

            if ($status === Event::STATUS_ONGOING) {
                $query->where('start_at', '<=', $now)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('end_at')
                            ->orWhere('end_at', '>', $now);
                    });
            } elseif ($status === Event::STATUS_PAST) {
                $query->whereNotNull('end_at')
                    ->where('end_at', '<=', $now);
            } elseif (in_array($status, Event::editableStatuses(), true)) {
                $query->where('status', $status)
                    ->where('start_at', '>', $now);
            }
        }
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->string('event_type'));
        }
        if ($request->filled('start_from')) {
            $query->whereDate('start_at', '>=', $request->date('start_from'));
        }
        if ($request->filled('start_to')) {
            $query->whereDate('start_at', '<=', $request->date('start_to'));
        }

        $events = $query->orderBy('start_at', 'desc')->paginate(15)->withQueryString();

        $events->through(function (Event $event) {
            return [
                ...$event->toArray(),
                'scope_label' => $this->scopeLabel((string) ($event->scope_type ?: 'club'), (int) ($event->scope_id ?: $event->club_id)),
                'target_clubs' => $event->targetClubs->map(fn (Club $club) => [
                    'id' => $club->id,
                    'club_name' => $club->club_name,
                    'church_name' => $club->church_name,
                ])->values()->all(),
            ];
        });

        return Inertia::render('EventPlanner/Index', [
            'events' => $events,
            'filters' => $request->only(['status', 'event_type', 'start_from', 'start_to']),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', Event::class);

        $context = $this->plannerContext($request->user());

        return Inertia::render('EventPlanner/Create', [
            'scopeOptions' => $context['scopeOptions'],
            'selectedScopeType' => $context['selectedScopeType'],
            'selectedScopeId' => $context['selectedScopeId'],
            'lockScopeSelection' => $context['lockScopeSelection'],
            'targetClubOptions' => $context['targetClubOptions'],
            'clubTypeOptions' => $context['clubTypeOptions'],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Event::class);

        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(['club', 'church', 'district', 'association', 'union'])],
            'scope_id' => ['required', 'integer'],
            'involved_club_ids' => ['nullable', 'array'],
            'involved_club_ids.*' => ['integer', 'exists:clubs,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Event::editableStatuses())],
            'target_club_types' => ['nullable', 'array'],
            'target_club_types.*' => ['string', Rule::in($this->allowedEventClubTypesForUser($request->user()))],
            'budget_estimated_total' => ['nullable', 'numeric'],
            'budget_actual_total' => ['nullable', 'numeric'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'],
            'fee_components' => ['nullable', 'array'],
            'fee_components.*.label' => ['required_with:fee_components', 'string', 'max:255'],
            'fee_components.*.amount' => ['required_with:fee_components', 'numeric', 'min:0.01'],
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['scope_type'] ?? 'club') !== 'club' && empty($validated['target_club_types'])) {
            return back()
                ->withErrors([
                    'target_club_types' => 'Selecciona al menos un tipo de club para eventos por encima del club.',
                ])
                ->withInput();
        }

        [$anchorClubId, $targetClubIds] = $this->resolveScopeSelection(
            $request->user(),
            (string) $validated['scope_type'],
            (int) $validated['scope_id'],
            array_map('intval', $validated['involved_club_ids'] ?? [])
        );

        $targetClubIds = $this->filterTargetClubIdsByClubTypes(
            $request->user(),
            (string) $validated['scope_type'],
            (int) $validated['scope_id'],
            $targetClubIds,
            $validated['target_club_types'] ?? []
        );

        abort_if(empty($targetClubIds), 422, 'No hay clubes compatibles con los tipos seleccionados.');

        $event = Event::create([
            'club_id' => $anchorClubId,
            'scope_type' => $validated['scope_type'],
            'scope_id' => (int) $validated['scope_id'],
            'target_club_types' => !empty($validated['target_club_types']) ? array_values($validated['target_club_types']) : null,
            'created_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_type' => $validated['event_type'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'timezone' => $validated['timezone'] ?? 'America/New_York',
            'location_name' => $validated['location_name'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'status' => $validated['status'] ?? Event::STATUS_DRAFT,
            'budget_estimated_total' => $validated['budget_estimated_total'] ?? null,
            'budget_actual_total' => $validated['budget_actual_total'] ?? null,
            'requires_approval' => $validated['requires_approval'] ?? false,
            'is_mandatory' => (bool) ($validated['is_mandatory'] ?? false),
            'is_payable' => false,
            'payment_amount' => null,
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

        $event->targetClubs()->sync($targetClubIds);

        EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);

        try {
            $seededTasks = app(EventTaskTemplateService::class)->seedEventTasks($event);
        } catch (\Throwable $e) {
            Log::warning('Event task seeding failed; continuing without seeded tasks.', [
                'event_id' => $event->id,
                'club_id' => $event->club_id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);
            report($e);
            $seededTasks = [];
        }

        if (!empty($seededTasks)) {
            $event->plan()->update([
                'missing_items_json' => collect($seededTasks)->map(fn ($task) => $task->title)->values()->all(),
            ]);
        }

        $finance = app(EventFinanceService::class);
        $finance->syncFeeComponents($event, $validated['fee_components'] ?? []);
        $finance->syncPaymentConcepts($event->fresh(), $request->user()->id);

        return redirect()->route('events.show', $event);
    }

    public function show(Event $event)
    {
        $this->authorize('view', $event);

        app(EventTaskTemplateService::class)->reseedEventTasksIfSafe($event);
        $event->refresh();
        $event->load([
            'plan',
            'tasks.formResponse',
            'tasks.assignments.formResponse',
            'budgetItems.expense',
            'budgetItems.reimbursementExpense',
            'participants.member:id,type,id_data,club_id,class_id,parent_id',
            'documents',
            'placeOptions',
            'targetClubs:id,club_name,church_name,district_id,club_type',
            'targetClubs.district:id,name,association_id',
            'feeComponents',
        ]);
        $visibleClubIds = $this->visibleEventClubIdsForUser(auth()->user(), $event);
        $clubId = $visibleClubIds[0] ?? $event->club_id;
        $supportsClubOperations = ($event->scope_type ?: 'club') === 'club';
        $hideOtherClubParticipants = !empty($visibleClubIds)
            && count($visibleClubIds) < max($event->targetClubs->count(), 1);
        $visibleParticipants = $event->participants->filter(function ($participant) use ($supportsClubOperations, $visibleClubIds, $hideOtherClubParticipants) {
            if ($supportsClubOperations) {
                return true;
            }

            if (!$hideOtherClubParticipants) {
                return true;
            }

            if (!$participant->member_id || !$participant->member?->club_id) {
                return false;
            }

            return in_array((int) $participant->member->club_id, $visibleClubIds, true);
        })->values();

        $members = collect();
        $classes = collect();
        $staff = collect();
        $accounts = collect();
        $parents = collect();

        $paymentSummary = [
            'total_received' => 0.0,
            'by_member_id' => [],
            'by_staff_id' => [],
        ];
        $paymentRecords = [];
        $paymentConceptLabel = null;
        if ($supportsClubOperations) {
            $members = ClubHelper::membersOfClub($clubId);
            $classes = ClubClass::where('club_id', $clubId)->orderBy('class_name')->get(['id', 'class_name']);
            $staff = ClubHelper::staffOfClub($clubId)
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'name' => $row->user?->name ?? '—',
                        'email' => $row->user?->email,
                        'assigned_class' => $row->assigned_class,
                        'type' => $row->type,
                        'status' => $row->status,
                        'classes' => $row->classes?->map(fn ($c) => ['id' => $c->id, 'class_name' => $c->class_name])->values(),
                    ];
                })
                ->values();

            $accounts = Account::query()
                ->where('club_id', $clubId)
                ->orderBy('label')
                ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);

            if ($accounts->isEmpty()) {
                $accounts = collect([
                    Account::create([
                        'club_id' => $clubId,
                        'pay_to' => 'club_budget',
                        'label' => 'Club budget',
                        'balance' => 0,
                    ]),
                ]);
            }

            $accounts = $accounts
                ->map(function (Account $account) {
                    return [
                        'id' => $account->id,
                        'pay_to' => $account->pay_to,
                        'value' => $account->pay_to,
                        'label' => $account->label,
                        'balance' => (float) $account->balance,
                    ];
                })
                ->values();

            $parentIdsWithKids = Member::where('club_id', $clubId)
                ->whereNotNull('parent_id')
                ->pluck('parent_id')
                ->unique()
                ->all();

            $parents = User::where('profile_type', 'parent')
                ->whereIn('id', $parentIdsWithKids)
                ->get(['id', 'name', 'email'])
                ->map(function ($parent) use ($clubId) {
                    $children = Member::where('club_id', $clubId)
                        ->where('parent_id', $parent->id)
                        ->get()
                        ->map(function ($member) {
                            $detail = ClubHelper::memberDetail($member);
                            return [
                                'id' => $member->id,
                                'name' => $detail['name'] ?? null,
                                'class_id' => $member->class_id,
                            ];
                        })
                        ->values();

                    return [
                        'id' => $parent->id,
                        'name' => $parent->name,
                        'email' => $parent->email,
                        'children' => $children,
                    ];
                })
                ->values();
        }

        if (!$supportsClubOperations && !empty($visibleClubIds)) {
            $members = Member::query()
                ->whereIn('club_id', $visibleClubIds)
                ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
                ->where('status', '!=', 'deleted')
                ->with(['club:id,club_name', 'class:id,class_name'])
                ->get(['id', 'type', 'id_data', 'club_id', 'class_id', 'parent_id'])
                ->map(function (Member $member) {
                    $detail = ClubHelper::memberDetail($member);

                    return [
                        'member_id' => $member->id,
                        'applicant_name' => $detail['name'] ?? '—',
                        'club_id' => $member->club_id,
                        'club_name' => $member->club?->club_name,
                        'class_id' => $member->class_id,
                        'member_type' => $member->type,
                        'parent_id' => $member->parent_id,
                    ];
                })
                ->values();

            $classes = ClubClass::query()
                ->whereIn('club_id', $visibleClubIds)
                ->orderBy('class_name')
                ->get(['id', 'club_id', 'class_name'])
                ->map(fn (ClubClass $class) => [
                    'id' => $class->id,
                    'club_id' => $class->club_id,
                    'class_name' => $class->class_name,
                ])
                ->values();
        }

        $financeSummary = app(EventFinanceService::class)->paymentSummary($event, $visibleClubIds);
        $clubSignupSummary = collect(app(EventFinanceService::class)->clubSignupSummary($event))
            ->when(!empty($visibleClubIds), fn ($rows) => $rows->whereIn('club_id', $visibleClubIds))
            ->values();
        $paymentSummary = [
            'total_received' => $financeSummary['total_received'],
            'by_member_id' => $financeSummary['by_member_id'],
            'by_staff_id' => $financeSummary['by_staff_id'],
        ];
        $paymentRecords = $financeSummary['records'];
        $paymentConceptLabel = collect($financeSummary['concepts'])->pluck('label')->first();
        $canEditEvent = auth()->user()?->can('update', $event) ?? false;
        $manageableSettlementClubIds = $this->manageableSettlementClubIdsForUser(auth()->user(), $event);
        $taskAssignmentService = app(EventTaskAssignmentService::class);

        $serpApiUsage = app(SerpApiUsageService::class)->currentMonthSummary();

        return Inertia::render('EventPlanner/Show', [
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $taskAssignmentService->serializeTasksForUser($event, auth()->user()),
            'budgetItems' => $event->budgetItems,
            'participants' => $visibleParticipants,
            'documents' => $event->documents,
            'placeOptions' => $event->placeOptions,
            'supportsClubOperations' => $supportsClubOperations,
            'feeComponents' => $event->feeComponents->map(fn ($component) => [
                'id' => $component->id,
                'label' => $component->label,
                'amount' => (float) $component->amount,
                'sort_order' => (int) $component->sort_order,
            ])->values(),
            'scopeLabel' => $this->scopeLabel((string) ($event->scope_type ?: 'club'), (int) ($event->scope_id ?: $event->club_id)),
            'targetClubs' => $event->targetClubs
                ->filter(fn (Club $club) => empty($visibleClubIds) || in_array((int) $club->id, $visibleClubIds, true))
                ->map(fn (Club $club) => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'church_name' => $club->church_name,
                'district_id' => $club->district_id,
                'district_name' => $club->district?->name,
                'club_type' => $club->club_type,
                'signup_status' => $club->pivot?->signup_status ?: 'targeted',
                'signed_up_at' => optional($club->pivot?->signed_up_at)->toDateTimeString(),
            ])->values(),
            'clubSignupSummary' => $clubSignupSummary,
            'members' => $members,
            'classes' => $classes,
            'staff' => $staff,
            'accounts' => $accounts,
            'parents' => $parents,
            'paymentSummary' => $paymentSummary,
            'paymentConfig' => [
                'concept_id' => $event->payment_concept_id,
                'concept_label' => $paymentConceptLabel,
                'amount' => $event->payment_amount,
                'is_payable' => $event->is_payable,
                'concepts' => $financeSummary['concepts'],
                'total_amount' => (float) ($event->payment_amount ?? 0),
            ],
            'canEditEvent' => $canEditEvent,
            'taskResponsibilityOptions' => ($event->scope_type ?: 'club') === 'club'
                ? []
                : $taskAssignmentService->responsibilityOptions($event),
            'manageableSettlementClubIds' => $manageableSettlementClubIds,
            'paymentRecords' => $paymentRecords,
            'serpApiUsage' => $serpApiUsage,
        ]);
    }

    public function edit(Event $event)
    {
        $this->authorize('update', $event);

        return Inertia::render('EventPlanner/Edit', [
            'event' => $event,
        ]);
    }

    public function pdf(Event $event, ClubLogoService $clubLogoService)
    {
        $this->authorize('view', $event);

        $event->load([
            'club',
            'plan',
            'drivers.participant',
            'drivers.vehicles',
            'documents',
        ]);

        $planJson = $event->plan?->plan_json ?? ['sections' => []];
        $sections = collect($planJson['sections'] ?? [])
            ->filter(function ($section) {
                return ($section['name'] ?? '') !== 'Recommendations';
            })
            ->values()
            ->all();

        $transportMode = $planJson['transportation_mode'] ?? null;

        $documents = collect($event->documents ?? []);
        $drivers = collect($event->drivers ?? [])->map(function ($driver) use ($documents, $transportMode) {
            $participantId = $driver->participant_id;
            $licenseDoc = $documents->first(function ($doc) use ($participantId) {
                $docType = strtolower((string) ($doc->doc_type ?? $doc->type ?? ''));
                return (int) $doc->driver_participant_id === (int) $participantId
                    && str_contains($docType, 'license');
            });

            $vehicleRows = collect($driver->vehicles ?? [])->map(function ($vehicle) use ($documents) {
                $insuranceDoc = $documents->first(function ($doc) use ($vehicle) {
                    $docType = strtolower((string) ($doc->doc_type ?? $doc->type ?? ''));
                    return (int) $doc->vehicle_id === (int) $vehicle->id
                        && (str_contains($docType, 'insurance') || str_contains($docType, 'rental'));
                });

                return [
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year,
                    'plate' => $vehicle->plate,
                    'vin' => $vehicle->vin,
                    'insurance_doc_title' => $insuranceDoc?->title,
                    'insurance_doc_path' => $insuranceDoc?->path,
                ];
            })->values()->all();

            return [
                'name' => $driver->participant?->participant_name ?? 'Driver',
                'license_number' => $driver->license_number,
                'license_doc_title' => $licenseDoc?->title,
                'license_doc_path' => $licenseDoc?->path,
                'vehicles' => $vehicleRows,
                'private_mode' => $transportMode === 'private',
            ];
        })->values()->all();

        $pdf = Pdf::loadView('pdf.event_planner', [
            'event' => $event,
            'sections' => $sections,
            'drivers' => $drivers,
            'transport_mode' => $transportMode,
            'clubLogoDataUri' => $clubLogoService->dataUri($event->club),
        ]);

        return $pdf->download('event-plan-' . $event->id . '.pdf');
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Event::editableStatuses())],
            'target_club_types' => ['nullable', 'array'],
            'target_club_types.*' => ['string', Rule::in($this->allowedEventClubTypesForUser($request->user()))],
            'budget_estimated_total' => ['nullable', 'numeric'],
            'budget_actual_total' => ['nullable', 'numeric'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'],
            'fee_components' => ['nullable', 'array'],
            'fee_components.*.label' => ['required_with:fee_components', 'string', 'max:255'],
            'fee_components.*.amount' => ['required_with:fee_components', 'numeric', 'min:0.01'],
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

        $event->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_type' => $validated['event_type'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'timezone' => $validated['timezone'] ?? $event->timezone,
            'location_name' => $validated['location_name'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'status' => $validated['status'] ?? $event->status,
            'target_club_types' => !empty($validated['target_club_types']) ? array_values($validated['target_club_types']) : $event->target_club_types,
            'budget_estimated_total' => $validated['budget_estimated_total'] ?? null,
            'budget_actual_total' => $validated['budget_actual_total'] ?? null,
            'requires_approval' => $validated['requires_approval'] ?? false,
            'is_mandatory' => (bool) ($validated['is_mandatory'] ?? $event->is_mandatory),
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

        if ($request->has('fee_components')) {
            $finance = app(EventFinanceService::class);
            $finance->syncFeeComponents($event, $validated['fee_components'] ?? []);
            $finance->syncPaymentConcepts($event->fresh(), $request->user()->id);
        }

        return redirect()->route('events.show', $event);
    }

    public function destroy(Event $event)
    {
        $this->authorize('delete', $event);

        $event->delete();

        return redirect()->route('events.index');
    }

    public function updateClubSignup(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'signup_status' => ['required', Rule::in(['targeted', 'signed_up', 'declined'])],
            'signup_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $visibleClubIds = $this->visibleEventClubIdsForUser($request->user(), $event);
        abort_unless(in_array((int) $validated['club_id'], $visibleClubIds, true), 403);
        abort_unless($event->targetClubs()->where('clubs.id', (int) $validated['club_id'])->exists(), 422);

        $event->targetClubs()->updateExistingPivot((int) $validated['club_id'], [
            'signup_status' => $validated['signup_status'],
            'signed_up_at' => $validated['signup_status'] === 'signed_up' ? now() : null,
            'signup_notes' => $validated['signup_notes'] ?? null,
        ]);

        return back()->with('success', 'Estado de inscripción actualizado.');
    }

    protected function userClubIds($user): array
    {
        return ClubHelper::clubIdsForUser($user)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    protected function syncPaymentConcept(Event $event, bool $isPayable, ?float $amount, int $userId): void
    {
        if (!$isPayable) {
            if ($event->payment_concept_id) {
                $concept = PaymentConcept::with('scopes')->find($event->payment_concept_id);
                if ($concept) {
                    DB::transaction(function () use ($concept) {
                        $concept->scopes()->delete();
                        $concept->update(['status' => 'inactive']);
                        $concept->delete();
                    });
                }
            }

            $event->update([
                'payment_concept_id' => null,
                'payment_amount' => null,
            ]);

            return;
        }

        if (!$amount || $amount <= 0) {
            return;
        }

        $conceptLabel = 'Event Fee: ' . $event->title;

        if ($event->payment_concept_id) {
            $concept = PaymentConcept::find($event->payment_concept_id);
            if ($concept) {
                $concept->update([
                    'concept' => $conceptLabel,
                    'amount' => $amount,
                    'status' => 'active',
                    'club_id' => $event->club_id,
                ]);

                return;
            }
        }

        DB::transaction(function () use ($event, $amount, $conceptLabel, $userId) {
            $concept = PaymentConcept::create([
                'concept' => $conceptLabel,
                'amount' => $amount,
                'type' => 'mandatory',
                'pay_to' => 'club_budget',
                'created_by' => $userId,
                'status' => 'active',
                'club_id' => $event->club_id,
            ]);

            PaymentConceptScope::create([
                'payment_concept_id' => $concept->id,
                'scope_type' => 'club_wide',
                'club_id' => $event->club_id,
            ]);

            $event->update([
                'payment_concept_id' => $concept->id,
            ]);
        });
    }

    protected function assertUserHasClub($user, int $clubId): void
    {
        if (!in_array($clubId, $this->userClubIds($user), true)) {
            abort(403, 'Access denied.');
        }
    }

    protected function accessibleEventsQuery($user)
    {
        $query = Event::query()->with(['plan', 'targetClubs:id,club_name,church_name']);
        $role = $this->plannerRoleKey($user);

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return $query;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $clubIds = $this->userClubIdsForHierarchy($user);

            return $query->where(function ($builder) use ($clubIds) {
                $builder->where(function ($directClubEvents) use ($clubIds) {
                    $directClubEvents->where('scope_type', 'club')
                        ->whereIn('scope_id', $clubIds);
                })->orWhereHas('targetClubs', fn ($targeted) => $targeted->whereIn('clubs.id', $clubIds));
            });
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            $districtId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['district_id'] ?? 0)
                : (int) $user->scope_id;
            $churchIds = Church::query()->where('district_id', $districtId)->pluck('id')->all();
            $clubIds = Club::query()->where('district_id', $districtId)->pluck('id')->all();

            return $query->where(function ($builder) use ($districtId, $churchIds, $clubIds) {
                $builder->where(fn ($q) => $q->where('scope_type', 'district')->where('scope_id', $districtId));
                if (!empty($churchIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'church')->whereIn('scope_id', $churchIds));
                }
                if (!empty($clubIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'club')->whereIn('scope_id', $clubIds));
                    $builder->orWhereHas('targetClubs', fn ($targeted) => $targeted->whereIn('clubs.id', $clubIds));
                }
            });
        }

        if ($role === 'association_youth_director') {
            $associationId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['association_id'] ?? 0)
                : (int) $user->scope_id;
            $districtIds = District::query()->where('association_id', $associationId)->pluck('id')->all();
            $churchIds = Church::query()->whereIn('district_id', $districtIds)->pluck('id')->all();
            $clubIds = Club::query()->whereIn('district_id', $districtIds)->pluck('id')->all();

            return $query->where(function ($builder) use ($associationId, $districtIds, $churchIds, $clubIds) {
                $builder->where(fn ($q) => $q->where('scope_type', 'association')->where('scope_id', $associationId));
                if (!empty($districtIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'district')->whereIn('scope_id', $districtIds));
                }
                if (!empty($churchIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'church')->whereIn('scope_id', $churchIds));
                }
                if (!empty($clubIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'club')->whereIn('scope_id', $clubIds));
                    $builder->orWhereHas('targetClubs', fn ($targeted) => $targeted->whereIn('clubs.id', $clubIds));
                }
            });
        }

        if ($role === 'union_youth_director') {
            $unionId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['union_id'] ?? 0)
                : (int) $user->scope_id;
            $associationIds = Association::query()->where('union_id', $unionId)->pluck('id')->all();
            $districtIds = District::query()->whereIn('association_id', $associationIds)->pluck('id')->all();
            $churchIds = Church::query()->whereIn('district_id', $districtIds)->pluck('id')->all();
            $clubIds = Club::query()->whereIn('district_id', $districtIds)->pluck('id')->all();

            return $query->where(function ($builder) use ($unionId, $associationIds, $districtIds, $churchIds, $clubIds) {
                $builder->where(fn ($q) => $q->where('scope_type', 'union')->where('scope_id', $unionId));
                if (!empty($associationIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'association')->whereIn('scope_id', $associationIds));
                }
                if (!empty($districtIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'district')->whereIn('scope_id', $districtIds));
                }
                if (!empty($churchIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'church')->whereIn('scope_id', $churchIds));
                }
                if (!empty($clubIds)) {
                    $builder->orWhere(fn ($q) => $q->where('scope_type', 'club')->whereIn('scope_id', $clubIds));
                }
            });
        }

        return $query->whereRaw('1 = 0');
    }

    protected function plannerContext($user): array
    {
        $role = $this->plannerRoleKey($user);
        $targetClubOptions = $this->targetClubOptionsForUser($user);
        $scopeOptions = [];
        $selectedScopeType = 'club';
        $selectedScopeId = null;
        $lockScopeSelection = false;
        $clubTypeOptions = $this->eventClubTypeOptions($user);

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            $scopeOptions['union'] = Union::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($union) => ['id' => $union->id, 'label' => $union->name])
                ->values()
                ->all();
            $scopeOptions['association'] = Association::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($association) => ['id' => $association->id, 'label' => $association->name])
                ->values()
                ->all();
            $scopeOptions['district'] = District::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($district) => ['id' => $district->id, 'label' => $district->name])
                ->values()
                ->all();
            $scopeOptions['church'] = Church::query()
                ->orderBy('church_name')
                ->get(['id', 'church_name'])
                ->map(fn ($church) => ['id' => $church->id, 'label' => $church->church_name])
                ->values()
                ->all();
            $scopeOptions['club'] = collect($targetClubOptions)
                ->map(fn ($club) => ['id' => $club['id'], 'label' => $club['club_name']])
                ->values()
                ->all();

            $selectedScopeType = !empty($scopeOptions['union'])
                ? 'union'
                : (!empty($scopeOptions['association'])
                    ? 'association'
                    : (!empty($scopeOptions['district'])
                        ? 'district'
                        : (!empty($scopeOptions['church']) ? 'church' : 'club')));

            $selectedScopeId = $scopeOptions[$selectedScopeType][0]['id'] ?? null;
            $lockScopeSelection = false;
        } elseif (in_array($role, ['club_director', 'club_personal'], true)) {
            $scopeOptions['club'] = collect($targetClubOptions)
                ->map(fn ($club) => ['id' => $club['id'], 'label' => $club['club_name']])
                ->values()
                ->all();
            $selectedScopeId = (($user->profile_type ?? null) === 'superadmin')
                ? ($this->superadminPlannerContext()['club_id'] ?? null)
                : (ClubHelper::activeClubForUser($user)?->id ?: ($scopeOptions['club'][0]['id'] ?? null));
            $lockScopeSelection = count($scopeOptions['club']) <= 1;
        } elseif (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            $districtId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['district_id'] ?? 0)
                : (int) $user->scope_id;
            $district = District::query()->findOrFail($districtId, ['id', 'name']);
            $scopeOptions['district'] = [['id' => $district->id, 'label' => $district->name]];
            $scopeOptions['church'] = Church::query()
                ->where('district_id', $district->id)
                ->orderBy('church_name')
                ->get(['id', 'church_name'])
                ->map(fn ($church) => ['id' => $church->id, 'label' => $church->church_name])
                ->values()
                ->all();
            $selectedScopeType = 'district';
            $selectedScopeId = $district->id;
        } elseif ($role === 'association_youth_director') {
            $associationId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['association_id'] ?? 0)
                : (int) $user->scope_id;
            $association = Association::query()->findOrFail($associationId, ['id', 'name']);
            $districts = District::query()->where('association_id', $association->id)->orderBy('name')->get(['id', 'name']);
            $scopeOptions['association'] = [['id' => $association->id, 'label' => $association->name]];
            $scopeOptions['district'] = $districts->map(fn ($district) => ['id' => $district->id, 'label' => $district->name])->values()->all();
            $scopeOptions['church'] = Church::query()
                ->whereIn('district_id', $districts->pluck('id'))
                ->orderBy('church_name')
                ->get(['id', 'church_name'])
                ->map(fn ($church) => ['id' => $church->id, 'label' => $church->church_name])
                ->values()
                ->all();
            $selectedScopeType = 'association';
            $selectedScopeId = $association->id;
        } elseif ($role === 'union_youth_director') {
            $unionId = ($user->profile_type ?? null) === 'superadmin'
                ? (int) ($this->superadminPlannerContext()['union_id'] ?? 0)
                : (int) $user->scope_id;
            $union = Union::query()->findOrFail($unionId, ['id', 'name']);
            $associations = Association::query()->where('union_id', $union->id)->orderBy('name')->get(['id', 'name']);
            $districts = District::query()->whereIn('association_id', $associations->pluck('id'))->orderBy('name')->get(['id', 'name']);
            $scopeOptions['union'] = [['id' => $union->id, 'label' => $union->name]];
            $scopeOptions['association'] = $associations->map(fn ($association) => ['id' => $association->id, 'label' => $association->name])->values()->all();
            $scopeOptions['district'] = $districts->map(fn ($district) => ['id' => $district->id, 'label' => $district->name])->values()->all();
            $scopeOptions['church'] = Church::query()
                ->whereIn('district_id', $districts->pluck('id'))
                ->orderBy('church_name')
                ->get(['id', 'church_name'])
                ->map(fn ($church) => ['id' => $church->id, 'label' => $church->church_name])
                ->values()
                ->all();
            $selectedScopeType = 'union';
            $selectedScopeId = $union->id;
        }

        return [
            'scopeOptions' => $scopeOptions,
            'selectedScopeType' => $selectedScopeType,
            'selectedScopeId' => $selectedScopeId,
            'lockScopeSelection' => $lockScopeSelection,
            'targetClubOptions' => $targetClubOptions,
            'clubTypeOptions' => $clubTypeOptions,
        ];
    }

    protected function targetClubOptionsForUser($user): array
    {
        $clubs = Club::query()
            ->whereIn('id', $this->userClubIdsForHierarchy($user))
            ->with('district.association')
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'church_id', 'church_name', 'district_id', 'club_type']);

        return $clubs->map(function (Club $club) {
            return [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'church_id' => $club->church_id,
                'church_name' => $club->church_name,
                'district_id' => $club->district_id,
                'district_name' => $club->district?->name,
                'association_id' => $club->district?->association?->id,
                'association_name' => $club->district?->association?->name,
                'union_id' => $club->district?->association?->union_id,
            ];
        })->values()->all();
    }

    protected function userClubIdsForHierarchy($user): array
    {
        if (($user->profile_type ?? null) === 'superadmin') {
            $context = $this->superadminPlannerContext();
            $role = $context['role'] ?? 'superadmin';

            if ($role === 'club_director' && !empty($context['club_id'])) {
                return [(int) $context['club_id']];
            }

            if (in_array($role, ['district_pastor', 'district_secretary'], true) && !empty($context['district_id'])) {
                return Club::query()
                    ->where('district_id', (int) $context['district_id'])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if ($role === 'association_youth_director' && !empty($context['association_id'])) {
                return Club::query()
                    ->whereHas('district', fn ($query) => $query->where('association_id', (int) $context['association_id']))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if ($role === 'union_youth_director' && !empty($context['union_id'])) {
                return Club::query()
                    ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) $context['union_id']))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            return Club::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $role = $this->plannerRoleKey($user);

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            return $this->userClubIds($user);
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return Club::query()
                ->where('district_id', (int) $user->scope_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($role === 'association_youth_director') {
            return Club::query()
                ->whereHas('district', fn ($query) => $query->where('association_id', (int) $user->scope_id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($role === 'union_youth_director') {
            return Club::query()
                ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) $user->scope_id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return [];
    }

    protected function resolveScopeSelection($user, string $scopeType, int $scopeId, array $selectedClubIds): array
    {
        if ($scopeType === 'club') {
            $this->assertUserHasClub($user, $scopeId);

            return [$scopeId, [$scopeId]];
        }

        $clubOptions = collect($this->targetClubOptionsForUser($user))->keyBy('id');

        $eligibleClubs = $clubOptions->filter(function (array $club) use ($scopeType, $scopeId) {
            return match ($scopeType) {
                'church' => (int) $club['church_id'] === $scopeId,
                'district' => (int) $club['district_id'] === $scopeId,
                'association' => (int) $club['association_id'] === $scopeId,
                'union' => (int) $club['union_id'] === $scopeId,
                default => false,
            };
        });

        abort_if($eligibleClubs->isEmpty(), 422, 'No hay clubes elegibles para el scope seleccionado.');

        $selected = collect($selectedClubIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        abort_if($selected->isEmpty(), 422, 'Selecciona al menos un club involucrado para este evento.');

        $invalid = $selected->first(fn ($clubId) => !$eligibleClubs->has($clubId));
        abort_if($invalid, 422, 'Hay clubes seleccionados que no pertenecen al scope elegido.');

        $this->assertAccessibleHierarchyScope($user, $scopeType, $scopeId);

        return [(int) $selected->first(), $selected->all()];
    }

    protected function assertAccessibleHierarchyScope($user, string $scopeType, int $scopeId): void
    {
        $role = ClubHelper::roleKey($user);

        if (($user->profile_type ?? null) === 'superadmin') {
            return;
        }

        $allowed = match ($scopeType) {
            'church' => match (true) {
                in_array($role, ['district_pastor', 'district_secretary'], true) => Church::query()->whereKey($scopeId)->where('district_id', (int) $user->scope_id)->exists(),
                $role === 'association_youth_director' => Church::query()->whereKey($scopeId)->whereHas('district', fn ($query) => $query->where('association_id', (int) $user->scope_id))->exists(),
                $role === 'union_youth_director' => Church::query()->whereKey($scopeId)->whereHas('district.association', fn ($query) => $query->where('union_id', (int) $user->scope_id))->exists(),
                default => false,
            },
            'district' => match (true) {
                in_array($role, ['district_pastor', 'district_secretary'], true) => (int) $user->scope_id === $scopeId,
                $role === 'association_youth_director' => District::query()->whereKey($scopeId)->where('association_id', (int) $user->scope_id)->exists(),
                $role === 'union_youth_director' => District::query()->whereKey($scopeId)->whereHas('association', fn ($query) => $query->where('union_id', (int) $user->scope_id))->exists(),
                default => false,
            },
            'association' => match (true) {
                $role === 'association_youth_director' => (int) $user->scope_id === $scopeId,
                $role === 'union_youth_director' => Association::query()->whereKey($scopeId)->where('union_id', (int) $user->scope_id)->exists(),
                default => false,
            },
            'union' => $role === 'union_youth_director' && (int) $user->scope_id === $scopeId,
            default => false,
        };

        abort_unless($allowed, 403, 'Access denied.');
    }

    protected function scopeLabel(string $scopeType, int $scopeId): string
    {
        return match ($scopeType) {
            'club' => 'Club: ' . (Club::query()->whereKey($scopeId)->value('club_name') ?: "#{$scopeId}"),
            'church' => 'Iglesia: ' . (Church::query()->whereKey($scopeId)->value('church_name') ?: "#{$scopeId}"),
            'district' => 'Distrito: ' . (District::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            'association' => 'Asociación: ' . (Association::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            'union' => 'Unión: ' . (Union::query()->whereKey($scopeId)->value('name') ?: "#{$scopeId}"),
            default => ucfirst($scopeType) . ": #{$scopeId}",
        };
    }

    protected function allowedEventClubTypesForUser($user): array
    {
        return collect($this->eventClubTypeOptions($user))
            ->pluck('value')
            ->filter()
            ->values()
            ->all();
    }

    protected function eventClubTypeOptions($user): array
    {
        $union = $this->unionForPlannerUser($user);
        if (!$union) {
            return [];
        }

        $union->loadMissing('clubCatalogs');

        return $union->clubCatalogs
            ->where('status', 'active')
            ->map(fn ($catalog) => [
                'value' => $catalog->club_type,
                'label' => $catalog->name ?: $catalog->club_type,
                'sort_order' => $catalog->sort_order,
            ])
            ->sortBy([
                ['sort_order', 'asc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    protected function unionForPlannerUser($user): ?Union
    {
        $role = $this->plannerRoleKey($user);

        if (($user->profile_type ?? null) === 'superadmin') {
            $context = $this->superadminPlannerContext();

            if ($role === 'club_director') {
                $clubId = (int) ($context['club_id'] ?? 0);
                return $clubId
                    ? Club::query()->whereKey($clubId)->with('district.association.union')->first()?->district?->association?->union
                    : null;
            }

            if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
                return !empty($context['district_id'])
                    ? District::query()->whereKey((int) $context['district_id'])->with('association.union')->first()?->association?->union
                    : null;
            }

            if ($role === 'association_youth_director') {
                return !empty($context['association_id'])
                    ? Association::query()->whereKey((int) $context['association_id'])->with('union')->first()?->union
                    : null;
            }

            if ($role === 'union_youth_director') {
                return !empty($context['union_id'])
                    ? Union::query()->find((int) $context['union_id'])
                    : null;
            }

            return null;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $clubId = $this->userClubIds($user)[0] ?? null;
            return $clubId
                ? Club::query()->whereKey($clubId)->with('district.association.union')->first()?->district?->association?->union
                : null;
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return District::query()->whereKey((int) $user->scope_id)->with('association.union')->first()?->association?->union;
        }

        if ($role === 'association_youth_director') {
            return Association::query()->whereKey((int) $user->scope_id)->with('union')->first()?->union;
        }

        if ($role === 'union_youth_director') {
            return Union::query()->find((int) $user->scope_id);
        }

        return null;
    }

    protected function plannerRoleKey($user): ?string
    {
        if (($user->profile_type ?? null) === 'superadmin') {
            return $this->superadminPlannerContext()['role'] ?? 'superadmin';
        }

        return ClubHelper::roleKey($user);
    }

    protected function superadminPlannerContext(): array
    {
        return SuperadminContext::fromSession();
    }

    protected function filterTargetClubIdsByClubTypes($user, string $scopeType, int $scopeId, array $targetClubIds, array $clubTypes): array
    {
        $selectedClubTypes = collect($clubTypes)->filter()->values();
        if ($scopeType === 'club' || $selectedClubTypes->isEmpty()) {
            return $targetClubIds;
        }

        $eligibleClubIds = collect($this->targetClubOptionsForUser($user))
            ->filter(function (array $club) use ($scopeType, $scopeId, $selectedClubTypes) {
                $matchesScope = match ($scopeType) {
                    'church' => (int) $club['church_id'] === $scopeId,
                    'district' => (int) $club['district_id'] === $scopeId,
                    'association' => (int) $club['association_id'] === $scopeId,
                    'union' => (int) $club['union_id'] === $scopeId,
                    default => false,
                };

                return $matchesScope && $selectedClubTypes->contains((string) $club['club_type']);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect($targetClubIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $eligibleClubIds, true))
            ->unique()
            ->values()
            ->all();
    }

    protected function visibleEventClubIdsForUser($user, Event $event): array
    {
        $targetClubIds = $event->targetClubs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $eventScopeType = (string) ($event->scope_type ?: 'club');

        if ($eventScopeType === 'club') {
            return [(int) $event->club_id];
        }

        $role = $this->plannerRoleKey($user);
        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return $targetClubIds;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $userClubIds = $this->userClubIdsForHierarchy($user);

            return collect($targetClubIds)
                ->filter(fn ($clubId) => in_array((int) $clubId, $userClubIds, true))
                ->values()
                ->all();
        }

        return $targetClubIds;
    }

    protected function manageableSettlementClubIdsForUser($user, Event $event): array
    {
        if (($event->scope_type ?: 'club') === 'club') {
            return [];
        }

        if (($user->profile_type ?? null) === 'superadmin') {
            return [];
        }

        $role = ClubHelper::roleKey($user);
        if (!in_array($role, ['club_director', 'club_personal'], true)) {
            return [];
        }

        $userClubIds = $this->userClubIds($user);
        $targetClubIds = $event->targetClubs->pluck('id')->map(fn ($id) => (int) $id)->all();

        return collect($targetClubIds)
            ->filter(fn ($clubId) => in_array((int) $clubId, $userClubIds, true))
            ->values()
            ->all();
    }
}
