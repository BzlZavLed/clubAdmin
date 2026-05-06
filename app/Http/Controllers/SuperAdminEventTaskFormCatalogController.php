<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\EventTask;
use App\Models\EventTaskTemplate;
use App\Models\TaskFormSchema;
use App\Services\EventTaskTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SuperAdminEventTaskFormCatalogController extends Controller
{
    private const KNOWN_TASK_KEYS = [
        'camp_reservation',
        'permission_slips',
        'finalize_attendee_list',
        'transportation_plan',
        'emergency_contacts',
        'chaperone_assignments',
    ];

    private const SCHEMA_SHADOWED_TASK_KEYS = [
        'finalize_attendee_list',
        'transportation_plan',
        'permission_slips',
        'permission_slip',
    ];

    private const DOCUMENT_HANDLER_TASK_KEYS = [
        'permission_slips',
        'permission_slip',
    ];

    private const DOCUMENT_KEYWORDS = [
        'release',
        'doc',
        'slip',
        'permission',
        'medical',
        'insurance',
        'rental',
    ];

    public function __construct(
        private readonly EventTaskTemplateService $templateService,
    ) {
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'club_id' => $request->input('club_id', ''),
            'event_type' => trim((string) $request->input('event_type', '')),
        ];

        $schemas = TaskFormSchema::query()
            ->orderBy('key')
            ->get()
            ->map(fn (TaskFormSchema $schema) => $this->serializeSchema($schema))
            ->values();
        $globalSchemaKeys = $schemas->pluck('key')->all();
        $assignableSchemas = $schemas
            ->reject(fn (array $schema) => (bool) $schema['is_shadowed_by_fixed_handler'])
            ->values();

        $templateFormOptions = EventTaskTemplate::query()
            ->with(['club:id,club_name,status'])
            ->where('is_active', true)
            ->whereNotNull('form_schema_json')
            ->orderBy('event_type')
            ->orderBy('title')
            ->get()
            ->map(fn (EventTaskTemplate $template) => [
                'id' => 'template:' . $template->id,
                'source' => 'template',
                'label' => $template->title,
                'detail' => trim(($template->club?->club_name ?: 'Club #' . $template->club_id) . ' / ' . $template->event_type),
                'task_key' => $template->task_key,
                'schema_json' => $template->form_schema_json,
                'club_id' => (int) $template->club_id,
                'event_type' => $template->event_type,
                'title' => $template->title,
            ])
            ->values();

        $templates = EventTaskTemplate::query()
            ->with(['club:id,club_name,church_id,status'])
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('task_key', 'like', $search)
                        ->orWhere('event_type', 'like', $search);
                });
            })
            ->when($filters['club_id'] !== '', fn ($query) => $query->where('club_id', (int) $filters['club_id']))
            ->when($filters['event_type'] !== '', fn ($query) => $query->where('event_type', $filters['event_type']))
            ->orderByDesc('is_active')
            ->orderBy('event_type')
            ->orderBy('title')
            ->paginate(30, ['*'], 'templates_page')
            ->withQueryString()
            ->through(fn (EventTaskTemplate $template) => $this->serializeTemplate($template));

        $tasks = EventTask::query()
            ->with([
                'event:id,club_id,scope_type,scope_id,title,event_type,start_at,status',
                'event.club' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'club_name', 'church_id', 'status'),
                'formResponse:id,event_task_id,schema_key,updated_at',
                'assignments:id,event_task_id,scope_type,scope_id,status',
            ])
            ->withCount(['assignments'])
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhereHas('event', function ($eventQuery) use ($search) {
                            $eventQuery->where('title', 'like', $search)
                                ->orWhere('event_type', 'like', $search);
                        });
                });
            })
            ->when($filters['club_id'] !== '', fn ($query) => $query->whereHas('event', fn ($eventQuery) => $eventQuery->where('club_id', (int) $filters['club_id'])))
            ->when($filters['event_type'] !== '', fn ($query) => $query->whereHas('event', fn ($eventQuery) => $eventQuery->where('event_type', $filters['event_type'])))
            ->latest('updated_at')
            ->paginate(30, ['*'], 'tasks_page')
            ->withQueryString()
            ->through(fn (EventTask $task) => $this->serializeTask($task, $globalSchemaKeys));

        $eventTypes = EventTaskTemplate::query()
            ->select('event_type')
            ->whereNotNull('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->filter()
            ->values();

        return Inertia::render('SuperAdmin/EventTaskFormCatalog', [
            'schemas' => $schemas,
            'fixedHandlers' => $this->fixedHandlers(),
            'formOptions' => [
                'global' => $assignableSchemas
                    ->map(fn (array $schema) => [
                        'id' => 'global:' . $schema['id'],
                        'source' => 'global',
                        'label' => $schema['name'],
                        'detail' => $schema['key'],
                        'task_key' => $schema['key'],
                        'schema_json' => $schema['schema_json'],
                    ])
                    ->values(),
                'templates' => $templateFormOptions,
            ],
            'templates' => $templates,
            'tasks' => $tasks,
            'clubs' => Club::query()
                ->withoutGlobalScopes()
                ->select('id', 'club_name', 'status')
                ->orderBy('club_name')
                ->get(),
            'eventTypes' => $eventTypes,
            'taskKeys' => self::KNOWN_TASK_KEYS,
            'filters' => $filters,
        ]);
    }

    public function updateSchema(Request $request, TaskFormSchema $schema)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schema_json' => ['required', 'array'],
        ]);

        $schema->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schema_json' => $this->validatedFormSchema($validated['schema_json']),
        ]);

        return back()->with('success', 'Task form schema updated.');
    }

    public function storeSchema(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/', Rule::notIn(self::SCHEMA_SHADOWED_TASK_KEYS), 'unique:task_form_schemas,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schema_json' => ['required', 'array'],
        ]);

        TaskFormSchema::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schema_json' => $this->validatedFormSchema($validated['schema_json']),
        ]);

        return back()->with('success', 'Global task form schema created.');
    }

    public function updateTemplate(Request $request, EventTaskTemplate $template)
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:255'],
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('event_task_templates', 'title')
                    ->where('club_id', $template->club_id)
                    ->where('event_type', (string) $request->input('event_type'))
                    ->ignore($template->id),
            ],
            'description' => ['nullable', 'string'],
            'task_key' => ['nullable', 'string', 'max:255'],
            'form_schema_json' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);

        $template->update([
            'event_type' => $validated['event_type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'task_key' => $validated['task_key'] ?? null,
            'form_schema_json' => array_key_exists('form_schema_json', $validated) && $validated['form_schema_json']
                ? $this->validatedFormSchema($validated['form_schema_json'])
                : null,
            'is_custom' => !empty($validated['form_schema_json']) || empty($validated['task_key']),
            'is_active' => (bool) $validated['is_active'],
        ]);

        return back()->with('success', 'Event task template updated.');
    }

    public function updateTask(Request $request, EventTask $task)
    {
        $validated = $request->validate([
            'task_key' => ['nullable', 'string', 'max:255'],
            'custom_form_schema' => ['nullable', 'array'],
            'clear_custom_form' => ['nullable', 'boolean'],
        ]);

        $checklist = is_array($task->checklist_json) ? $task->checklist_json : [];
        $checklist['source'] = $checklist['source'] ?? 'event_checklist';

        $taskKey = trim((string) ($validated['task_key'] ?? ''));
        if ($taskKey !== '') {
            $checklist['task_key'] = $taskKey;
        } else {
            unset($checklist['task_key']);
        }

        if ($request->boolean('clear_custom_form')) {
            unset($checklist['custom_form_schema']);
        } elseif (array_key_exists('custom_form_schema', $validated) && $validated['custom_form_schema']) {
            $checklist['custom_form_schema'] = $this->validatedFormSchema($validated['custom_form_schema']);
        }

        $task->update(['checklist_json' => $checklist]);
        $this->templateService->syncTemplateFromTask($task);

        return back()->with('success', 'Event task form assignment updated.');
    }

    private function serializeSchema(TaskFormSchema $schema): array
    {
        $meta = $this->formSchemaSummary($schema->schema_json);
        $fixedHandler = $this->fixedHandlerForKey($schema->key);

        return [
            'id' => (int) $schema->id,
            'key' => $schema->key,
            'name' => $schema->name,
            'description' => $schema->description,
            'schema_json' => $schema->schema_json,
            'mode' => $meta['mode'],
            'field_count' => $meta['field_count'],
            'fixed_handler' => $fixedHandler,
            'is_shadowed_by_fixed_handler' => $fixedHandler !== null,
            'updated_at' => optional($schema->updated_at)->toDateTimeString(),
        ];
    }

    private function serializeTemplate(EventTaskTemplate $template): array
    {
        $meta = $this->formSchemaSummary($template->form_schema_json);

        return [
            'id' => (int) $template->id,
            'club_id' => (int) $template->club_id,
            'club_name' => $template->club?->club_name,
            'event_type' => $template->event_type,
            'title' => $template->title,
            'description' => $template->description,
            'task_key' => $template->task_key,
            'form_schema_json' => $template->form_schema_json,
            'form_mode' => $meta['mode'],
            'field_count' => $meta['field_count'],
            'is_custom' => (bool) $template->is_custom,
            'is_active' => (bool) $template->is_active,
            'updated_at' => optional($template->updated_at)->toDateTimeString(),
        ];
    }

    private function serializeTask(EventTask $task, array $globalSchemaKeys): array
    {
        $checklist = is_array($task->checklist_json) ? $task->checklist_json : [];
        $handler = $this->activeHandlerFor($task, $globalSchemaKeys);
        $meta = $this->formSchemaSummary($checklist['custom_form_schema'] ?? null);

        return [
            'id' => (int) $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'task_key' => $checklist['task_key'] ?? null,
            'source' => $checklist['source'] ?? null,
            'custom_form_schema' => $checklist['custom_form_schema'] ?? null,
            'custom_form_mode' => $meta['mode'],
            'custom_field_count' => $meta['field_count'],
            'active_handler' => $handler['handler'],
            'active_handler_label' => $handler['label'],
            'active_handler_tone' => $handler['tone'],
            'event' => $task->event ? [
                'id' => (int) $task->event->id,
                'title' => $task->event->title,
                'event_type' => $task->event->event_type,
                'scope_type' => $task->event->scope_type,
                'status' => $task->event->status,
                'start_at' => optional($task->event->start_at)->toDateString(),
            ] : null,
            'club' => $task->event?->club ? [
                'id' => (int) $task->event->club->id,
                'club_name' => $task->event->club->club_name,
                'status' => $task->event->club->status,
            ] : null,
            'status' => $task->status,
            'responsibility_level' => $task->responsibility_level ?: 'organizer',
            'assignments_count' => (int) ($task->assignments_count ?? 0),
            'has_definition_response' => (bool) $task->formResponse,
            'updated_at' => optional($task->updated_at)->toDateTimeString(),
        ];
    }

    private function activeHandlerFor(EventTask $task, array $globalSchemaKeys): array
    {
        $checklist = is_array($task->checklist_json) ? $task->checklist_json : [];
        $customSchema = $checklist['custom_form_schema'] ?? null;
        $hasCustomSchema = is_array($customSchema)
            && !empty($customSchema['fields'])
            && is_array($customSchema['fields']);

        if ($hasCustomSchema) {
            return ['handler' => 'custom_form', 'label' => 'Custom form', 'tone' => 'green'];
        }

        $taskKey = strtolower((string) ($checklist['task_key'] ?? $this->taskKeyFromTitle($task->title) ?? ''));
        $title = strtolower((string) $task->title);

        if ($taskKey === 'finalize_attendee_list' || str_contains($title, 'finalize attendee list')) {
            return ['handler' => 'participants_tab', 'label' => 'Participants tab', 'tone' => 'blue'];
        }

        if ($taskKey === 'transportation_plan') {
            return ['handler' => 'transportation_modal', 'label' => 'Transportation modal', 'tone' => 'blue'];
        }

        if (in_array($taskKey, self::DOCUMENT_HANDLER_TASK_KEYS, true)) {
            return ['handler' => 'documents_tab', 'label' => 'Documents tab', 'tone' => 'amber'];
        }

        if (in_array($taskKey, $globalSchemaKeys, true)) {
            return ['handler' => 'global_form', 'label' => 'Global form', 'tone' => 'green'];
        }

        $documentSource = $taskKey !== '' ? $taskKey : $title;
        foreach (self::DOCUMENT_KEYWORDS as $word) {
            if (str_contains($documentSource, $word)) {
                return ['handler' => 'documents_tab', 'label' => 'Documents tab', 'tone' => 'amber'];
            }
        }

        if (in_array($taskKey, self::KNOWN_TASK_KEYS, true)) {
            return ['handler' => 'missing_global_schema', 'label' => 'Missing global schema', 'tone' => 'red'];
        }

        return ['handler' => 'none', 'label' => 'No form handler', 'tone' => 'red'];
    }

    private function formSchemaSummary(?array $schema): array
    {
        if (!$schema || !is_array($schema)) {
            return ['mode' => null, 'field_count' => 0];
        }

        $fields = $schema['fields'] ?? [];

        return [
            'mode' => $schema['mode'] ?? 'single',
            'field_count' => is_array($fields) ? count($fields) : 0,
        ];
    }

    private function taskKeyFromTitle(string $title): ?string
    {
        $normalized = strtolower($title);
        $mappings = [
            ['confirm date/time with venue', 'camp_reservation'],
            ['confirm date with venue', 'camp_reservation'],
            ['confirm venue', 'camp_reservation'],
            ['venue confirmation', 'camp_reservation'],
            ['collect permission slips', 'permission_slips'],
            ['permission slips', 'permission_slips'],
            ['permission slip', 'permission_slips'],
            ['finalize attendee list', 'finalize_attendee_list'],
            ['attendee list', 'finalize_attendee_list'],
            ['arrange transportation', 'transportation_plan'],
            ['transportation', 'transportation_plan'],
            ['emergency contact list', 'emergency_contacts'],
            ['emergency contacts', 'emergency_contacts'],
            ['assign chaperones', 'chaperone_assignments'],
            ['chaperones', 'chaperone_assignments'],
        ];

        foreach ($mappings as [$needle, $key]) {
            if (str_contains($normalized, $needle)) {
                return $key;
            }
        }

        return null;
    }

    private function fixedHandlerForKey(string $key): ?array
    {
        foreach ($this->fixedHandlers() as $handler) {
            if (in_array($key, $handler['task_keys'], true)) {
                return [
                    'handler' => $handler['handler'],
                    'label' => $handler['label'],
                    'target' => $handler['target'],
                ];
            }
        }

        return null;
    }

    private function fixedHandlers(): array
    {
        return [
            [
                'handler' => 'participants_tab',
                'label' => 'Participants tab',
                'target' => 'Participantes',
                'task_keys' => ['finalize_attendee_list'],
                'keywords' => ['finalize attendee list', 'attendee list'],
                'description' => 'Abre el tab de participantes. No usa schema_json ni task_form_schemas.',
                'priority' => 'Despues de custom form; antes de formularios globales.',
            ],
            [
                'handler' => 'documents_tab',
                'label' => 'Documents tab',
                'target' => 'Documentos',
                'task_keys' => self::DOCUMENT_HANDLER_TASK_KEYS,
                'keywords' => self::DOCUMENT_KEYWORDS,
                'description' => 'Abre el tab de documentos para permisos, medical forms, insurance, rentals y tareas similares.',
                'priority' => 'Las keys permission_slips/permission_slip son fijas; otros keywords pueden ser reemplazados por custom/global form.',
            ],
            [
                'handler' => 'transportation_modal',
                'label' => 'Transportation modal',
                'target' => 'Transporte',
                'task_keys' => ['transportation_plan'],
                'keywords' => ['arrange transportation', 'transportation'],
                'description' => 'Abre el modal especializado de transporte. No usa schema_json ni task_form_schemas.',
                'priority' => 'Despues de custom form; antes de formularios globales.',
            ],
        ];
    }

    private function validatedFormSchema(array $schema): array
    {
        if (!array_key_exists('fields', $schema) || !is_array($schema['fields'])) {
            abort(422, 'Form schema must include a fields array.');
        }

        $mode = $schema['mode'] ?? 'single';
        if (!in_array($mode, ['single', 'registry'], true)) {
            abort(422, 'Form schema mode must be single or registry.');
        }

        foreach ($schema['fields'] as $field) {
            if (!is_array($field) || empty($field['key']) || empty($field['label']) || empty($field['type'])) {
                abort(422, 'Each form field must include key, label, and type.');
            }
        }

        return [
            ...$schema,
            'mode' => $mode,
        ];
    }
}
