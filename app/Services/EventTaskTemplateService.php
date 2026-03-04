<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EventTaskTemplateService
{
    public function __construct(
        private AiClient $aiClient,
    ) {
    }

    public function seedEventTasks(Event $event): array
    {
        $templates = $this->templatesForEventType($event->club_id, $event->event_type);

        if ($templates->isEmpty()) {
            $templates = $this->generateTemplatesWithAi($event->event_type, (string) $event->title);
            if ($templates->isEmpty()) {
                $templates = $this->buildDefaultTemplates($event->event_type);
            }
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
            $templates = $this->templatesForEventType($event->club_id, $event->event_type);
        }

        $createdTasks = [];
        foreach ($templates as $template) {
            $checklist = [
                'source' => 'event_type_template',
            ];
            $title = strtolower((string) $template->title);
            $skipVenueFormTaskKey = str_contains($title, 'campsite reservation confirmed');
            if (!empty($template->task_key) && !$skipVenueFormTaskKey) {
                $checklist['task_key'] = $template->task_key;
            }
            if (!empty($template->form_schema_json)) {
                $checklist['custom_form_schema'] = $template->form_schema_json;
            }

            $createdTasks[] = EventTask::create([
                'event_id' => $event->id,
                'title' => $template->title,
                'description' => $template->description,
                'status' => 'todo',
                'checklist_json' => $checklist,
            ]);
        }

        return $createdTasks;
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

    protected function generateTemplatesWithAi(string $eventType, ?string $eventTitle = null): Collection
    {
        try {
            $response = $this->aiClient->responses([
                'model' => config('ai.model'),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => 'You generate event planning task checklists. Return valid JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildAiTaskPrompt($eventType, $eventTitle),
                    ],
                ],
                'tool_choice' => 'none',
                'max_output_tokens' => min(1000, max(350, (int) config('ai.max_output_tokens'))),
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

            return $this->normalizeAiTasks($tasks);
        } catch (Throwable $e) {
            report($e);
            return collect();
        }
    }

    protected function buildAiTaskPrompt(string $eventType, ?string $eventTitle = null): string
    {
        $eventTypeLabel = trim(str_replace('_', ' ', strtolower($eventType)));
        $titlePart = $eventTitle ? " Event title: {$eventTitle}." : '';

        return "Create a practical checklist for a club event. Event type: {$eventTypeLabel}.{$titlePart} "
            . 'Include safety, logistics, participants, staffing, permissions, budget, venue and communication tasks. '
            . 'Return JSON object with this exact shape: '
            . '{"tasks":[{"title":"string","description":"string|null","task_key":"string|null"}]}. '
            . 'Rules: 8-18 tasks, no duplicates, concise titles, null description when unnecessary, task_key optional.';
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

    protected function normalizeAiTasks(array $tasks): Collection
    {
        $knownTaskKeys = [
            'camp_reservation',
            'permission_slips',
            'finalize_attendee_list',
            'transportation_plan',
            'emergency_contacts',
            'chaperone_assignments',
        ];

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

            $normalized[] = [
                'title' => $title,
                'description' => $description,
                'task_key' => $taskKey,
                'form_schema_json' => null,
            ];
        }

        if (count($normalized) < 6) {
            throw new RuntimeException('AI returned an insufficient task list.');
        }

        return collect(array_slice($normalized, 0, 20));
    }

    protected function buildDefaultTemplates(string $eventType): Collection
    {
        $base = [
            'Confirm date/time with venue',
            'Finalize attendee list',
            'Collect permission slips',
            'Assign chaperones/staff',
            'Arrange transportation',
            'Emergency contact list ready',
        ];

        $titles = match ($eventType) {
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
            default => $base,
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
}
