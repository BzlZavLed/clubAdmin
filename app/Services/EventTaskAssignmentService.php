<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;
use Illuminate\Support\Collection;

class EventTaskAssignmentService
{
    public function responsibilityOptions(Event $event): array
    {
        $scopeType = (string) ($event->scope_type ?: 'club');

        return match ($scopeType) {
            'union' => [
                ['value' => 'organizer', 'label' => 'Union'],
                ['value' => 'association', 'label' => 'Asociacion'],
                ['value' => 'district', 'label' => 'Distrito'],
                ['value' => 'club', 'label' => 'Club'],
            ],
            'association' => [
                ['value' => 'organizer', 'label' => 'Asociacion'],
                ['value' => 'district', 'label' => 'Distrito'],
                ['value' => 'club', 'label' => 'Club'],
            ],
            'district' => [
                ['value' => 'organizer', 'label' => 'Distrito'],
                ['value' => 'club', 'label' => 'Club'],
            ],
            'church' => [
                ['value' => 'organizer', 'label' => 'Iglesia'],
                ['value' => 'club', 'label' => 'Club'],
            ],
            default => [
                ['value' => 'organizer', 'label' => 'Club'],
            ],
        };
    }

    public function syncAssignments(EventTask $task): void
    {
        $task->loadMissing([
            'event.targetClubs:id,club_name,district_id',
            'event.targetClubs.district:id,name,association_id',
            'event.targetClubs.district.association:id,name',
            'assignments',
        ]);

        $event = $task->event;
        if (!$event) {
            return;
        }

        $responsibilityLevel = (string) ($task->responsibility_level ?: 'organizer');
        if (($event->scope_type ?: 'club') === 'club' || $responsibilityLevel === 'organizer') {
            $task->assignments()->delete();
            return;
        }

        $rows = collect($this->assignmentRowsForTask($task));
        $keepKeys = [];

        foreach ($rows as $row) {
            $assignment = EventTaskAssignment::firstOrCreate([
                'event_task_id' => $task->id,
                'scope_type' => $row['scope_type'],
                'scope_id' => $row['scope_id'],
            ], [
                'status' => 'todo',
            ]);

            $keepKeys[] = $this->assignmentKey($assignment->scope_type, (int) $assignment->scope_id);
        }

        $task->assignments()
            ->get()
            ->each(function (EventTaskAssignment $assignment) use ($keepKeys) {
                if (!in_array($this->assignmentKey($assignment->scope_type, (int) $assignment->scope_id), $keepKeys, true)) {
                    $assignment->delete();
                }
            });
    }

    public function serializeTasksForUser(Event $event, $user): array
    {
        $event->loadMissing([
            'tasks.formResponse',
            'tasks.assignments.formResponse',
            'tasks.assignments.completedBy:id,name',
            'targetClubs:id,club_name,district_id',
            'targetClubs.district:id,name,association_id',
            'targetClubs.district.association:id,name',
        ]);

        return $event->tasks
            ->sortBy('id')
            ->flatMap(fn (EventTask $task) => $this->serializeTaskRowsForUser($task, $user))
            ->values()
            ->all();
    }

    public function serializeTaskForUser(EventTask $task, $user, ?int $assignmentId = null): ?array
    {
        $rows = $this->serializeTaskRowsForUser($task, $user);

        if ($assignmentId !== null) {
            return collect($rows)->first(fn (array $row) => (int) ($row['active_assignment_id'] ?? 0) === $assignmentId);
        }

        return $rows[0] ?? null;
    }

    public function resolveAssignmentForUser(EventTask $task, $user, ?int $assignmentId = null): ?EventTaskAssignment
    {
        $task->loadMissing([
            'event.targetClubs:id,club_name,district_id',
            'event.targetClubs.district:id,name,association_id',
            'event.targetClubs.district.association:id,name',
            'assignments.formResponse',
        ]);

        $visible = $this->visibleScopeMap($task->event, $user);

        return $task->assignments
            ->first(function (EventTaskAssignment $assignment) use ($assignmentId, $visible) {
                if ($assignmentId !== null && (int) $assignment->id !== $assignmentId) {
                    return false;
                }

                return in_array((int) $assignment->scope_id, $visible[$assignment->scope_type] ?? [], true);
            });
    }

    public function canManageDefinition($user, EventTask $task): bool
    {
        return (bool) ($user && $user->can('update', $task->event));
    }

    public function canCompleteAssignment(Event $event, $user, EventTaskAssignment $assignment): bool
    {
        $actionable = $this->actionableScopeMap($event, $user);

        return in_array((int) $assignment->scope_id, $actionable[$assignment->scope_type] ?? [], true);
    }

    public function canCompleteOrganizerTask(EventTask $task, $user): bool
    {
        return $this->canManageDefinition($user, $task)
            && (string) ($task->responsibility_level ?: 'organizer') === 'organizer';
    }

    protected function serializeTaskRowsForUser(EventTask $task, $user): array
    {
        $event = $task->event;
        $canManage = $this->canManageDefinition($user, $task);
        $responsibilityLevel = (string) ($task->responsibility_level ?: 'organizer');

        if (($event->scope_type ?: 'club') === 'club' || $canManage) {
            return [$this->serializeOrganizerRow($task, $user)];
        }

        if ($responsibilityLevel === 'organizer') {
            return [];
        }

        $visible = $this->visibleScopeMap($event, $user);
        $rows = [];

        foreach ($task->assignments as $assignment) {
            if (!in_array((int) $assignment->scope_id, $visible[$assignment->scope_type] ?? [], true)) {
                continue;
            }

            $rows[] = $this->serializeAssignmentRow($task, $user, $assignment);
        }

        return $rows;
    }

    protected function serializeOrganizerRow(EventTask $task, $user): array
    {
        $summary = $this->assignmentSummary($task);
        $responsibilityLevel = (string) ($task->responsibility_level ?: 'organizer');

        return [
            'id' => (int) $task->id,
            'instance_key' => 'task:' . $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'due_at' => optional($task->due_at)->toDateTimeString(),
            'status' => $responsibilityLevel === 'organizer' ? $task->status : $summary['status'],
            'responsibility_level' => $responsibilityLevel,
            'responsibility_label' => $this->responsibilityLabel($task->event, $responsibilityLevel),
            'checklist_json' => $task->checklist_json,
            'form_response' => $responsibilityLevel === 'organizer' ? $task->formResponse : null,
            'active_assignment_id' => null,
            'assignment_scope_type' => null,
            'assignment_scope_id' => null,
            'assignment_scope_label' => null,
            'assignment_summary' => $summary,
            'assignment_details' => $this->assignmentDetailRows($task),
            'can_manage_definition' => $this->canManageDefinition($user, $task),
            'can_complete_task' => $this->canCompleteOrganizerTask($task, $user),
            'can_edit_form_definition' => $this->canManageDefinition($user, $task),
        ];
    }

    protected function serializeAssignmentRow(EventTask $task, $user, EventTaskAssignment $assignment): array
    {
        return [
            'id' => (int) $task->id,
            'instance_key' => 'task:' . $task->id . ':assignment:' . $assignment->id,
            'title' => $task->title,
            'description' => $task->description,
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'due_at' => optional($task->due_at)->toDateTimeString(),
            'status' => $assignment->status,
            'responsibility_level' => (string) ($task->responsibility_level ?: 'organizer'),
            'responsibility_label' => $this->responsibilityLabel($task->event, (string) ($task->responsibility_level ?: 'organizer')),
            'checklist_json' => $task->checklist_json,
            'form_response' => $assignment->formResponse,
            'active_assignment_id' => (int) $assignment->id,
            'assignment_scope_type' => $assignment->scope_type,
            'assignment_scope_id' => (int) $assignment->scope_id,
            'assignment_scope_label' => $this->scopeLabelForAssignment($task->event, $assignment),
            'assignment_summary' => null,
            'assignment_details' => [],
            'can_manage_definition' => false,
            'can_complete_task' => $this->canCompleteAssignment($task->event, $user, $assignment),
            'can_edit_form_definition' => false,
        ];
    }

    protected function assignmentSummary(EventTask $task): array
    {
        $assignments = $task->assignments ?? collect();
        $done = $assignments->where('status', 'done')->count();
        $total = $assignments->count();

        $status = 'todo';
        if ($total > 0 && $done === $total) {
            $status = 'done';
        } elseif ($done > 0) {
            $status = 'in_progress';
        }

        return [
            'total' => $total,
            'done' => $done,
            'pending' => max($total - $done, 0),
            'status' => $status,
        ];
    }

    protected function assignmentDetailRows(EventTask $task): array
    {
        $task->loadMissing([
            'event.targetClubs:id,club_name,district_id',
            'event.targetClubs.district:id,name,association_id',
            'event.targetClubs.district.association:id,name',
            'assignments.formResponse',
        ]);

        return ($task->assignments ?? collect())
            ->map(function (EventTaskAssignment $assignment) use ($task) {
                $club = null;
                $district = null;
                $association = null;

                if ($assignment->scope_type === 'club') {
                    $club = $task->event->targetClubs->firstWhere('id', (int) $assignment->scope_id);
                    $district = $club?->district;
                    $association = $district?->association;
                } elseif ($assignment->scope_type === 'district') {
                    $club = $task->event->targetClubs->first(fn (Club $item) => (int) $item->district_id === (int) $assignment->scope_id);
                    $district = $club?->district;
                    $association = $district?->association;
                } elseif ($assignment->scope_type === 'association') {
                    $club = $task->event->targetClubs->first(fn (Club $item) => (int) ($item->district?->association_id ?? 0) === (int) $assignment->scope_id);
                    $district = $club?->district;
                    $association = $district?->association;
                }

                return [
                    'id' => (int) $assignment->id,
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => (int) $assignment->scope_id,
                    'scope_label' => $this->scopeLabelForAssignment($task->event, $assignment),
                    'status' => $assignment->status,
                    'completed_at' => optional($assignment->completed_at)->toDateTimeString(),
                    'association_id' => (int) ($association?->id ?? 0),
                    'association_name' => $association?->name,
                    'district_id' => (int) ($district?->id ?? 0),
                    'district_name' => $district?->name,
                    'club_id' => (int) ($club?->id ?? 0),
                    'club_name' => $club?->club_name,
                ];
            })
            ->values()
            ->all();
    }

    protected function assignmentRowsForTask(EventTask $task): array
    {
        $event = $task->event;
        $targetClubs = $event->targetClubs;
        $responsibilityLevel = (string) ($task->responsibility_level ?: 'organizer');

        if ($responsibilityLevel === 'club') {
            return $targetClubs
                ->map(fn (Club $club) => [
                    'scope_type' => 'club',
                    'scope_id' => (int) $club->id,
                ])
                ->unique(fn (array $row) => $this->assignmentKey($row['scope_type'], $row['scope_id']))
                ->values()
                ->all();
        }

        if ($responsibilityLevel === 'district') {
            return $targetClubs
                ->filter(fn (Club $club) => !empty($club->district_id))
                ->map(fn (Club $club) => [
                    'scope_type' => 'district',
                    'scope_id' => (int) $club->district_id,
                ])
                ->unique(fn (array $row) => $this->assignmentKey($row['scope_type'], $row['scope_id']))
                ->values()
                ->all();
        }

        if ($responsibilityLevel === 'association') {
            return $targetClubs
                ->filter(fn (Club $club) => !empty($club->district?->association_id))
                ->map(fn (Club $club) => [
                    'scope_type' => 'association',
                    'scope_id' => (int) $club->district->association_id,
                ])
                ->unique(fn (array $row) => $this->assignmentKey($row['scope_type'], $row['scope_id']))
                ->values()
                ->all();
        }

        return [];
    }

    protected function visibleScopeMap(Event $event, $user): array
    {
        $clubs = $event->targetClubs;
        $role = $this->effectiveRole($user);
        $context = $this->effectiveContext($user);

        $empty = [
            'club' => [],
            'district' => [],
            'association' => [],
            'union' => [],
        ];

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return [
                'club' => $clubs->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'district' => $clubs->pluck('district_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'association' => $clubs->pluck('district.association_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'union' => [],
            ];
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $clubIds = ($user->profile_type ?? null) === 'superadmin'
                ? collect([(int) ($context['club_id'] ?? 0)])->filter()->values()->all()
                : ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->all();

            return [
                ...$empty,
                'club' => collect($clubs->pluck('id')->all())->map(fn ($id) => (int) $id)->intersect($clubIds)->values()->all(),
            ];
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            $districtId = (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id);

            return [
                ...$empty,
                'district' => $clubs->pluck('district_id')->filter(fn ($id) => (int) $id === $districtId)->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'club' => $clubs->filter(fn (Club $club) => (int) $club->district_id === $districtId)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        if ($role === 'association_youth_director') {
            $associationId = (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id);

            return [
                ...$empty,
                'association' => $clubs->pluck('district.association_id')->filter(fn ($id) => (int) $id === $associationId)->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'district' => $clubs->filter(fn (Club $club) => (int) ($club->district?->association_id ?? 0) === $associationId)->pluck('district_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'club' => $clubs->filter(fn (Club $club) => (int) ($club->district?->association_id ?? 0) === $associationId)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        return $empty;
    }

    protected function actionableScopeMap(Event $event, $user): array
    {
        $role = $this->effectiveRole($user);
        $context = $this->effectiveContext($user);
        $empty = [
            'club' => [],
            'district' => [],
            'association' => [],
            'union' => [],
        ];

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return $empty;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            return [
                ...$empty,
                'club' => ($user->profile_type ?? null) === 'superadmin'
                    ? collect([(int) ($context['club_id'] ?? 0)])->filter()->values()->all()
                    : ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->all(),
            ];
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return [
                ...$empty,
                'district' => [(int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id)],
            ];
        }

        if ($role === 'association_youth_director') {
            return [
                ...$empty,
                'association' => [(int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id)],
            ];
        }

        if ($role === 'union_youth_director') {
            return [
                ...$empty,
                'union' => [(int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id)],
            ];
        }

        return $empty;
    }

    protected function effectiveRole($user): string
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return ClubHelper::roleKey($user);
        }

        return (string) (SuperadminContext::fromSession()['role'] ?? 'superadmin');
    }

    protected function effectiveContext($user): array
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return [];
        }

        return SuperadminContext::fromSession();
    }

    protected function responsibilityLabel(Event $event, string $level): string
    {
        return collect($this->responsibilityOptions($event))
            ->firstWhere('value', $level)['label'] ?? ucfirst($level);
    }

    protected function scopeLabelForAssignment(Event $event, EventTaskAssignment $assignment): string
    {
        $clubs = $event->targetClubs;

        return match ($assignment->scope_type) {
            'club' => (string) optional($clubs->firstWhere('id', (int) $assignment->scope_id))->club_name,
            'district' => (string) optional($clubs->first(fn (Club $club) => (int) $club->district_id === (int) $assignment->scope_id)?->district)->name,
            'association' => (string) optional($clubs->first(fn (Club $club) => (int) ($club->district?->association_id ?? 0) === (int) $assignment->scope_id)?->district?->association)->name,
            default => ucfirst($assignment->scope_type) . ' #' . $assignment->scope_id,
        };
    }

    protected function assignmentKey(string $scopeType, int $scopeId): string
    {
        return $scopeType . ':' . $scopeId;
    }
}
