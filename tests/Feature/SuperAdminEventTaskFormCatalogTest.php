<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskTemplate;
use App\Models\TaskFormSchema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminEventTaskFormCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_global_task_form_schema(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $schema = [
            'mode' => 'single',
            'fields' => [
                ['key' => 'medical_forms_uploaded', 'label' => 'Medical forms uploaded', 'type' => 'checkbox', 'required' => true],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ];

        $this->actingAs($superadmin)
            ->post(route('superadmin.event-task-forms.schemas.store'), [
                'key' => 'medical_forms',
                'name' => 'Medical Forms',
                'description' => 'Reusable medical form checklist.',
                'schema_json' => $schema,
            ])
            ->assertRedirect();

        $created = TaskFormSchema::where('key', 'medical_forms')->firstOrFail();

        $this->assertSame('Medical Forms', $created->name);
        $this->assertSame($schema, $created->schema_json);
    }

    public function test_superadmin_cannot_create_global_schema_for_fixed_handler_key(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->post(route('superadmin.event-task-forms.schemas.store'), [
                'key' => 'permission_slips',
                'name' => 'Permission Slips',
                'description' => 'Should use documents handler instead.',
                'schema_json' => [
                    'mode' => 'single',
                    'fields' => [
                        ['key' => 'received', 'label' => 'Received', 'type' => 'checkbox'],
                    ],
                ],
            ])
            ->assertSessionHasErrors('key');

        $this->assertDatabaseMissing('task_form_schemas', [
            'key' => 'permission_slips',
        ]);
    }

    public function test_superadmin_can_update_live_event_task_custom_form_assignment(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'North Pathfinders',
            'church_name' => 'North Church',
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $event = Event::create([
            'club_id' => $club->id,
            'scope_type' => 'association',
            'scope_id' => 1,
            'created_by_user_id' => $director->id,
            'title' => 'Association Camporee',
            'event_type' => 'camporee',
            'start_at' => now()->addMonth(),
            'end_at' => now()->addMonth()->addDay(),
            'timezone' => 'America/New_York',
            'status' => 'draft',
        ]);

        $task = EventTask::create([
            'event_id' => $event->id,
            'title' => 'First aid kit & medical forms Club',
            'description' => 'Club-level medical readiness.',
            'status' => 'todo',
            'responsibility_level' => 'club',
            'checklist_json' => [
                'source' => 'event_checklist',
                'task_key' => 'medical_forms',
                'custom_form_schema' => [
                    'mode' => 'single',
                    'fields' => [
                        ['key' => 'kit_ready', 'label' => 'Kit ready', 'type' => 'checkbox'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($superadmin)
            ->get(route('superadmin.event-task-forms.index', ['search' => 'medical']))
            ->assertOk();

        $replacementSchema = [
            'mode' => 'single',
            'fields' => [
                ['key' => 'medical_forms_uploaded', 'label' => 'Medical forms uploaded', 'type' => 'checkbox'],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ];

        $this->actingAs($superadmin)
            ->put(route('superadmin.event-task-forms.tasks.update', $task), [
                'task_key' => 'medical_forms',
                'custom_form_schema' => $replacementSchema,
                'clear_custom_form' => false,
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame($replacementSchema, $task->checklist_json['custom_form_schema']);

        $template = EventTaskTemplate::query()
            ->where('club_id', $club->id)
            ->where('event_type', 'camporee')
            ->where('title', 'First aid kit & medical forms Club')
            ->firstOrFail();

        $this->assertSame($replacementSchema, $template->form_schema_json);
        $this->assertTrue($template->is_active);
    }
}
