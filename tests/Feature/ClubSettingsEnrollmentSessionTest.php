<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\ParentMember;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubSettingsEnrollmentSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_manage_live_parent_enrollment_requests_for_their_club(): void
    {
        $church = Church::create([
            'church_name' => 'Enrollment Church',
            'email' => 'enrollment@example.com',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Enrollment Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $director->clubs()->attach($club->id, ['status' => 'active']);
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'pending',
        ]);

        $this->actingAs($director)
            ->getJson(route('club.settings.enrollment-session', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.club.id', $club->id)
            ->assertJsonPath('data.pending_parents.0.id', $parent->id)
            ->assertJsonPath('data.registration_url', route('parent.register'));

        $this->assertDatabaseHas('church_invite_codes', ['church_id' => $church->id, 'status' => 'active']);

        $this->actingAs($director)
            ->get(route('club.settings.enrollment.qr', $club))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($director)
            ->postJson(route('club.settings.enrollment.parents.approve', $parent), ['club_id' => $club->id])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $parent->id, 'status' => 'active']);
    }

    public function test_director_can_approve_staff_request_assign_a_class_and_make_them_treasurer(): void
    {
        $church = Church::create(['church_name' => 'Staff Enrollment Church', 'email' => 'staff-enrollment@example.com']);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Staff Enrollment Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);
        $director->clubs()->attach($club->id, ['status' => 'active']);
        $class = ClubClass::create(['club_id' => $club->id, 'class_name' => 'Pioneros', 'class_order' => 1]);
        $staffUser = User::factory()->create([
            'profile_type' => 'club_personal',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'pending',
        ]);

        $this->actingAs($director)
            ->getJson(route('club.settings.enrollment-session', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.pending_staff.0.id', $staffUser->id)
            ->assertJsonPath('data.classes.0.id', $class->id)
            ->assertJsonPath('data.staff_registration_url', route('register', ['profile_type' => 'club_personal', 'club_id' => $club->id]));

        $this->actingAs($director)
            ->postJson(route('club.settings.enrollment.staff.approve', $staffUser), [
                'club_id' => $club->id,
                'assigned_class' => $class->id,
                'make_treasurer' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $staffUser->id, 'status' => 'active', 'profile_type' => 'treasurer']);
        $staff = Staff::query()->where('user_id', $staffUser->id)->where('club_id', $club->id)->firstOrFail();
        $this->assertSame($class->id, $staff->assigned_class);
        $this->assertDatabaseHas('class_staff', ['staff_id' => $staff->id, 'club_class_id' => $class->id]);
    }

    public function test_director_can_create_an_active_parent_and_member_from_the_enrollment_session(): void
    {
        $church = Church::create(['church_name' => 'Assisted Enrollment Church', 'email' => 'assisted@example.com']);
        $director = User::factory()->create(['profile_type' => 'club_director', 'church_id' => $church->id, 'church_name' => $church->church_name, 'status' => 'active']);
        $club = Club::create(['user_id' => $director->id, 'club_name' => 'Assisted Enrollment Club', 'church_id' => $church->id, 'church_name' => $church->church_name, 'director_name' => $director->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        $director->clubs()->attach($club->id, ['status' => 'active']);

        $this->actingAs($director)
            ->postJson(route('club.settings.enrollment.assisted.store'), [
                'club_id' => $club->id,
                'enrollment_type' => 'parent_and_member',
                'parent' => ['name' => 'Assisted Parent', 'email' => 'assisted-parent@example.com', 'phone' => '555-111-2222', 'password' => 'password123', 'password_confirmation' => 'password123'],
                'member' => ['applicant_name' => 'Assisted Child', 'birthdate' => '2018-01-01', 'age' => 8, 'grade' => '2', 'mailing_address' => '1 Main Street', 'home_address' => '1 Main Street', 'cell_number' => '555-222-3333', 'emergency_contact' => 'Emergency Contact', 'signature' => 'Assisted Parent'],
            ])
            ->assertOk();

        $parent = User::query()->where('email', 'assisted-parent@example.com')->firstOrFail();
        $member = Member::query()->where('club_id', $club->id)->where('parent_id', $parent->id)->firstOrFail();
        $this->assertSame('active', $parent->status);
        $this->assertDatabaseHas('parent_members', ['user_id' => $parent->id, 'member_id' => $member->id]);
    }
}
