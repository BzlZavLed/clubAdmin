<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventParticipantRosterAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_hierarchical_event_owner_cannot_mark_club_participants_but_targeted_club_can_confirm_staff(): void
    {
        $associationOwner = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => 1,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $clubDirector = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club = Club::create([
            'user_id' => $clubDirector->id,
            'club_name' => 'North Pathfinders',
            'church_name' => 'North Church',
            'director_name' => $clubDirector->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $clubDirector->update([
            'club_id' => $club->id,
            'scope_id' => $club->id,
        ]);

        $staffUser = User::factory()->create([
            'profile_type' => 'club_personal',
            'role_key' => 'club_personal',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'club_id' => $club->id,
            'sub_role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $staff = Staff::create([
            'type' => 'pathfinders',
            'id_data' => 1,
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
        ]);

        $event = Event::create([
            'club_id' => $club->id,
            'scope_type' => 'association',
            'scope_id' => 1,
            'target_club_types' => ['pathfinders'],
            'created_by_user_id' => $associationOwner->id,
            'title' => 'Association Camporee',
            'event_type' => 'camporee',
            'start_at' => now()->addMonth(),
            'end_at' => now()->addMonth()->addDay(),
            'timezone' => 'America/New_York',
            'status' => 'draft',
            'is_payable' => false,
        ]);
        $event->targetClubs()->sync([$club->id]);

        $payload = [
            'staff_id' => $staff->id,
            'participant_name' => $staffUser->name,
            'role' => 'staff',
            'status' => 'confirmed',
        ];

        $this->actingAs($associationOwner)
            ->postJson(route('event-participants.store', $event), $payload)
            ->assertForbidden();

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), $payload)
            ->assertOk()
            ->assertJsonPath('participant.staff_id', $staff->id);

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'role' => 'staff',
            'status' => 'confirmed',
        ]);

        $this->assertSame(1, EventParticipant::query()->count());
    }
}
