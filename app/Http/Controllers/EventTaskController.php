<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Http\Request;

class EventTaskController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'tasks' => $event->tasks()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'checklist_json' => ['nullable', 'array'],
        ]);

        $task = EventTask::create([
            'event_id' => $event->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'status' => $validated['status'] ?? 'todo',
            'checklist_json' => $validated['checklist_json'] ?? null,
        ]);

        return response()->json(['task' => $task]);
    }

    public function update(Request $request, EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'checklist_json' => ['nullable', 'array'],
        ]);

        $eventTask->update($validated);

        return response()->json(['task' => $eventTask]);
    }

    public function destroy(EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $eventTask->delete();

        return response()->json(['deleted' => true]);
    }
}
