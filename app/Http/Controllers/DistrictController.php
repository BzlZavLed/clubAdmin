<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\AssociationWorkplanEvent;
use App\Models\AssociationWorkplanPublication;
use App\Models\Club;
use App\Models\District;
use App\Models\DistrictWorkplanEvent;
use App\Models\DistrictWorkplanPublication;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\PaymentConcept;
use App\Models\Union;
use App\Models\User;
use App\Services\WorkplanPropagationService;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DistrictController extends Controller
{
    protected function syncClubInsurancePaymentConcept(Club $club, ?int $actorId = null): void
    {
        $club->loadMissing('district.association');

        $amount = (float) ($club->district?->association?->insurance_payment_amount ?? 0);
        $concept = PaymentConcept::withTrashed()
            ->where('club_id', $club->id)
            ->where('concept', 'Seguro de membresía')
            ->where('pay_to', 'church_budget')
            ->first();

        if ($amount <= 0) {
            if ($concept) {
                $concept->update([
                    'amount' => 0,
                    'status' => 'inactive',
                    'reusable' => true,
                ]);
            }

            return;
        }

        if ($concept && method_exists($concept, 'trashed') && $concept->trashed()) {
            $concept->restore();
        }

        $concept ??= PaymentConcept::create([
            'club_id' => $club->id,
            'concept' => 'Seguro de membresía',
            'pay_to' => 'church_budget',
            'type' => 'mandatory',
            'status' => 'active',
            'created_by' => $actorId,
            'amount' => $amount,
            'reusable' => true,
        ]);

        $concept->update([
            'amount' => $amount,
            'type' => 'mandatory',
            'status' => 'active',
            'reusable' => true,
        ]);
    }

    public function index()
    {
        return Inertia::render('SuperAdmin/Districts', [
            'associations' => Association::query()
                ->with('union:id,name')
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name', 'status']),
            'districts' => District::query()
                ->with('association.union:id,name')
                ->withCount('churches')
                ->orderBy('name')
                ->get(['id', 'association_id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'association_id' => ['required', 'exists:associations,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('association_id', $request->input('association_id'))
                        ->where('status', '!=', 'deleted');
                }),
            ],
        ]);

        District::create([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Distrito creado correctamente.');
    }

    public function update(Request $request, District $district)
    {
        $validated = $request->validate([
            'association_id' => ['required', 'exists:associations,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'name')
                    ->ignore($district->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('association_id', $request->input('association_id'))
                            ->where('status', '!=', 'deleted');
                    }),
            ],
        ]);

        $district->update([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Distrito actualizado correctamente.');
    }

    public function deactivate(District $district)
    {
        $district->update(['status' => 'inactive']);

        return back()->with('success', 'Distrito desactivado correctamente.');
    }

    public function destroy(District $district)
    {
        $district->update(['status' => 'deleted']);

        return back()->with('success', 'Distrito eliminado correctamente.');
    }

    public function churches(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association:id,name');

        $churches = Church::query()
            ->where('district_id', $district->id)
            ->withCount('clubs')
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'address', 'ethnicity', 'phone_number', 'email']);

        return Inertia::render('District/Churches', [
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
            'churches' => $churches->map(fn (Church $church) => [
                'id' => $church->id,
                'church_name' => $church->church_name,
                'address' => $church->address,
                'ethnicity' => $church->ethnicity,
                'phone_number' => $church->phone_number,
                'email' => $church->email,
                'clubs_count' => (int) ($church->clubs_count ?? 0),
            ])->values(),
        ]);
    }

    public function storeChurch(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association:id,name');

        $validated = $request->validate([
            'church_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('churches', 'church_name')->where(
                    fn ($query) => $query->where('district_id', $district->id)
                ),
            ],
            'address' => ['nullable', 'string'],
            'ethnicity' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $church = Church::query()->create([
            ...$validated,
            'district_id' => $district->id,
            'conference' => $district->association?->name,
            'pastor_name' => null,
            'pastor_email' => null,
        ]);

        ChurchInviteCode::firstOrCreate(
            ['church_id' => $church->id],
            [
                'code' => Str::upper(Str::random(10)),
                'uses_left' => null,
                'status' => 'active',
            ]
        );

        return back()->with('success', 'Iglesia creada correctamente.');
    }

    public function updateChurch(Request $request, Church $church)
    {
        $district = $this->resolveScopedDistrict($request)->load('association:id,name');
        $this->assertOwnsChurch($district, $church);

        $validated = $request->validate([
            'church_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('churches', 'church_name')
                    ->ignore($church->id)
                    ->where(fn ($query) => $query->where('district_id', $district->id)),
            ],
            'address' => ['nullable', 'string'],
            'ethnicity' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $church->update([
            ...$validated,
            'conference' => $district->association?->name,
            'pastor_name' => null,
            'pastor_email' => null,
        ]);

        return back()->with('success', 'Iglesia actualizada correctamente.');
    }

    public function destroyChurch(Request $request, Church $church)
    {
        $district = $this->resolveScopedDistrict($request);
        $this->assertOwnsChurch($district, $church);

        if ($church->clubs()->exists()) {
            abort(422, 'No se puede eliminar una iglesia que ya tiene clubes asociados.');
        }

        $church->delete();

        return back()->with('success', 'Iglesia eliminada correctamente.');
    }

    public function clubs(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union.clubCatalogs');

        $churches = Church::query()
            ->where('district_id', $district->id)
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'pastor_name']);

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->where('district_id', $district->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'status', 'church_id', 'church_name', 'district_id', 'director_name', 'user_id', 'evaluation_system', 'creation_date']);

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

        $formatMember = fn ($member) => [
            'id' => $member->id,
            'name' => $member->applicant_name,
            'birthdate' => $member->birthdate?->toDateString(),
            'age' => $member->age ?? ($member->birthdate ? (int) $member->birthdate->diffInYears(now()) : null),
            'email' => $member->email_address,
            'phone' => $member->cell_number,
            'insurance_paid' => (bool) $member->insurance_paid,
            'insurance_paid_at' => $member->insurance_paid_at?->toDateString(),
        ];

        return Inertia::render('District/Clubs', [
            'district' => [
                'id' => $district->id,
                'name' => $district->name,
                'pastor_name' => $district->pastor_name,
                'pastor_email' => $district->pastor_email,
            ],
            'association' => [
                'id' => $district->association?->id,
                'name' => $district->association?->name,
                'insurance_payment_amount' => $district->association?->insurance_payment_amount,
            ],
            'union' => [
                'id' => $district->association?->union?->id,
                'name' => $district->association?->union?->name,
                'evaluation_system' => $district->association?->union?->evaluation_system ?: 'honors',
            ],
            'clubCatalogs' => $district->association?->union?->clubCatalogs
                ? $district->association->union->clubCatalogs
                    ->where('status', 'active')
                    ->map(fn ($catalog) => [
                        'id' => $catalog->id,
                        'name' => $catalog->name,
                        'club_type' => $catalog->club_type,
                        'sort_order' => $catalog->sort_order,
                    ])
                    ->values()
                : [],
            'churches' => $churches->map(fn (Church $church) => [
                'id' => $church->id,
                'district_id' => $church->district_id,
                'church_name' => $church->church_name,
                'pastor_name' => $church->pastor_name ?: $district->pastor_name,
            ])->values(),
            'clubs' => $clubs->map(function ($club) use ($adventurerMembers, $pathfinderMembers, $formatMember) {
                $members = $club->club_type === 'adventurers'
                    ? ($adventurerMembers->get($club->id) ?? collect())
                    : ($pathfinderMembers->get($club->id) ?? collect());

                return [
                    'id' => $club->id,
                    'club_name' => $club->club_name,
                    'club_type' => $club->club_type,
                    'status' => $club->status,
                    'church_id' => $club->church_id,
                    'church_name' => $club->church_name,
                    'district_id' => $club->district_id,
                    'director_name' => $club->director_name,
                    'has_director' => (bool) $club->user_id,
                    'evaluation_system' => $club->evaluation_system,
                    'creation_date' => $club->creation_date,
                    'members' => $members->map($formatMember)->values(),
                ];
            })->values(),
        ]);
    }

    public function storeClub(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union.clubCatalogs');

        $churchIds = Church::query()
            ->where('district_id', $district->id)
            ->pluck('id')
            ->toArray();

        $allowedClubTypes = $district->association?->union?->clubCatalogs
            ? $district->association->union->clubCatalogs
                ->where('status', 'active')
                ->pluck('club_type')
                ->filter()
                ->values()
                ->all()
            : [];

        if (empty($allowedClubTypes)) {
            return back()->withErrors(['club_type' => 'No active club types are configured in the union catalog.']);
        }

        $validated = $request->validate([
            'church_id' => ['required', 'integer', Rule::in($churchIds)],
            'club_name' => ['required', 'string', 'max:255'],
            'club_type' => ['required', Rule::in($allowedClubTypes)],
            'creation_date' => ['nullable', 'date'],
        ]);

        $church = Church::query()->findOrFail($validated['church_id']);
        $church->loadMissing('district.association.union:id,evaluation_system');

        $duplicate = Club::query()
            ->withoutGlobalScopes()
            ->where('church_id', $church->id)
            ->where('club_type', $validated['club_type'])
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['club_type' => 'This church already has a club of this type.']);
        }

        $club = Club::query()->create([
            'club_name' => $validated['club_name'],
            'club_type' => $validated['club_type'],
            'creation_date' => $validated['creation_date'] ?? null,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'district_id' => $district->id,
            'pastor_name' => $church->pastor_name ?: $district->pastor_name,
            'conference_name' => $district->association?->name,
            'evaluation_system' => $church->district?->association?->union?->evaluation_system ?: 'honors',
            'status' => 'inactive',
            'user_id' => null,
            'director_name' => null,
        ]);
        $this->syncClubInsurancePaymentConcept($club, $request->user()?->id);

        return back()->with('success', 'Club created. Assign a director to activate it.');
    }

    public function storeClubDirector(Request $request, int $club)
    {
        $district = $this->resolveScopedDistrict($request);
        $club = Club::withoutGlobalScopes()->findOrFail($club);

        if ((int) $club->district_id !== (int) $district->id) {
            abort(403);
        }

        if ($club->user_id) {
            return back()->withErrors(['email' => 'This club already has a director assigned.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $church = Church::find($club->church_id);

        $director = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_type' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'club_id' => $club->id,
            'church_id' => $church?->id,
            'church_name' => $church?->church_name,
            'status' => 'active',
        ]);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $director->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $club->update([
            'user_id' => $director->id,
            'director_name' => $director->name,
            'status' => 'active',
        ]);

        return back()->with('success', 'Director created and club activated.');
    }

    public function toggleMemberInsurance(Request $request, int $clubId, int $memberId)
    {
        $district = $this->resolveScopedDistrict($request);
        $club = Club::withoutGlobalScopes()->findOrFail($clubId);

        if ((int) $club->district_id !== (int) $district->id) {
            abort(403);
        }

        if ($club->club_type === 'adventurers') {
            $member = MemberAdventurer::where('club_id', $club->id)->findOrFail($memberId);
        } else {
            $member = MemberPathfinder::where('club_id', $club->id)->findOrFail($memberId);
        }

        $nowPaid = ! $member->insurance_paid;
        $member->update([
            'insurance_paid' => $nowPaid,
            'insurance_paid_at' => $nowPaid ? now() : null,
        ]);

        return back()->with('success', 'Insurance status updated.');
    }

    public function workplan(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union.clubCatalogs');
        $year = (int) $request->input('year', now()->year);

        $publication = AssociationWorkplanPublication::query()
            ->where('association_id', $district->association_id)
            ->where('year', $year)
            ->first();
        $districtPublication = DistrictWorkplanPublication::query()
            ->where('district_id', $district->id)
            ->where('year', $year)
            ->first();
        $lastDistrictChangedAt = DistrictWorkplanEvent::query()
            ->where('district_id', $district->id)
            ->where('year', $year)
            ->max('updated_at');
        $requiresRepublish = $districtPublication?->status === 'published'
            && $districtPublication?->published_at
            && $lastDistrictChangedAt
            && strtotime((string) $lastDistrictChangedAt) > strtotime((string) $districtPublication->published_at);

        $associationEvents = AssociationWorkplanEvent::query()
            ->where('association_id', $district->association_id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (AssociationWorkplanEvent $event) => [
                'id' => $event->id,
                'source_level' => $event->union_workplan_event_id ? 'union' : 'association',
                'year' => $event->year,
                'date' => $event->date,
                'end_date' => $event->end_date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'target_club_types' => $event->target_club_types,
                'is_mandatory' => (bool) $event->is_mandatory,
                'union_workplan_event_id' => $event->union_workplan_event_id,
            ]);

        $districtEvents = DistrictWorkplanEvent::query()
            ->where('district_id', $district->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (DistrictWorkplanEvent $event) => [
                'id' => $event->id,
                'source_level' => 'district',
                'year' => $event->year,
                'date' => $event->date,
                'end_date' => $event->end_date,
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'target_club_types' => $event->target_club_types,
                'is_mandatory' => (bool) $event->is_mandatory,
                'union_workplan_event_id' => null,
            ]);

        return Inertia::render('District/Workplan', [
            'district' => ['id' => $district->id, 'name' => $district->name],
            'association' => [
                'id' => $district->association?->id,
                'name' => $district->association?->name,
            ],
            'union' => [
                'id' => $district->association?->union?->id,
                'name' => $district->association?->union?->name,
            ],
            'clubTypeOptions' => $this->workplanClubTypeOptions($district->association?->union),
            'year' => $year,
            'associationPublication' => $publication,
            'districtPublication' => $districtPublication,
            'requiresRepublish' => $requiresRepublish,
            'events' => $associationEvents
                ->concat($districtEvents)
                ->sortBy([
                    ['date', 'asc'],
                    ['start_time', 'asc'],
                    ['title', 'asc'],
                ])
                ->values(),
        ]);
    }

    public function storeWorkplanEvent(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union.clubCatalogs');
        $validated = $this->validateWorkplanEvent($request, $district->association?->union, requireYear: true);

        DistrictWorkplanEvent::query()->create([
            ...$validated,
            'district_id' => $district->id,
            'status' => 'active',
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Evento distrital creado correctamente.');
    }

    public function updateWorkplanEvent(Request $request, DistrictWorkplanEvent $event)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union.clubCatalogs');
        $this->assertOwnsWorkplanEvent($district, $event);

        $event->update($this->validateWorkplanEvent($request, $district->association?->union));

        return back()->with('success', 'Evento distrital actualizado correctamente.');
    }

    public function destroyWorkplanEvent(Request $request, DistrictWorkplanEvent $event)
    {
        $district = $this->resolveScopedDistrict($request);
        $this->assertOwnsWorkplanEvent($district, $event);

        $event->update(['status' => 'deleted']);

        return back()->with('success', 'Evento distrital eliminado.');
    }

    public function publishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $district = $this->resolveScopedDistrict($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->publishDistrict($district, (int) $validated['year'], $request->user());

        return back()->with('success', "Calendario distrital publicado a {$result['clubs']} clubes.");
    }

    public function unpublishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $district = $this->resolveScopedDistrict($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->unpublishDistrict($district, (int) $validated['year']);

        return back()->with('success', "Calendario distrital despublicado. Se removieron {$result['club_events']} eventos de clubes.");
    }

    public function syncWorkplanMissing(Request $request, WorkplanPropagationService $propagationService)
    {
        $district = $this->resolveScopedDistrict($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = (int) $validated['year'];
        $publication = DistrictWorkplanPublication::query()
            ->where('district_id', $district->id)
            ->where('year', $year)
            ->first();

        if (($publication?->status ?? null) !== 'published') {
            abort(422, 'El calendario debe estar publicado antes de sincronizar eventos faltantes.');
        }

        $result = $propagationService->syncDistrictMissing($district, $year, $request->user());

        return back()->with(
            'success',
            "Sincronizacion completada. {$result['club_events_created']} eventos agregados en {$result['clubs']} clubes."
        );
    }

    protected function resolveScopedDistrict(Request $request): District
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();
            if (!in_array(($context['role'] ?? null), ['district_pastor', 'district_secretary'], true) || empty($context['district_id'])) {
                abort(403);
            }

            return District::query()->findOrFail((int) $context['district_id']);
        }

        if (!in_array($user->profile_type, ['district_pastor', 'district_secretary'], true) || $user->scope_type !== 'district' || empty($user->scope_id)) {
            abort(403);
        }

        return District::query()->findOrFail((int) $user->scope_id);
    }

    protected function validateWorkplanEvent(Request $request, ?Union $union, bool $requireYear = false): array
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
            'target_club_types.*' => ['string', Rule::in($this->workplanAllowedClubTypes($union))],
            'is_mandatory' => ['boolean'],
        ]);
    }

    protected function workplanAllowedClubTypes(?Union $union): array
    {
        if (! $union?->relationLoaded('clubCatalogs')) {
            $union?->load('clubCatalogs');
        }

        return $union?->clubCatalogs
            ? $union->clubCatalogs
                ->where('status', 'active')
                ->pluck('club_type')
                ->filter()
                ->values()
                ->all()
            : [];
    }

    protected function workplanClubTypeOptions(?Union $union): array
    {
        if (! $union?->relationLoaded('clubCatalogs')) {
            $union?->load('clubCatalogs');
        }

        return $union?->clubCatalogs
            ? $union->clubCatalogs
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
                ->all()
            : [];
    }

    protected function assertOwnsWorkplanEvent(District $district, DistrictWorkplanEvent $event): void
    {
        if ((int) $event->district_id !== (int) $district->id) {
            abort(403);
        }
    }

    protected function assertOwnsChurch(District $district, Church $church): void
    {
        if ((int) $church->district_id !== (int) $district->id) {
            abort(403);
        }
    }

}
