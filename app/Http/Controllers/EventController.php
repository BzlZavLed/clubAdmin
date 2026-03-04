<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Club;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\Member;
use App\Models\ClubClass;
use App\Models\User;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\Payment;
use App\Services\SerpApiUsageService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Event::class);

        $user = $request->user();
        $clubIds = $this->userClubIds($user);

        $query = Event::with('plan')
            ->whereIn('club_id', $clubIds);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
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

        return Inertia::render('EventPlanner/Index', [
            'events' => $events,
            'filters' => $request->only(['status', 'event_type', 'start_from', 'start_to']),
            'clubIds' => $clubIds,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', Event::class);

        $user = $request->user();
        $clubs = Club::whereIn('id', $this->userClubIds($user))
            ->get(['id', 'club_name']);

        return Inertia::render('EventPlanner/Create', [
            'clubs' => $clubs,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Event::class);

        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'title' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'budget_estimated_total' => ['nullable', 'numeric'],
            'budget_actual_total' => ['nullable', 'numeric'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_payable' => ['nullable', 'boolean'],
            'payment_amount' => ['required_if:is_payable,1', 'nullable', 'numeric', 'min:0.01'],
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertUserHasClub($request->user(), (int) $validated['club_id']);

        $isPayable = (bool) ($validated['is_payable'] ?? false);

        $event = Event::create([
            'club_id' => $validated['club_id'],
            'created_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'event_type' => $validated['event_type'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'timezone' => $validated['timezone'] ?? 'America/New_York',
            'location_name' => $validated['location_name'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'budget_estimated_total' => $validated['budget_estimated_total'] ?? null,
            'budget_actual_total' => $validated['budget_actual_total'] ?? null,
            'requires_approval' => $validated['requires_approval'] ?? false,
            'is_payable' => $isPayable,
            'payment_amount' => $isPayable ? ($validated['payment_amount'] ?? null) : null,
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

        EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);

        $this->syncPaymentConcept($event, $isPayable, $validated['payment_amount'] ?? null, $request->user()->id);

        return redirect()->route('events.show', $event);
    }

    public function show(Event $event)
    {
        $this->authorize('view', $event);

        $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents', 'placeOptions']);
        $clubId = $event->club_id;

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

        $paymentSummary = [
            'total_received' => 0.0,
            'by_member_id' => [],
            'by_staff_id' => [],
        ];
        $paymentRecords = [];
        $paymentConceptLabel = null;
        if ($event->payment_concept_id) {
            $paymentConceptLabel = PaymentConcept::query()
                ->where('id', $event->payment_concept_id)
                ->value('concept');

            $conceptPayments = Payment::query()
                ->where('club_id', $clubId)
                ->where('payment_concept_id', $event->payment_concept_id);

            $paymentSummary['total_received'] = (float) (clone $conceptPayments)->sum('amount_paid');
            $paymentSummary['by_member_id'] = (clone $conceptPayments)
                ->whereNotNull('member_id')
                ->groupBy('member_id')
                ->selectRaw('member_id, SUM(amount_paid) as total_paid')
                ->pluck('total_paid', 'member_id')
                ->toArray();
            $paymentSummary['by_staff_id'] = (clone $conceptPayments)
                ->whereNotNull('staff_id')
                ->groupBy('staff_id')
                ->selectRaw('staff_id, SUM(amount_paid) as total_paid')
                ->pluck('total_paid', 'staff_id')
                ->toArray();

            $paymentRecords = (clone $conceptPayments)
                ->with(['member', 'staff.user:id,name', 'receivedBy:id,name'])
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->get()
                ->map(function (Payment $payment) {
                    $memberName = null;
                    if ($payment->member) {
                        $memberName = ClubHelper::memberDetail($payment->member)['name'] ?? null;
                    }

                    $staffName = null;
                    if ($payment->staff) {
                        $staffName = ClubHelper::staffDetail($payment->staff)['name']
                            ?? $payment->staff->user?->name;
                    }

                    return [
                        'id' => $payment->id,
                        'payment_date' => optional($payment->payment_date)->format('Y-m-d'),
                        'amount_paid' => (float) $payment->amount_paid,
                        'payment_type' => $payment->payment_type,
                        'payer_type' => $payment->member_id ? 'member' : ($payment->staff_id ? 'staff' : 'other'),
                        'payer_name' => $memberName ?: $staffName ?: 'Unknown',
                        'notes' => $payment->notes,
                        'received_by' => $payment->receivedBy?->name,
                    ];
                })
                ->values()
                ->all();
        }

        $serpApiUsage = app(SerpApiUsageService::class)->currentMonthSummary();

        return Inertia::render('EventPlanner/Show', [
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $event->tasks,
            'budgetItems' => $event->budgetItems,
            'participants' => $event->participants,
            'documents' => $event->documents,
            'placeOptions' => $event->placeOptions,
            'members' => $members,
            'classes' => $classes,
            'staff' => $staff,
            'parents' => $parents,
            'paymentSummary' => $paymentSummary,
            'paymentConfig' => [
                'concept_id' => $event->payment_concept_id,
                'concept_label' => $paymentConceptLabel,
                'amount' => $event->payment_amount,
                'is_payable' => $event->is_payable,
            ],
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

    public function pdf(Event $event)
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
        ]);

        return $pdf->download('event-plan-' . $event->id . '.pdf');
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'budget_estimated_total' => ['nullable', 'numeric'],
            'budget_actual_total' => ['nullable', 'numeric'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_payable' => ['nullable', 'boolean'],
            'payment_amount' => ['required_if:is_payable,1', 'nullable', 'numeric', 'min:0.01'],
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

        $isPayable = (bool) ($validated['is_payable'] ?? false);

        $event->update([
            'title' => $validated['title'],
            'event_type' => $validated['event_type'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'timezone' => $validated['timezone'] ?? $event->timezone,
            'location_name' => $validated['location_name'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'status' => $validated['status'] ?? $event->status,
            'budget_estimated_total' => $validated['budget_estimated_total'] ?? null,
            'budget_actual_total' => $validated['budget_actual_total'] ?? null,
            'requires_approval' => $validated['requires_approval'] ?? false,
            'is_payable' => $isPayable,
            'payment_amount' => $isPayable ? ($validated['payment_amount'] ?? null) : null,
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

        $this->syncPaymentConcept($event, $isPayable, $validated['payment_amount'] ?? null, $request->user()->id);

        return redirect()->route('events.show', $event);
    }

    public function destroy(Event $event)
    {
        $this->authorize('delete', $event);

        $event->delete();

        return redirect()->route('events.index');
    }

    protected function userClubIds($user): array
    {
        $clubIds = $user->clubs()->pluck('clubs.id')->all();
        if ($user->club_id) {
            $clubIds[] = $user->club_id;
        }

        return array_values(array_unique(array_filter($clubIds)));
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
}
