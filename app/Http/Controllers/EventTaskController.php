<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventTask;
use App\Services\EventTaskAssignmentService;
use App\Services\EventTaskTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventTaskController extends Controller
{
    public function __construct(
        private readonly EventTaskTemplateService $templateService,
        private readonly EventTaskAssignmentService $assignmentService,
    ) {
    }

    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'tasks' => $this->assignmentService->serializeTasksForUser($event, request()->user()),
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
            'responsibility_level' => ['nullable', Rule::in(array_column($this->assignmentService->responsibilityOptions($event), 'value'))],
            'checklist_json' => ['nullable', 'array'],
        ]);

        $task = EventTask::create([
            'event_id' => $event->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'status' => $validated['status'] ?? 'todo',
            'responsibility_level' => $validated['responsibility_level'] ?? 'organizer',
            'checklist_json' => $validated['checklist_json'] ?? null,
        ]);

        $this->templateService->syncTemplateFromTask($task);
        $this->assignmentService->syncAssignments($task);

        return response()->json([
            'task' => $this->assignmentService->serializeTaskForUser($task->fresh(['event.targetClubs.district.association', 'formResponse', 'assignments.formResponse']), $request->user()),
        ]);
    }

    public function update(Request $request, EventTask $eventTask)
    {
        $event = $eventTask->event;
        $assignmentId = $request->integer('assignment_id') ?: null;
        $canManageDefinition = $this->assignmentService->canManageDefinition($request->user(), $eventTask);

        if ($canManageDefinition) {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'due_at' => ['nullable', 'date'],
                'status' => ['nullable', 'string'],
                'responsibility_level' => ['nullable', Rule::in(array_column($this->assignmentService->responsibilityOptions($event), 'value'))],
                'checklist_json' => ['nullable', 'array'],
            ]);

            $eventTask->update($validated);
            $this->templateService->syncTemplateFromTask($eventTask);
            $this->assignmentService->syncAssignments($eventTask);

            return response()->json([
                'task' => $this->assignmentService->serializeTaskForUser(
                    $eventTask->fresh(['event.targetClubs.district.association', 'formResponse', 'assignments.formResponse']),
                    $request->user(),
                    $assignmentId
                ),
            ]);
        }

        $this->authorize('view', $event);
        $assignment = $this->assignmentService->resolveAssignmentForUser($eventTask, $request->user(), $assignmentId);
        abort_unless($assignment, 404);
        abort_unless($this->assignmentService->canCompleteAssignment($event, $request->user(), $assignment), 403);

        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $status = (string) $validated['status'];
        $assignment->update([
            'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
            'completed_by_user_id' => $status === 'done' ? $request->user()->id : null,
        ]);

        return response()->json([
            'task' => $this->assignmentService->serializeTaskForUser(
                $eventTask->fresh(['event.targetClubs.district.association', 'formResponse', 'assignments.formResponse']),
                $request->user(),
                $assignment->id
            ),
        ]);
    }

    public function destroy(EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $eventTask->delete();

        return response()->json(['deleted' => true]);
    }
}
