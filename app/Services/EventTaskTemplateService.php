<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskTemplate;
use Illuminate\Support\Collection;

class EventTaskTemplateService
{
    public function seedEventTasks(Event $event): array
    {
        $templates = $this->templatesForEventType($event->club_id, $event->event_type);

        if ($templates->isEmpty()) {
            $templates = $this->buildDefaultTemplates($event->event_type);
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
