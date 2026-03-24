<?php

namespace App\Services;

use App\Models\Event;
use App\Models\AiRequestLog;
use App\Models\EventTask;
use App\Models\EventTaskTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EventTaskTemplateService
{
    public const SEEDED_TASK_VERSION = 'ai-first-v2';

    public function __construct(
        private AiClient $aiClient,
    ) {
    }

    public function seedEventTasks(Event $event): array
    {
        $existingSeededTasks = $event->tasks()
            ->get(['id', 'title', 'checklist_json'])
            ->filter(function ($task) {
                $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
                return ($meta['source'] ?? null) === 'event_type_template';
            })
            ->values();

        if ($existingSeededTasks->contains(function ($task) {
            $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
            return ($meta['seed_version'] ?? null) === self::SEEDED_TASK_VERSION;
        })) {
            return $existingSeededTasks->all();
        }

        $archetype = $this->inferEventArchetype($event->event_type, (string) $event->title);
        $storedTemplates = $this->templatesForEventType($event->club_id, $event->event_type);
        $dbTemplateThreshold = max(1, (int) config('ai.event_task_template_db_threshold', 8));
        $shouldPreferStoredTemplates = $storedTemplates->count() >= $dbTemplateThreshold
            && !$this->shouldRefreshTemplates($storedTemplates, $archetype);

        if ($shouldPreferStoredTemplates) {
            $templates = $storedTemplates;
        } else {
            // Learning mode: use AI first until the club has enough stored
            // templates for this event type to make DB reuse reliable.
            $templates = $this->generateTemplatesWithAiForEvent($event, $archetype);
        }

        if ($templates->isEmpty()) {
            $templates = $storedTemplates->isNotEmpty()
                ? $storedTemplates
                : $this->templatesForEventType($event->club_id, $event->event_type);
        }

        if ($this->shouldRefreshTemplates($templates, $archetype)) {
            EventTaskTemplate::query()
                ->where('club_id', $event->club_id)
                ->where('event_type', $event->event_type)
                ->where('is_custom', false)
                ->update(['is_active' => false]);

            $templates = $this->generateTemplatesWithAiForEvent($event, $archetype);
        }

        if ($templates->isEmpty()) {
            $templates = $this->buildDefaultTemplates($event->event_type, (string) $event->title);
        }

        $templates = collect($templates)
            ->unique(function ($template) {
                $title = is_array($template) ? ($template['title'] ?? '') : ($template->title ?? '');
                return mb_strtolower(trim((string) $title));
            })
            ->values();

        foreach ($templates as $template) {
            EventTaskTemplate::updateOrCreate(
                [
                    'club_id' => $event->club_id,
                    'event_type' => $event->event_type,
                    'title' => $template['title'],
                ],
                [
                    'description' => $template['description'] ?? null,
                    'task_key' => $template['task_key'] ?? null,
                    'form_schema_json' => $template['form_schema_json'] ?? null,
                    'is_custom' => false,
                    'is_active' => true,
                ]
            );
        }

        $createdTasks = [];
        foreach ($templates as $template) {
            $checklist = [
                'source' => 'event_type_template',
                'seed_version' => self::SEEDED_TASK_VERSION,
            ];
            $templateTitle = is_array($template) ? ($template['title'] ?? '') : ($template->title ?? '');
            $templateDescription = is_array($template) ? ($template['description'] ?? null) : ($template->description ?? null);
            $templateTaskKey = is_array($template) ? ($template['task_key'] ?? null) : ($template->task_key ?? null);
            $templateFormSchema = is_array($template) ? ($template['form_schema_json'] ?? null) : ($template->form_schema_json ?? null);

            $title = strtolower((string) $templateTitle);
            $skipVenueFormTaskKey = str_contains($title, 'campsite reservation confirmed');
            if (!empty($templateTaskKey) && !$skipVenueFormTaskKey) {
                $checklist['task_key'] = $templateTaskKey;
            }
            if (!empty($templateFormSchema)) {
                $checklist['custom_form_schema'] = $templateFormSchema;
            }

            $createdTasks[] = EventTask::create([
                'event_id' => $event->id,
                'title' => $templateTitle,
                'description' => $templateDescription,
                'status' => 'todo',
                'checklist_json' => $checklist,
            ]);
        }

        return $createdTasks;
    }

    public function reseedEventTasksIfSafe(Event $event): bool
    {
        $seededTasks = $event->tasks()
            ->get(['id', 'title', 'status', 'checklist_json']);

        $seededTemplateTasks = $seededTasks->filter(function ($task) {
            $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
            return ($meta['source'] ?? null) === 'event_type_template';
        })->values();

        if ($seededTemplateTasks->isEmpty()) {
            return false;
        }

        $this->dedupeSeededTasksByTitle($seededTemplateTasks);
        $seededTemplateTasks = $event->tasks()
            ->get(['id', 'title', 'status', 'checklist_json'])
            ->filter(function ($task) {
                $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
                return ($meta['source'] ?? null) === 'event_type_template';
            })
            ->values();

        $hasCurrentVersion = $seededTemplateTasks->contains(function ($task) {
            $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
            return ($meta['seed_version'] ?? null) === self::SEEDED_TASK_VERSION;
        });

        $hasCompletedSeededTask = $seededTemplateTasks->contains(function ($task) {
            return strtolower((string) $task->status) === 'done';
        });

        if ($hasCompletedSeededTask) {
            return false;
        }

        if ($hasCurrentVersion) {
            return false;
        }

        foreach ($seededTemplateTasks as $task) {
            $task->delete();
        }

        $this->seedEventTasks($event);

        return true;
    }

    protected function dedupeSeededTasksByTitle(Collection $tasks): void
    {
        $tasks
            ->groupBy(fn ($task) => mb_strtolower(trim((string) $task->title)))
            ->each(function (Collection $group) {
                if ($group->count() <= 1) {
                    return;
                }

                $keep = $group->sortByDesc(function ($task) {
                    $meta = is_array($task->checklist_json) ? $task->checklist_json : [];
                    $hasCustomSchema = is_array($meta['custom_form_schema'] ?? null)
                        && !empty(($meta['custom_form_schema']['fields'] ?? []));

                    return sprintf(
                        '%d-%d-%d',
                        $hasCustomSchema ? 1 : 0,
                        strtolower((string) $task->status) === 'done' ? 1 : 0,
                        (int) $task->id
                    );
                })->first();

                $group->each(function ($task) use ($keep) {
                    if ((int) $task->id !== (int) $keep->id) {
                        $task->delete();
                    }
                });
            });
    }

    public function syncTemplateFromTask(EventTask $task): void
    {
        $event = $task->event;
        if (!$event) {
            return;
        }

        $checklist = $task->checklist_json ?? [];
        $customSchema = is_array($checklist['custom_form_schema'] ?? null)
            ? $checklist['custom_form_schema']
            : null;
        $taskKey = $checklist['task_key'] ?? $this->taskKeyFromTitle($task->title);
        if (str_contains(strtolower((string) $task->title), 'campsite reservation confirmed')) {
            $taskKey = null;
        }

        EventTaskTemplate::updateOrCreate(
            [
                'club_id' => $event->club_id,
                'event_type' => $event->event_type,
                'title' => $task->title,
            ],
            [
                'description' => $task->description,
                'task_key' => $taskKey,
                'form_schema_json' => $customSchema,
                'is_custom' => $customSchema !== null || empty($taskKey),
                'is_active' => true,
            ]
        );
    }

    public function templatesForEventType(int $clubId, string $eventType): Collection
    {
        return EventTaskTemplate::query()
            ->where('club_id', $clubId)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    protected function taskKeyFromTitle(string $title): ?string
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

    protected function generateTemplatesWithAi(string $eventType, ?string $eventTitle = null, ?string $archetype = null): Collection
    {
        try {
            $payload = [
                'model' => config('ai.model'),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => 'You generate event planning task checklists. Return valid JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildAiTaskPrompt($eventType, $eventTitle, $archetype),
                    ],
                ],
                'tool_choice' => 'none',
                'max_output_tokens' => min(1000, max(350, (int) config('ai.max_output_tokens'))),
            ];

            $response = $this->aiClient->responses($payload);

            $rawText = $this->extractResponseText($response);
            if (!$rawText) {
                return collect();
            }

            $decoded = $this->decodeJsonPayload($rawText);
            if (!$decoded) {
                return collect();
            }

            $tasks = $decoded['tasks'] ?? null;
            if (!is_array($tasks) || empty($tasks)) {
                return collect();
            }

            return $this->normalizeAiTasks($tasks, $archetype);
        } catch (Throwable $e) {
            report($e);
            return collect();
        }
    }

    protected function generateTemplatesWithAiForEvent(Event $event, ?string $archetype = null): Collection
    {
        $archetype = $archetype ?: $this->inferEventArchetype($event->event_type, (string) $event->title);
        $payload = [
            'model' => config('ai.model'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You generate event planning task checklists. Return valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildAiTaskPromptForEvent($event, $archetype),
                ],
            ],
            'tool_choice' => 'none',
            'max_output_tokens' => min(1000, max(350, (int) config('ai.max_output_tokens'))),
        ];

        $user = null;
        if (!empty($event->created_by_user_id)) {
            $user = User::find($event->created_by_user_id);
        }

        $start = microtime(true);
        $log = AiRequestLog::create([
            'event_id' => $event->id,
            'club_id' => $event->club_id,
            'user_id' => $user?->id,
            'provider' => config('ai.provider'),
            'model' => $payload['model'] ?? config('ai.model'),
            'request_json' => [
                'source' => 'event_task_seed',
                'endpoint' => rtrim(config('ai.base_url'), '/') . '/responses',
                'payload' => $payload,
                'event_type' => $event->event_type,
                'event_title' => $event->title,
                'event_description' => $event->description,
                'archetype' => $archetype,
            ],
            'status' => 'pending',
        ]);

        try {
            $response = $this->aiClient->responses($payload);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $usage = $response['usage'] ?? [];

            $log->update([
                'response_json' => $response,
                'latency_ms' => $latency,
                'status' => 'success',
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]);

            $rawText = $this->extractResponseText($response);
            if (!$rawText) {
                return collect();
            }

            $decoded = $this->decodeJsonPayload($rawText);
            if (!$decoded) {
                return collect();
            }

            $tasks = $decoded['tasks'] ?? null;
            if (!is_array($tasks) || empty($tasks)) {
                return collect();
            }

            return $this->normalizeAiTasks($tasks, $archetype);
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $log->update([
                'latency_ms' => $latency,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            report($e);
            return collect();
        }
    }

    protected function buildAiTaskPrompt(string $eventType, ?string $eventTitle = null, ?string $archetype = null): string
    {
        $eventTypeLabel = trim(str_replace('_', ' ', strtolower($eventType)));
        $titlePart = $eventTitle ? " Event title: {$eventTitle}." : '';
        $archetype = $archetype ?: $this->inferEventArchetype($eventType, (string) $eventTitle);

        $domainGuidance = match ($archetype) {
            'sports_tournament' => 'This is a sports tournament. Focus on registration, teams, brackets/schedule, field/court booking, referees, equipment, hydration/medical support, scoring/results, announcements, awards, and volunteer assignments. Do not include permission slips, transportation, or chaperones unless the event clearly implies travel or minors leaving the regular meeting location.',
            'fundraiser' => 'This is a fundraiser. Focus on pricing, sales workflow, promotion, inventory, volunteers, payment collection, reconciliation, and cleanup.',
            'museum_trip' => 'This is a field trip. Include tickets, transportation, permission slips, supervision, timing, and venue rules.',
            'camp' => 'This is a camp-style outing. Include permission slips, transportation, lodging/campsite, supervision, meals, first aid, and emergency prep.',
            default => 'Only include tasks that are directly relevant to the event type. Do not force transportation, permission slips, chaperones, or venue reservation unless they are actually implied.',
        };

        return "I am creating a club event and I need you to work as an event planner. "
            . "Event type: {$eventTypeLabel}.{$titlePart} "
            . "Generate the series of concrete planning tasks I need in order to run this event properly. "
            . $domainGuidance . ' '
            . 'Infer likely intent from the event title if it adds context, for example if the event is for fundraising, competition, outreach, worship, or recreation. '
            . 'Do not force generic camp/trip tasks unless the event clearly requires them. '
            . 'Prefer event-specific operational tasks over generic placeholders. '
            . 'Return JSON object with this exact shape: '
            . '{"tasks":[{"title":"string","description":"string|null","task_key":"string|null","form_schema_json":{"fields":[{"key":"string","label":"string","type":"text|textarea|number|date|select|checkbox","required":"boolean","help":"string|null","options":["string"]|null}]}|null}]}. '
            . 'Rules: 8-12 tasks, no duplicates, concise titles, null description when unnecessary, task_key optional. '
            . 'Only include form_schema_json for tasks that truly require structured data capture. '
            . 'At most 4 tasks may include form_schema_json. Each form may have at most 4 fields. Keep forms minimal.';
    }

    protected function buildAiTaskPromptForEvent(Event $event, ?string $archetype = null): string
    {
        $eventTypeLabel = trim(str_replace('_', ' ', strtolower((string) $event->event_type)));
        $titlePart = $event->title ? " Event title: {$event->title}." : '';
        $description = trim((string) ($event->description ?? ''));
        $descriptionPart = $description !== ''
            ? " Event description: {$description}."
            : '';
        $archetype = $archetype ?: $this->inferEventArchetype((string) $event->event_type, (string) $event->title);

        $domainGuidance = match ($archetype) {
            'sports_tournament' => 'This is a sports tournament. Focus on registration, teams, brackets or schedule, field or court setup, referees, scorekeeping, equipment, hydration, safety, awards, promotion, and fundraising operations when relevant.',
            'fundraiser' => 'This is a fundraiser. Focus on pricing, sales workflow, promotion, inventory, volunteers, payment collection, reconciliation, and cleanup.',
            'museum_trip' => 'This is a field trip. Include tickets, transportation, permission slips, supervision, timing, and venue rules.',
            'camp' => 'This is a camp-style outing. Include permission slips, transportation, lodging or campsite, supervision, meals, first aid, and emergency prep.',
            default => 'Only include tasks that are directly relevant to the event type and description. Do not force transportation, permission slips, chaperones, or venue reservation unless they are actually implied.',
        };

        return "I am creating a club event and I need you to work as an event planner. "
            . "Event type: {$eventTypeLabel}.{$titlePart}{$descriptionPart} "
            . "Generate the concrete planning tasks I need in order to run this event properly. "
            . $domainGuidance . ' '
            . 'Use the event description as real context, not as decoration. '
            . 'Infer likely intent from the title and description, including whether the event is for fundraising, competition, outreach, worship, recreation, or logistics. '
            . 'Do not force generic camp or trip tasks unless the event clearly requires them. '
            . 'Prefer event-specific operational tasks over generic placeholders. '
            . 'Return JSON object with this exact shape: '
            . '{"tasks":[{"title":"string","description":"string|null","task_key":"string|null","form_schema_json":{"fields":[{"key":"string","label":"string","type":"text|textarea|number|date|select|checkbox","required":"boolean","help":"string|null","options":["string"]|null}]}|null}]}. '
            . 'Rules: 8-12 tasks, no duplicates, concise titles, null description when unnecessary, task_key optional. '
            . 'Only include form_schema_json for tasks that truly require structured data capture. '
            . 'At most 4 tasks may include form_schema_json. Each form may have at most 4 fields. Keep forms minimal.';
    }

    protected function extractResponseText(array $response): ?string
    {
        $output = $response['output'] ?? [];
        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant') {
                continue;
            }
            $content = $item['content'] ?? [];
            if (is_string($content)) {
                return trim($content);
            }
            if (!is_array($content)) {
                continue;
            }
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            if ($text !== '') {
                return trim($text);
            }
        }

        $fallback = $response['output_text'] ?? null;
        return is_string($fallback) ? trim($fallback) : null;
    }

    protected function decodeJsonPayload(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```json\s*(\{.*\})\s*```/is', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{.*\})/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function normalizeAiTasks(array $tasks, ?string $archetype = null): Collection
    {
        $knownTaskKeys = [
            'camp_reservation',
            'permission_slips',
            'finalize_attendee_list',
            'transportation_plan',
            'emergency_contacts',
            'chaperone_assignments',
        ];
        $customFormsUsed = 0;

        $seen = [];
        $normalized = [];
        foreach ($tasks as $task) {
            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $title = Str::limit($title, 255, '');
            $titleKey = mb_strtolower($title);
            if (isset($seen[$titleKey])) {
                continue;
            }
            $seen[$titleKey] = true;

            $description = $task['description'] ?? null;
            $description = is_string($description) ? trim($description) : null;
            if ($description === '') {
                $description = null;
            }

            $taskKey = $task['task_key'] ?? null;
            $taskKey = is_string($taskKey) ? trim($taskKey) : null;
            if ($taskKey === '' || !in_array($taskKey, $knownTaskKeys, true)) {
                $taskKey = $this->taskKeyFromTitle($title);
            }
            if (str_contains(mb_strtolower($title), 'campsite reservation confirmed')) {
                $taskKey = null;
            }

            $formSchema = null;
            if ($customFormsUsed < 4) {
                $formSchema = $this->sanitizeAiFormSchema($task['form_schema_json'] ?? null);
                if (is_array($formSchema) && !empty($formSchema['fields'])) {
                    $customFormsUsed++;
                } else {
                    $formSchema = null;
                }
            }

            $normalized[] = [
                'title' => $title,
                'description' => $description,
                'task_key' => $taskKey,
                'form_schema_json' => $formSchema,
            ];
        }

        $normalized = $this->filterTasksForArchetype(collect($normalized), $archetype)->values()->all();

        if (count($normalized) < 6) {
            throw new RuntimeException('AI returned an insufficient task list.');
        }

        return collect(array_slice($normalized, 0, 20));
    }

    protected function sanitizeAiFormSchema(mixed $schema): ?array
    {
        if (!is_array($schema)) {
            return null;
        }

        $fields = $schema['fields'] ?? null;
        if (!is_array($fields) || empty($fields)) {
            return null;
        }

        $allowedTypes = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];
        $normalized = [];
        $seenKeys = [];

        foreach (array_slice($fields, 0, 4) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $label = Str::limit($label, 80, '');

            $keySource = trim((string) ($field['key'] ?? $label));
            $key = Str::of($keySource)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->limit(40, '')
                ->value();

            if ($key === '' || isset($seenKeys[$key])) {
                continue;
            }

            $type = trim((string) ($field['type'] ?? 'text'));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $help = isset($field['help']) ? trim((string) $field['help']) : null;
            $help = $help !== '' ? Str::limit($help, 120, '') : null;

            $options = null;
            if ($type === 'select') {
                $rawOptions = $field['options'] ?? [];
                if (is_array($rawOptions)) {
                    $options = collect($rawOptions)
                        ->map(fn ($option) => trim((string) $option))
                        ->filter()
                        ->unique()
                        ->take(6)
                        ->map(fn ($option) => Str::limit($option, 60, ''))
                        ->values()
                        ->all();
                }
                if (empty($options)) {
                    continue;
                }
            }

            $normalized[] = array_filter([
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'help' => $help,
                'options' => $options,
            ], fn ($value) => $value !== null);

            $seenKeys[$key] = true;
        }

        if (empty($normalized)) {
            return null;
        }

        return ['fields' => $normalized];
    }

    protected function buildDefaultTemplates(string $eventType, ?string $eventTitle = null): Collection
    {
        $base = [
            'Confirm date/time with venue',
            'Finalize attendee list',
            'Collect permission slips',
            'Assign chaperones/staff',
            'Arrange transportation',
            'Emergency contact list ready',
        ];

        $generic = [
            'Confirm event date/time',
            'Finalize attendee or participant list',
            'Assign staff and volunteer responsibilities',
            'Prepare communication reminders',
            'Confirm safety and first aid coverage',
            'Finalize budget and supplies',
        ];

        $sportsTournament = [
            'Confirm tournament format and rules',
            'Open team registration',
            'Finalize team roster list',
            'Publish match schedule',
            'Confirm field or court availability',
            'Assign referees and scorekeepers',
            'Prepare equipment and uniforms',
            'Set up hydration and first aid station',
            'Confirm check-in table and signage',
            'Prepare awards or recognition',
        ];

        $titles = match ($this->inferEventArchetype($eventType, (string) $eventTitle)) {
            'camp' => array_merge($base, [
                'Campsite reservation confirmed',
                'Tent/cabin assignments',
                'Meal plan & supplies list',
                'First aid kit & medical forms',
                'Weather contingency plan',
            ]),
            'fundraiser' => array_merge($base, [
                'Fundraising goal defined',
                'Pricing & payment plan',
                'Promotion plan',
                'Cash handling plan',
            ]),
            'museum_trip' => array_merge($base, [
                'Tickets purchased or held',
                'Museum rules shared',
                'Docent/tour scheduled',
            ]),
            'sports_outing' => array_merge($base, [
                'Facility reservation',
                'Equipment checklist',
                'Waivers/insurance confirmed',
            ]),
            'sports_tournament' => $sportsTournament,
            'general' => $generic,
            default => $generic,
        };

        return collect($titles)->map(function (string $title) {
            return [
                'title' => $title,
                'description' => null,
                'task_key' => $this->taskKeyFromTitle($title),
                'form_schema_json' => null,
            ];
        });
    }

    protected function inferEventArchetype(string $eventType, string $eventTitle = ''): string
    {
        $text = mb_strtolower(trim($eventType . ' ' . $eventTitle));

        if (str_contains($text, 'camp')) {
            return 'camp';
        }

        if (
            str_contains($text, 'soccer')
            || str_contains($text, 'football')
            || str_contains($text, 'basketball')
            || str_contains($text, 'baseball')
            || str_contains($text, 'volleyball')
            || str_contains($text, 'tournament')
            || str_contains($text, 'torneo')
            || str_contains($text, 'league')
            || str_contains($text, 'match')
            || str_contains($text, 'game day')
        ) {
            return 'sports_tournament';
        }

        if (str_contains($text, 'fundraiser') || str_contains($text, 'recaud')) {
            return 'fundraiser';
        }

        if (str_contains($text, 'museum')) {
            return 'museum_trip';
        }

        if (str_contains($text, 'sports_outing')) {
            return 'sports_outing';
        }

        return 'general';
    }

    protected function shouldRefreshTemplates(Collection $templates, string $archetype): bool
    {
        if ($templates->isEmpty() || $archetype !== 'sports_tournament') {
            return false;
        }

        $titles = $templates->pluck('title')
            ->filter()
            ->map(fn ($title) => mb_strtolower((string) $title));

        $sportsHits = $titles->filter(fn ($title) => str_contains($title, 'team')
            || str_contains($title, 'match')
            || str_contains($title, 'schedule')
            || str_contains($title, 'referee')
            || str_contains($title, 'field')
            || str_contains($title, 'court')
            || str_contains($title, 'tournament')
            || str_contains($title, 'equipment')
            || str_contains($title, 'score'))->count();

        $campHits = $titles->filter(fn ($title) => str_contains($title, 'permission slip')
            || str_contains($title, 'chaperone')
            || str_contains($title, 'transportation')
            || str_contains($title, 'venue')
            || str_contains($title, 'attendee list'))->count();

        return $sportsHits === 0 && $campHits >= 3;
    }

    protected function filterTasksForArchetype(Collection $tasks, ?string $archetype): Collection
    {
        if ($archetype !== 'sports_tournament') {
            return $tasks;
        }

        return $tasks->reject(function ($task) {
            $title = mb_strtolower((string) ($task['title'] ?? ''));
            return str_contains($title, 'permission slip')
                || str_contains($title, 'chaperone')
                || str_contains($title, 'transportation')
                || str_contains($title, 'attendee list')
                || str_contains($title, 'venue');
        });
    }
}
