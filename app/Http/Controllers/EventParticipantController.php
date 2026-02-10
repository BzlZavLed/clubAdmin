<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use Illuminate\Http\Request;

class EventParticipantController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'participants' => $event->participants()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'participant_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'permission_received' => ['nullable', 'boolean'],
            'medical_form_received' => ['nullable', 'boolean'],
            'emergency_contact_json' => ['nullable', 'array'],
        ]);

        $participant = EventParticipant::create([
            'event_id' => $event->id,
            'member_id' => $validated['member_id'] ?? null,
            'participant_name' => $validated['participant_name'],
            'role' => $validated['role'],
            'status' => $validated['status'],
            'permission_received' => $validated['permission_received'] ?? false,
            'medical_form_received' => $validated['medical_form_received'] ?? false,
            'emergency_contact_json' => $validated['emergency_contact_json'] ?? null,
        ]);

        return response()->json(['participant' => $participant]);
    }

    public function update(Request $request, EventParticipant $eventParticipant)
    {
        $event = $eventParticipant->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'participant_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'permission_received' => ['nullable', 'boolean'],
            'medical_form_received' => ['nullable', 'boolean'],
            'emergency_contact_json' => ['nullable', 'array'],
        ]);

        $eventParticipant->update($validated);

        return response()->json(['participant' => $eventParticipant]);
    }

    public function destroy(EventParticipant $eventParticipant)
    {
        $event = $eventParticipant->event;
        $this->authorize('update', $event);

        $eventParticipant->delete();

        return response()->json(['deleted' => true]);
    }
}
