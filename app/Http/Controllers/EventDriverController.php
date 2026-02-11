<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventDriver;
use Illuminate\Http\Request;

class EventDriverController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'drivers' => $event->drivers()->with(['participant', 'vehicles'])->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:event_participants,id'],
            'license_number' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = EventDriver::updateOrCreate(
            [
                'event_id' => $event->id,
                'participant_id' => $validated['participant_id'],
            ],
            [
                'license_number' => $validated['license_number'] ?? null,
            ]
        );

        return response()->json(['driver' => $driver->load(['participant', 'vehicles'])]);
    }

    public function update(Request $request, EventDriver $eventDriver)
    {
        $event = $eventDriver->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'license_number' => ['nullable', 'string', 'max:255'],
        ]);

        $eventDriver->update($validated);

        return response()->json(['driver' => $eventDriver->load(['participant', 'vehicles'])]);
    }

    public function destroy(EventDriver $eventDriver)
    {
        $event = $eventDriver->event;
        $this->authorize('update', $event);

        $eventDriver->delete();

        return response()->json(['deleted' => true]);
    }
}
