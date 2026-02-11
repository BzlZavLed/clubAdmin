<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventDocument;
use App\Services\EventChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventDocumentController extends Controller
{
    public function __construct(private readonly EventChecklistService $checklistService)
    {
    }

    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'documents' => $event->documents()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $rawCustomParents = $request->input('custom_parents');
        if (is_string($rawCustomParents)) {
            $decoded = json_decode($rawCustomParents, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['custom_parents' => $decoded]);
            }
        }

        $validated = $request->validate([
            'doc_type' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:5120'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:staff,id'],
            'parent_ids' => ['nullable', 'array'],
            'parent_ids.*' => ['integer', 'exists:users,id'],
            'driver_participant_id' => ['nullable', 'integer', 'exists:event_participants,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:event_vehicles,id'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => ['integer', 'exists:event_participants,id'],
            'custom_parents' => ['nullable', 'array'],
            'meta_json' => ['nullable', 'array'],
        ]);

        $path = $request->file('file')->store('event-documents/' . $event->id, 'public');

        $docType = $validated['doc_type'] ?? $validated['type'] ?? 'document';
        $memberIds = $validated['member_ids'] ?? [];
        $staffIds = $validated['staff_ids'] ?? [];
        $parentIds = $validated['parent_ids'] ?? [];
        $participantIds = $validated['participant_ids'] ?? [];

        $document = EventDocument::create([
            'event_id' => $event->id,
            'type' => $docType,
            'doc_type' => $docType,
            'title' => $validated['title'],
            'path' => $path,
            'uploaded_by_user_id' => $request->user()->id,
            'member_id' => $memberIds[0] ?? null,
            'staff_id' => $staffIds[0] ?? null,
            'parent_id' => $parentIds[0] ?? null,
            'driver_participant_id' => $validated['driver_participant_id'] ?? null,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'status' => 'active',
            'meta_json' => array_filter([
                'member_ids' => $memberIds,
                'staff_ids' => $staffIds,
                'parent_ids' => $parentIds,
                'participant_ids' => $participantIds,
                'custom_parents' => $validated['custom_parents'] ?? null,
                'extra' => $validated['meta_json'] ?? null,
            ]),
        ]);

        $this->checklistService->syncPermissionSlips($event);

        return response()->json(['document' => $document]);
    }

    public function update(Request $request, EventDocument $eventDocument)
    {
        $event = $eventDocument->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'driver_participant_id' => ['nullable', 'integer', 'exists:event_participants,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:event_vehicles,id'],
        ]);

        $eventDocument->update($validated);

        return response()->json(['document' => $eventDocument]);
    }

    public function destroy(EventDocument $eventDocument)
    {
        $event = $eventDocument->event;
        $this->authorize('update', $event);

        if ($eventDocument->path) {
            Storage::disk('public')->delete($eventDocument->path);
        }

        $eventDocument->delete();

        $this->checklistService->syncPermissionSlips($event);

        return response()->json(['deleted' => true]);
    }
}
