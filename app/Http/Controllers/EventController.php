<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventPlan;
use Illuminate\Http\Request;
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
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertUserHasClub($request->user(), (int) $validated['club_id']);

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
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

        EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);

        return redirect()->route('events.show', $event);
    }

    public function show(Event $event)
    {
        $this->authorize('view', $event);

        $event->load(['plan', 'tasks', 'budgetItems', 'participants', 'documents', 'placeOptions']);

        return Inertia::render('EventPlanner/Show', [
            'event' => $event,
            'eventPlan' => $event->plan,
            'tasks' => $event->tasks,
            'budgetItems' => $event->budgetItems,
            'participants' => $event->participants,
            'documents' => $event->documents,
            'placeOptions' => $event->placeOptions,
        ]);
    }

    public function edit(Event $event)
    {
        $this->authorize('update', $event);

        return Inertia::render('EventPlanner/Edit', [
            'event' => $event,
        ]);
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
            'risk_level' => ['nullable', 'string', 'max:255'],
        ]);

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
            'risk_level' => $validated['risk_level'] ?? null,
        ]);

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

    protected function assertUserHasClub($user, int $clubId): void
    {
        if (!in_array($clubId, $this->userClubIds($user), true)) {
            abort(403, 'Access denied.');
        }
    }
}
