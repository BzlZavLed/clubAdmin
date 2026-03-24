<?php

namespace App\Http\Controllers;

use App\Models\AiRequestLog;
use App\Models\EventTask;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\TaskFormResponse;
use App\Models\TaskFormSchema;
use App\Models\MemberPathfinder;
use App\Services\AiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TaskFormController extends Controller
{
    public function __construct(
        private readonly AiClient $aiClient,
    ) {
    }

    public function show(EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('view', $event);

        $checklist = is_array($eventTask->checklist_json) ? $eventTask->checklist_json : [];
        $customSchema = is_array($checklist['custom_form_schema'] ?? null)
            ? $checklist['custom_form_schema']
            : null;

        $schemaKey = $eventTask->checklist_json['task_key'] ?? null;
        if (!$schemaKey) {
            $schemaKey = $this->inferSchemaKey($eventTask->title);
            if ($schemaKey) {
                $meta = $eventTask->checklist_json ?? [];
                $meta['task_key'] = $schemaKey;
                $eventTask->update(['checklist_json' => $meta]);
            }
        }
        if (!$schemaKey && !$customSchema) {
            return response()->json(['message' => 'No form schema assigned.'], 404);
        }
        $schema = null;
        if ($customSchema) {
            $schema = new TaskFormSchema([
                'key' => 'custom_task_form',
                'name' => 'Custom Task Form',
                'description' => 'Custom fields defined by club director.',
                'schema_json' => $customSchema,
            ]);
        } elseif ($schemaKey) {
            $schema = TaskFormSchema::where('key', $schemaKey)->first();
            if (!$schema) {
                return response()->json(['message' => 'Form schema not found.'], 404);
            }
        }

        $response = TaskFormResponse::where('event_task_id', $eventTask->id)->first();
        $prefill = null;
        if ($schemaKey === 'emergency_contacts' && (!$response || empty($response->data_json))) {
            $prefill = $this->buildEmergencyContactPrefill($event);
        }

        return response()->json([
            'schema' => $schema,
            'response' => $response,
            'prefill' => $prefill,
        ]);
    }

    public function update(Request $request, EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'data_json' => ['required', 'array'],
        ]);

        $checklist = is_array($eventTask->checklist_json) ? $eventTask->checklist_json : [];
        $customSchema = is_array($checklist['custom_form_schema'] ?? null)
            ? $checklist['custom_form_schema']
            : null;
        $schemaKey = $eventTask->checklist_json['task_key'] ?? null;
        if (!$schemaKey && !$customSchema) {
            return response()->json(['message' => 'No form schema assigned.'], 404);
        }
        if (!$customSchema && $schemaKey) {
            $schema = TaskFormSchema::where('key', $schemaKey)->first();
            if (!$schema) {
                return response()->json(['message' => 'Form schema not found.'], 404);
            }
        }
        if ($customSchema) {
            $schemaKey = 'custom_task_form';
        }

        $response = TaskFormResponse::updateOrCreate(
            ['event_task_id' => $eventTask->id],
            ['schema_key' => $schemaKey, 'data_json' => $validated['data_json']]
        );

        return response()->json(['response' => $response]);
    }

    public function uploadMedia(Request $request, EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'file' => ['required', 'image', 'max:5120'],
        ]);

        $file = $validated['file'];
        $path = $file->store("event-task-form-media/{$event->id}/{$eventTask->id}", 'public');

        return response()->json([
            'path' => $path,
            'url' => $this->buildPublicStorageUrl($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    public function suggest(Request $request, EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('update', $event);

        $payload = [
            'model' => config('ai.model'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You design small operational forms for club event tasks. Return valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildTaskFormPrompt($eventTask),
                ],
            ],
            'tool_choice' => 'none',
            'max_output_tokens' => min(900, max(350, (int) config('ai.max_output_tokens'))),
        ];

        $start = microtime(true);
        $log = AiRequestLog::create([
            'event_id' => $event?->id,
            'club_id' => $event?->club_id,
            'user_id' => $request->user()?->id,
            'provider' => config('ai.provider'),
            'model' => $payload['model'] ?? config('ai.model'),
            'request_json' => [
                'source' => 'task_form_suggest',
                'endpoint' => rtrim(config('ai.base_url'), '/') . '/responses',
                'payload' => $payload,
                'task_id' => $eventTask->id,
                'task_title' => $eventTask->title,
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
            $decoded = $rawText ? $this->decodeJsonPayload($rawText) : null;
            $schema = $this->sanitizeSuggestedSchema($decoded['schema'] ?? null);

            if (!$schema) {
                return response()->json(['message' => 'AI did not return a usable form suggestion.'], 422);
            }

            return response()->json(['schema' => $schema]);
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $log->update([
                'latency_ms' => $latency,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            report($e);

            return response()->json(['message' => 'Unable to generate form suggestion right now.'], 422);
        }
    }

    protected function inferSchemaKey(string $title): ?string
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

    protected function buildTaskFormPrompt(EventTask $eventTask): string
    {
        $event = $eventTask->event;
        $eventDescription = trim((string) ($event?->description ?? ''));
        $eventType = trim((string) ($event?->event_type ?? ''));

        return "I need a practical custom form schema to help complete an event-planning task. "
            . "Task name: {$eventTask->title}. "
            . ($eventTask->description ? "Task description: {$eventTask->description}. " : '')
            . ($eventType !== '' ? "Event type: {$eventType}. " : '')
            . ($eventDescription !== '' ? "Event description: {$eventDescription}. " : '')
            . 'Return JSON only with this exact shape: '
            . '{"schema":{"mode":"single|registry","fields":[{"key":"string","label":"string","type":"text|textarea|number|date|time|select|checkbox|image","required":true|false,"help":"string|null","source":"members|staff|classes|participants|task_data|null","source_config":{"task_id":"integer|null","label_field":"string|null","participant_role":"string|null","participant_status":"string|null"}|null,"multiple":true|false,"options":["string"]|null}]}}. '
            . 'Rules: choose registry mode only when multiple repeated records are likely. '
            . 'Use dynamic sources only when truly helpful. '
            . 'Do not use task_data unless the task clearly depends on records collected by another task. '
            . 'At most 6 fields. Keep the form minimal, practical, and editable by humans. '
            . 'For select fields, use either source OR options, not both.';
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

    protected function buildPublicStorageUrl(string $path): string
    {
        $storageUrl = Storage::disk('public')->url($path);
        $parsedPath = parse_url($storageUrl, PHP_URL_PATH) ?: $storageUrl;
        $parsedQuery = parse_url($storageUrl, PHP_URL_QUERY);
        $baseUrl = request()?->getSchemeAndHttpHost() ?: rtrim((string) config('app.url'), '/');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($parsedPath, '/');

        if ($parsedQuery) {
            $url .= '?' . $parsedQuery;
        }

        return $url;
    }

    protected function sanitizeSuggestedSchema(mixed $schema): ?array
    {
        if (!is_array($schema)) {
            return null;
        }

        $mode = ($schema['mode'] ?? 'single') === 'registry' ? 'registry' : 'single';
        $fields = $schema['fields'] ?? null;
        if (!is_array($fields) || empty($fields)) {
            return null;
        }

        $allowedTypes = ['text', 'textarea', 'number', 'date', 'time', 'select', 'checkbox', 'image'];
        $allowedSources = ['members', 'staff', 'classes', 'participants', 'task_data'];
        $normalized = [];
        $seenKeys = [];

        foreach (array_slice($fields, 0, 6) as $field) {
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

            $source = trim((string) ($field['source'] ?? ''));
            if (!in_array($source, $allowedSources, true)) {
                $source = null;
            }

            $sourceConfig = null;
            if ($source === 'task_data') {
                $rawSourceConfig = is_array($field['source_config'] ?? null) ? $field['source_config'] : [];
                $taskId = isset($rawSourceConfig['task_id']) ? (int) $rawSourceConfig['task_id'] : null;
                $labelField = trim((string) ($rawSourceConfig['label_field'] ?? ''));
                $labelField = $labelField !== '' ? Str::of($labelField)
                    ->lower()
                    ->replaceMatches('/[^a-z0-9_]+/', '_')
                    ->trim('_')
                    ->limit(40, '')
                    ->value() : null;

                if (!$taskId || !$labelField) {
                    $source = null;
                } else {
                    $sourceConfig = [
                        'task_id' => $taskId,
                        'label_field' => $labelField,
                    ];
                }
            } elseif ($source === 'participants') {
                $rawSourceConfig = is_array($field['source_config'] ?? null) ? $field['source_config'] : [];
                $participantRole = trim((string) ($rawSourceConfig['participant_role'] ?? ''));
                $participantStatus = trim((string) ($rawSourceConfig['participant_status'] ?? ''));

                $sourceConfig = array_filter([
                    'participant_role' => $participantRole !== '' ? Str::of($participantRole)->lower()->replaceMatches('/[^a-z_]+/', '_')->trim('_')->value() : null,
                    'participant_status' => $participantStatus !== '' ? Str::of($participantStatus)->lower()->replaceMatches('/[^a-z_]+/', '_')->trim('_')->value() : null,
                ], fn ($value) => $value !== null);
            }

            $help = isset($field['help']) ? trim((string) $field['help']) : null;
            $help = $help !== '' ? Str::limit($help, 120, '') : null;

            $options = null;
            if ($type === 'select' && !$source) {
                $rawOptions = $field['options'] ?? [];
                if (is_array($rawOptions)) {
                    $options = collect($rawOptions)
                        ->map(fn ($option) => trim((string) $option))
                        ->filter()
                        ->unique()
                        ->take(8)
                        ->map(fn ($option) => Str::limit($option, 60, ''))
                        ->values()
                        ->all();
                }
                if (empty($options)) {
                    continue;
                }
            }

            $multiple = $type === 'select' ? (bool) ($field['multiple'] ?? false) : false;

            $normalized[] = array_filter([
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'help' => $help,
                'source' => $source,
                'source_config' => $sourceConfig,
                'multiple' => $multiple ? true : null,
                'options' => $options,
            ], fn ($value) => $value !== null);

            $seenKeys[$key] = true;
        }

        if (empty($normalized)) {
            return null;
        }

        return [
            'mode' => $mode,
            'fields' => $normalized,
        ];
    }

    protected function buildEmergencyContactPrefill($event): array
    {
        $kids = $event->participants()
            ->where('role', 'kid')
            ->get(['id', 'member_id', 'participant_name']);

        if ($kids->isEmpty()) {
            return [
                'contact_list' => '',
                'medical_notes' => '',
                'allergies' => '',
            ];
        }

        $memberIds = $kids->pluck('member_id')->filter()->unique()->values();
        $memberRows = Member::whereIn('id', $memberIds)
            ->get(['id', 'type', 'id_data'])
            ->keyBy('id');

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->filter()->values();
        $pathfinderIds = $memberRows->whereIn('type', ['temp_pathfinder', 'pathfinders'])->pluck('id_data')->filter()->values();

        $adventurers = MemberAdventurer::whereIn('id', $adventurerIds)
            ->get(['id', 'applicant_name', 'emergency_contact', 'cell_number', 'allergies', 'health_history', 'physical_restrictions'])
            ->keyBy('id');

        $pathfinders = MemberPathfinder::whereIn('id', $pathfinderIds)
            ->get(['id', 'applicant_name', 'father_guardian_name', 'father_guardian_phone'])
            ->keyBy('id');

        $contactLines = [];
        $allergyLines = [];
        $medicalLines = [];

        foreach ($kids as $kid) {
            $name = $kid->participant_name;
            $member = $kid->member_id ? $memberRows->get($kid->member_id) : null;

            if ($member && $member->type === 'adventurers') {
                $detail = $adventurers->get($member->id_data);
                $name = $detail->applicant_name ?? $name;
                $contact = $detail->emergency_contact ?? '—';
                $phone = $detail->cell_number ?? '—';
                $contactLines[] = "{$name} — Emergency Contact: {$contact} (Phone: {$phone})";

                if (!empty($detail->allergies)) {
                    $allergyLines[] = "{$name}: {$detail->allergies}";
                }

                $medicalParts = array_filter([
                    $detail->health_history ?? null,
                    $detail->physical_restrictions ?? null,
                ]);
                if (!empty($medicalParts)) {
                    $medicalLines[] = "{$name}: " . implode(' | ', $medicalParts);
                }
            } elseif ($member && in_array($member->type, ['temp_pathfinder', 'pathfinders'], true)) {
                $detail = $pathfinders->get($member->id_data);
                $name = $detail->applicant_name ?? $name;
                $father = $detail->father_guardian_name ?? '—';
                $phone = $detail->father_guardian_phone ?? '—';
                $contactLines[] = "{$name} — Father: {$father} (Phone: {$phone})";
            } else {
                $contactLines[] = "{$name} — Emergency Contact: — (Phone: —)";
            }
        }

        return [
            'contact_list' => implode("\n", $contactLines),
            'medical_notes' => implode("\n", $medicalLines),
            'allergies' => implode("\n", $allergyLines),
        ];
    }
}
