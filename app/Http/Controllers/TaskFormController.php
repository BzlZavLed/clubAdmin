<?php

namespace App\Http\Controllers;

use App\Models\EventTask;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\TaskFormResponse;
use App\Models\TaskFormSchema;
use App\Models\TempMemberPathfinder;
use Illuminate\Http\Request;

class TaskFormController extends Controller
{
    public function show(EventTask $eventTask)
    {
        $event = $eventTask->event;
        $this->authorize('view', $event);

        $schemaKey = $eventTask->checklist_json['task_key'] ?? null;
        if (!$schemaKey) {
            $schemaKey = $this->inferSchemaKey($eventTask->title);
            if ($schemaKey) {
                $meta = $eventTask->checklist_json ?? [];
                $meta['task_key'] = $schemaKey;
                $eventTask->update(['checklist_json' => $meta]);
            }
        }
        if (!$schemaKey) {
            return response()->json(['message' => 'No form schema assigned.'], 404);
        }

        $schema = TaskFormSchema::where('key', $schemaKey)->first();
        if (!$schema) {
            return response()->json(['message' => 'Form schema not found.'], 404);
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

        $schemaKey = $eventTask->checklist_json['task_key'] ?? null;
        if (!$schemaKey) {
            return response()->json(['message' => 'No form schema assigned.'], 404);
        }

        $schema = TaskFormSchema::where('key', $schemaKey)->first();
        if (!$schema) {
            return response()->json(['message' => 'Form schema not found.'], 404);
        }

        $response = TaskFormResponse::updateOrCreate(
            ['event_task_id' => $eventTask->id],
            ['schema_key' => $schemaKey, 'data_json' => $validated['data_json']]
        );

        return response()->json(['response' => $response]);
    }

    protected function inferSchemaKey(string $title): ?string
    {
        $normalized = strtolower($title);
        $mappings = [
            ['collect permission slips', 'permission_slips'],
            ['permission slips', 'permission_slips'],
            ['permission slip', 'permission_slips'],
            ['arrange transportation', 'transportation_plan'],
            ['transportation', 'transportation_plan'],
            ['emergency contact list', 'emergency_contacts'],
            ['emergency contacts', 'emergency_contacts'],
            ['assign chaperones', 'chaperone_assignments'],
            ['chaperones', 'chaperone_assignments'],
            ['campsite reservation', 'camp_reservation'],
            ['site reservation', 'camp_reservation'],
        ];

        foreach ($mappings as [$needle, $key]) {
            if (str_contains($normalized, $needle)) {
                return $key;
            }
        }

        return null;
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

        $pathfinders = TempMemberPathfinder::whereIn('id', $pathfinderIds)
            ->get(['id', 'nombre', 'father_name', 'father_phone'])
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
                $name = $detail->nombre ?? $name;
                $father = $detail->father_name ?? '—';
                $phone = $detail->father_phone ?? '—';
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
