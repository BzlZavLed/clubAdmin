<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\AssociationWorkplanEvent;
use App\Models\AssociationWorkplanPublication;
use App\Models\District;
use App\Models\DistrictWorkplanEvent;
use App\Models\DistrictWorkplanPublication;
use App\Services\WorkplanPropagationService;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DistrictController extends Controller
{
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

    public function workplan(Request $request)
    {
        $district = $this->resolveScopedDistrict($request)->load('association.union');
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
        $district = $this->resolveScopedDistrict($request);
        $validated = $this->validateWorkplanEvent($request, requireYear: true);

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
        $district = $this->resolveScopedDistrict($request);
        $this->assertOwnsWorkplanEvent($district, $event);

        $event->update($this->validateWorkplanEvent($request));

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

    protected function assertOwnsWorkplanEvent(District $district, DistrictWorkplanEvent $event): void
    {
        if ((int) $event->district_id !== (int) $district->id) {
            abort(403);
        }
    }
}
