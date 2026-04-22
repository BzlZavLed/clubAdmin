<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\AssociationEvaluator;
use App\Models\AssociationHonorClassSession;
use App\Models\AssociationWorkplanEvent;
use App\Models\AssociationWorkplanPublication;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\Union;
use App\Models\User;
use App\Support\SuperadminContext;
use App\Services\WorkplanPropagationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssociationController extends Controller
{
    protected function normalizeClubType(?string $value): string
    {
        return str_replace(['-', '_', ' '], '', mb_strtolower((string) $value));
    }

    public function index()
    {
        return Inertia::render('SuperAdmin/Associations', [
            'unions' => Union::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'name', 'status']),
            'associations' => Association::query()
                ->with('union:id,name')
                ->withCount('districts')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('union_id', $request->input('union_id'))
                        ->where('status', '!=', 'deleted');
                }),
            ],
        ]);

        Association::create([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Asociacion creada correctamente.');
    }

    public function update(Request $request, Association $association)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')
                    ->ignore($association->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('union_id', $request->input('union_id'))
                            ->where('status', '!=', 'deleted');
                    }),
            ],
        ]);

        $association->update([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Asociacion actualizada correctamente.');
    }

    public function deactivate(Association $association)
    {
        $association->update(['status' => 'inactive']);

        return back()->with('success', 'Asociacion desactivada correctamente.');
    }

    public function destroy(Association $association)
    {
        $association->update(['status' => 'deleted']);

        return back()->with('success', 'Asociacion eliminada correctamente.');
    }

    public function programs(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system,status');

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->whereHas('church.district', fn ($query) => $query->where('association_id', $association->id))
            ->with(['church:id,district_id,church_name', 'church.district:id,association_id,name'])
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'evaluation_system', 'church_id', 'church_name', 'district_id', 'user_id', 'director_name', 'status']);

        $payload = [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $association->union?->evaluation_system ?: 'honors',
            ],
            'clubs' => $clubs->map(fn ($club) => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'evaluation_system' => $club->evaluation_system,
                'church_name' => $club->church_name,
                'district_name' => $club->church?->district?->name,
                'director_name' => $club->director_name,
                'status' => $club->status,
            ])->values(),
        ];

        if (($association->union?->evaluation_system ?: 'honors') === 'carpetas') {
            $currentYear = $association->union?->carpetaYears()
                ->with('requirements')
                ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
                ->orderByDesc('year')
                ->orderByDesc('id')
                ->first();

            $payload['carpeta_year'] = $currentYear ? [
                'id' => $currentYear->id,
                'year' => $currentYear->year,
                'status' => $currentYear->status,
                'requirements' => $currentYear->requirements->map(fn ($requirement) => [
                    'id' => $requirement->id,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'club_type' => $requirement->club_type,
                    'class_name' => $requirement->class_name,
                    'requirement_type' => $requirement->requirement_type,
                    'validation_mode' => $requirement->validation_mode,
                    'status' => $requirement->status,
                ])->values(),
            ] : null;

            $payload['club_requirement_map'] = $clubs->map(function ($club) use ($currentYear) {
                $requirements = collect($currentYear?->requirements ?? [])
                    ->filter(fn ($requirement) => $this->normalizeClubType($requirement->club_type) === $this->normalizeClubType($club->club_type))
                    ->groupBy('class_name')
                    ->map(fn ($items, $className) => [
                        'class_name' => $className,
                        'requirements' => collect($items)->map(fn ($requirement) => [
                            'id' => $requirement->id,
                            'title' => $requirement->title,
                            'description' => $requirement->description,
                            'requirement_type' => $requirement->requirement_type,
                            'validation_mode' => $requirement->validation_mode,
                            'status' => $requirement->status,
                        ])->values(),
                    ])
                    ->values();

                return [
                    'club_id' => $club->id,
                    'class_groups' => $requirements,
                ];
            })->values();
        } else {
            $payload['honor_sessions'] = $association->honorClassSessions()
                ->get()
                ->map(fn ($session) => [
                    'id' => $session->id,
                    'club_type' => $session->club_type,
                    'class_name' => $session->class_name,
                    'title' => $session->title,
                    'session_date' => optional($session->session_date)->toDateString(),
                    'location' => $session->location,
                    'notes' => $session->notes,
                    'status' => $session->status,
                ])
                ->values();
        }

        return Inertia::render('Association/Programs', $payload);
    }

    public function storeHonorSession(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,evaluation_system');

        if (($association->union?->evaluation_system ?: 'honors') !== 'honors') {
            abort(422, 'Honor planning is only available when the union evaluation system is honors.');
        }

        $validated = $request->validate([
            'club_type' => ['required', 'string', 'max:255'],
            'class_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'session_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['planned', 'open', 'completed'])],
        ]);

        $association->honorClassSessions()->create([
            'club_type' => $validated['club_type'],
            'class_name' => $validated['class_name'],
            'title' => $validated['title'],
            'session_date' => $validated['session_date'],
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'] ?? 'planned',
        ]);

        return back()->with('success', 'Honor class session planned successfully.');
    }

    public function destroyHonorSession(Request $request, AssociationHonorClassSession $session)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $session->association_id !== (int) $association->id) {
            abort(403);
        }

        $session->delete();

        return back()->with('success', 'Honor class session removed.');
    }

    public function districtEvaluation(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system');

        $districts = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'association_id', 'name', 'pastor_name', 'pastor_email', 'is_evaluator', 'status']);

        $districtIds = $districts->pluck('id')->toArray();

        // Scan clubs for pastor hints on districts that have no pastor set yet
        $clubPastorHints = Club::query()
            ->withoutGlobalScopes()
            ->whereIn('district_id', $districtIds)
            ->whereNotNull('pastor_name')
            ->where('pastor_name', '!=', '')
            ->orderBy('id')
            ->get(['district_id', 'pastor_name'])
            ->groupBy('district_id')
            ->map(fn($rows) => $rows->first()->pastor_name);

        $evaluators = AssociationEvaluator::query()
            ->where('association_id', $association->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'notes']);

        return Inertia::render('Association/DistrictEvaluation', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $association->union?->evaluation_system ?: 'honors',
            ],
            'districts' => $districts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'pastor_name' => $d->pastor_name,
                'pastor_email' => $d->pastor_email,
                'is_evaluator' => (bool) $d->is_evaluator,
                'status' => $d->status,
                'club_pastor_hint' => !$d->pastor_name ? ($clubPastorHints->get($d->id) ?? null) : null,
            ])->values(),
            'evaluators' => $evaluators->map(fn($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'notes' => $e->notes,
            ])->values(),
        ]);
    }

    public function workplan(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $year = (int) $request->input('year', now()->year);
        $publication = AssociationWorkplanPublication::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->first();
        $lastChangedAt = AssociationWorkplanEvent::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->max('updated_at');
        $requiresRepublish = $publication?->status === 'published'
            && $publication?->published_at
            && $lastChangedAt
            && strtotime((string) $lastChangedAt) > strtotime((string) $publication->published_at);

        return Inertia::render('Association/Workplan', [
            'association' => ['id' => $association->id, 'name' => $association->name],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
            ],
            'year' => $year,
            'events' => AssociationWorkplanEvent::query()
                ->where('association_id', $association->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get(),
            'publication' => $publication,
            'requiresRepublish' => $requiresRepublish,
        ]);
    }

    public function storeWorkplanEvent(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $this->validateWorkplanEvent($request, requireYear: true);

        AssociationWorkplanEvent::query()->create([
            ...$validated,
            'association_id' => $association->id,
            'status' => 'active',
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Evento creado correctamente.');
    }

    public function updateWorkplanEvent(Request $request, AssociationWorkplanEvent $event)
    {
        $association = $this->resolveScopedAssociation($request);
        $this->assertOwnsWorkplanEvent($association, $event);

        $event->update($this->validateWorkplanEvent($request));

        return back()->with('success', 'Evento actualizado correctamente.');
    }

    public function destroyWorkplanEvent(Request $request, AssociationWorkplanEvent $event)
    {
        $association = $this->resolveScopedAssociation($request);
        $this->assertOwnsWorkplanEvent($association, $event);

        if (!empty($event->union_workplan_event_id)) {
            abort(422, 'Los eventos heredados de la union no se pueden eliminar desde la asociacion.');
        }

        $event->update(['status' => 'deleted']);

        return back()->with('success', 'Evento eliminado.');
    }

    public function publishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->publishAssociation($association, (int) $validated['year'], $request->user());

        return back()->with('success', "Calendario publicado a {$result['clubs']} clubes.");
    }

    public function unpublishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->unpublishAssociation($association, (int) $validated['year']);

        return back()->with('success', "Calendario despublicado. Se removieron {$result['club_events']} eventos de clubes.");
    }

    public function storeDistrict(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
        ]);

        $exists = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->whereRaw('lower(name) = ?', [mb_strtolower($validated['name'])])
            ->exists();

        if ($exists) {
            return back()->withErrors(['name' => 'A district with this name already exists.']);
        }

        District::create([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'pastor_name' => $validated['pastor_name'] ?? null,
            'pastor_email' => $validated['pastor_email'] ?? null,
            'status' => 'active',
        ]);

        return back()->with('success', 'District created.');
    }

    public function updateDistrict(Request $request, District $district)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $district->association_id !== (int) $association->id) {
            abort(403);
        }

        $validated = $request->validate([
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
            'is_evaluator' => ['sometimes', 'boolean'],
        ]);

        $district->update($validated);

        return back()->with('success', 'District updated.');
    }

    public function associationClubs(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system');

        $districts = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'name', 'pastor_name', 'status']);

        $districtIds = $districts->pluck('id')->toArray();

        $churches = Church::query()
            ->whereIn('district_id', $districtIds)
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'pastor_name']);

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->whereIn('district_id', $districtIds)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'status', 'church_id', 'church_name', 'district_id', 'director_name', 'user_id', 'evaluation_system', 'creation_date']);

        $clubIds = $clubs->pluck('id')->toArray();

        $adventurerClubIds = $clubs->where('club_type', 'adventurers')->pluck('id')->toArray();
        $pathfinderClubIds = $clubs->whereIn('club_type', ['pathfinders', 'master_guide'])->pluck('id')->toArray();

        $adventurerMembers = MemberAdventurer::query()
            ->whereIn('club_id', $adventurerClubIds)
            ->where('status', 'active')
            ->get(['id', 'club_id', 'applicant_name', 'birthdate', 'age', 'email_address', 'cell_number', 'insurance_paid', 'insurance_paid_at'])
            ->groupBy('club_id');

        $pathfinderMembers = MemberPathfinder::query()
            ->whereIn('club_id', $pathfinderClubIds)
            ->where('status', 'active')
            ->get(['id', 'club_id', 'applicant_name', 'birthdate', 'email_address', 'cell_number', 'insurance_paid', 'insurance_paid_at'])
            ->groupBy('club_id');

        $evaluationSystem = $association->union?->evaluation_system ?: 'honors';

        $formatMember = fn($m) => [
            'id' => $m->id,
            'name' => $m->applicant_name,
            'birthdate' => $m->birthdate?->toDateString(),
            'age' => $m->age ?? ($m->birthdate ? (int) $m->birthdate->diffInYears(now()) : null),
            'email' => $m->email_address,
            'phone' => $m->cell_number,
            'insurance_paid' => (bool) $m->insurance_paid,
            'insurance_paid_at' => $m->insurance_paid_at?->toDateString(),
        ];

        return Inertia::render('Association/Clubs', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
                'insurance_payment_amount' => $association->insurance_payment_amount,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $evaluationSystem,
            ],
            'districts' => $districts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'pastor_name' => $d->pastor_name,
            ])->values(),
            'churches' => $churches->map(fn($c) => [
                'id' => $c->id,
                'district_id' => $c->district_id,
                'church_name' => $c->church_name,
                'pastor_name' => $c->pastor_name,
            ])->values(),
            'clubs' => $clubs->map(function ($c) use ($adventurerMembers, $pathfinderMembers, $formatMember) {
                $members = $c->club_type === 'adventurers'
                    ? ($adventurerMembers->get($c->id) ?? collect())
                    : ($pathfinderMembers->get($c->id) ?? collect());

                return [
                    'id' => $c->id,
                    'club_name' => $c->club_name,
                    'club_type' => $c->club_type,
                    'status' => $c->status,
                    'church_id' => $c->church_id,
                    'church_name' => $c->church_name,
                    'district_id' => $c->district_id,
                    'director_name' => $c->director_name,
                    'has_director' => (bool) $c->user_id,
                    'evaluation_system' => $c->evaluation_system,
                    'creation_date' => $c->creation_date,
                    'members' => $members->map($formatMember)->values(),
                ];
            })->values(),
        ]);
    }

    public function storeAssociationClub(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,evaluation_system');

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->pluck('id')->toArray();

        $churchIds = Church::query()
            ->whereIn('district_id', $districtIds)
            ->pluck('id')->toArray();

        $validated = $request->validate([
            'church_id'     => ['required', 'integer', Rule::in($churchIds)],
            'club_name'     => ['required', 'string', 'max:255'],
            'club_type'     => ['required', Rule::in(['adventurers', 'pathfinders', 'master_guide'])],
            'creation_date' => ['nullable', 'date'],
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $church->loadMissing('district.association.union:id,evaluation_system');

        $district = $church->district;

        // Enforce one club per type per church
        $duplicate = Club::query()
            ->withoutGlobalScopes()
            ->where('church_id', $church->id)
            ->where('club_type', $validated['club_type'])
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['club_type' => 'This church already has a club of this type.']);
        }

        Club::create([
            'club_name'       => $validated['club_name'],
            'club_type'       => $validated['club_type'],
            'creation_date'   => $validated['creation_date'] ?? null,
            'church_id'       => $church->id,
            'church_name'     => $church->church_name,
            'district_id'     => $district?->id,
            'pastor_name'     => $church->pastor_name,
            'conference_name' => $association->name,
            'evaluation_system' => $district?->association?->union?->evaluation_system ?: 'honors',
            'status'          => 'inactive',
            'user_id'         => null,
            'director_name'   => null,
        ]);

        return back()->with('success', 'Club created. Assign a director to activate it.');
    }

    public function storeClubDirector(Request $request, int $club)
    {
        $club = Club::withoutGlobalScopes()->findOrFail($club);
        $association = $this->resolveScopedAssociation($request);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->pluck('id')->toArray();

        if (!in_array((int) $club->district_id, $districtIds)) {
            abort(403);
        }

        if ($club->user_id) {
            return back()->withErrors(['email' => 'This club already has a director assigned.']);
        }

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $church = Church::find($club->church_id);

        $director = User::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => Hash::make($validated['password']),
            'profile_type' => 'club_director',
            'scope_type'  => 'club',
            'scope_id'    => $club->id,
            'club_id'     => $club->id,
            'church_id'   => $church?->id,
            'church_name' => $church?->church_name,
            'status'      => 'active',
        ]);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $director->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $club->update([
            'user_id'      => $director->id,
            'director_name' => $director->name,
            'status'       => 'active',
        ]);

        return back()->with('success', 'Director created and club activated.');
    }

    public function toggleMemberInsurance(Request $request, int $clubId, int $memberId)
    {
        $association = $this->resolveScopedAssociation($request);

        $club = Club::withoutGlobalScopes()->findOrFail($clubId);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->pluck('id')->toArray();

        if (!in_array((int) $club->district_id, $districtIds)) {
            abort(403);
        }

        if ($club->club_type === 'adventurers') {
            $member = MemberAdventurer::where('club_id', $club->id)->findOrFail($memberId);
        } else {
            $member = MemberPathfinder::where('club_id', $club->id)->findOrFail($memberId);
        }

        $nowPaid = !$member->insurance_paid;
        $member->update([
            'insurance_paid' => $nowPaid,
            'insurance_paid_at' => $nowPaid ? now() : null,
        ]);

        return back()->with('success', 'Insurance status updated.');
    }

    public function associationSettings(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        return Inertia::render('Association/Settings', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
                'insurance_payment_amount' => $association->insurance_payment_amount,
            ],
        ]);
    }

    public function updateAssociationSettings(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'insurance_payment_amount' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ]);

        $association->update($validated);

        return back()->with('success', 'Settings saved.');
    }

    public function churches(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $districts = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->with(['churches' => fn($q) => $q->orderBy('church_name')])
            ->get(['id', 'name', 'pastor_name', 'pastor_email', 'is_evaluator', 'status']);

        $districtIds = $districts->pluck('id')->toArray();

        $churches = Church::query()
            ->whereIn('district_id', $districtIds)
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'address', 'ethnicity', 'phone_number', 'email', 'pastor_name', 'pastor_email']);

        return Inertia::render('Association/Churches', [
            'association' => ['id' => $association->id, 'name' => $association->name],
            'districts' => $districts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'pastor_name' => $d->pastor_name,
                'pastor_email' => $d->pastor_email,
                'is_evaluator' => (bool) $d->is_evaluator,
                'churches' => $d->churches->map(fn($c) => [
                    'id' => $c->id,
                    'church_name' => $c->church_name,
                    'email' => $c->email,
                    'phone_number' => $c->phone_number,
                    'pastor_name' => $c->pastor_name,
                    'pastor_email' => $c->pastor_email,
                ])->values(),
            ])->values(),
            'churches' => $churches->map(fn($c) => [
                'id' => $c->id,
                'district_id' => $c->district_id,
                'church_name' => $c->church_name,
                'address' => $c->address,
                'ethnicity' => $c->ethnicity,
                'phone_number' => $c->phone_number,
                'email' => $c->email,
                'pastor_name' => $c->pastor_name,
                'pastor_email' => $c->pastor_email,
            ])->values(),
        ]);
    }

    public function storeChurch(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->pluck('id')->toArray();

        $validated = $request->validate([
            'district_id' => ['required', 'integer', Rule::in($districtIds)],
            'church_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'ethnicity' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
        ]);

        Church::create(array_merge($validated, ['conference' => $association->name]));

        return back()->with('success', 'Church created.');
    }

    public function updateChurch(Request $request, Church $church)
    {
        $association = $this->resolveScopedAssociation($request);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->pluck('id')->toArray();

        if (!in_array((int) $church->district_id, $districtIds)) {
            abort(403);
        }

        $validated = $request->validate([
            'district_id' => ['required', 'integer', Rule::in($districtIds)],
            'church_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'ethnicity' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
        ]);

        $church->update(array_merge($validated, ['conference' => $association->name]));

        return back()->with('success', 'Church updated.');
    }

    public function destroyChurch(Request $request, Church $church)
    {
        $association = $this->resolveScopedAssociation($request);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->pluck('id')->toArray();

        if (!in_array((int) $church->district_id, $districtIds)) {
            abort(403);
        }

        $church->delete();

        return back()->with('success', 'Church deleted.');
    }

    public function storeEvaluator(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        AssociationEvaluator::create([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Evaluator added.');
    }

    public function destroyEvaluator(Request $request, AssociationEvaluator $evaluator)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $evaluator->association_id !== (int) $association->id) {
            abort(403);
        }

        $evaluator->delete();

        return back()->with('success', 'Evaluator removed.');
    }

    protected function resolveScopedAssociation(Request $request): Association
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();

            if (($context['role'] ?? null) !== 'association_youth_director' || empty($context['association_id'])) {
                abort(403);
            }

            return Association::query()->findOrFail((int) $context['association_id']);
        }

        if ($user->profile_type !== 'association_youth_director' || $user->scope_type !== 'association' || empty($user->scope_id)) {
            abort(403);
        }

        return Association::query()->findOrFail((int) $user->scope_id);
    }

    protected function validateWorkplanEvent(Request $request, bool $requireYear = false): array
    {
        return $request->validate([
            'year' => [$requireYear ? 'required' : 'sometimes', 'integer', 'min:2000', 'max:2100'],
            'date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'event_type' => ['required', Rule::in(['general', 'program'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'target_club_types' => ['nullable', 'array'],
            'target_club_types.*' => ['string', Rule::in(['pathfinders', 'adventurers', 'master_guide'])],
            'is_mandatory' => ['boolean'],
        ]);
    }

    protected function assertOwnsWorkplanEvent(Association $association, AssociationWorkplanEvent $event): void
    {
        if ((int) $event->association_id !== (int) $association->id) {
            abort(403);
        }
    }
}
