<?php

namespace Tests\Feature\Auth;

use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $church = Church::create([
            'church_name' => 'Test Church',
            'email' => 'church@example.com',
        ]);

        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'TESTCODE01',
            'uses_left' => null,
            'status' => 'active',
        ]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'profile_type' => 'club_director',
            'sub_role' => null,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => 'new',
            'invite_code' => $invite->code,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/club-director/dashboard');
    }

    public function test_parent_registration_is_pending_until_the_club_director_approves_it(): void
    {
        $church = Church::create([
            'church_name' => 'Parent Registration Church',
            'email' => 'parents@example.com',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Parent Registration Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $director->clubs()->attach($club->id, ['status' => 'active']);
        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'PARENTCODE',
            'uses_left' => null,
            'status' => 'active',
        ]);

        $response = $this->post('/register', [
            'name' => 'Pending Parent',
            'email' => 'parent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'profile_type' => 'parent',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'invite_code' => $invite->code,
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'parent@example.com',
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'status' => 'pending',
        ]);

        $parent = User::where('email', 'parent@example.com')->firstOrFail();
        $this->actingAs($director)
            ->post(route('club.users.approve', $parent))
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $parent->id,
            'status' => 'active',
        ]);

        auth()->logout();
        $this->post('/login', [
            'email' => 'parent@example.com',
            'password' => 'password',
        ])->assertRedirect('/parent/dashboard');
    }

    public function test_deleted_parent_email_can_be_reused_from_the_parent_registration_form(): void
    {
        $church = Church::create(['church_name' => 'Reuse Church', 'email' => 'reuse@example.com']);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Reuse Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $deletedParent = User::factory()->create([
            'email' => 'reused-parent@example.com',
            'profile_type' => 'parent',
            'status' => 'deleted',
        ]);
        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'REUSECODE',
            'uses_left' => null,
            'status' => 'active',
        ]);

        $this->post('/register-parent', [
            'name' => 'Reactivated Parent',
            'email' => 'reused-parent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'invite_code' => $invite->code,
        ])->assertRedirect('/login');

        $this->assertDatabaseHas('users', [
            'id' => $deletedParent->id,
            'email' => 'reused-parent@example.com',
            'name' => 'Reactivated Parent',
            'club_id' => $club->id,
            'status' => 'pending',
        ]);
    }
}
