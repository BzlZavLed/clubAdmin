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
        $this->get('/privacy')->assertOk();
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

        $payload = [
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
        ];

        $this->post('/register', $payload)->assertSessionHasErrors('privacy_consent');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);

        $response = $this->post('/register', [...$payload, 'privacy_consent' => true]);

        $this->assertAuthenticated();
        $response->assertRedirect('/club-director/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'privacy_notice_version' => '2026-08-27',
        ]);
        $this->assertNotNull(User::where('email', 'test@example.com')->firstOrFail()->privacy_consent_at);
        $this->assertDatabaseHas('privacy_consents', [
            'user_id' => User::where('email', 'test@example.com')->firstOrFail()->id,
            'notice_version' => '2026-08-27',
            'source' => 'account_registration',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'privacy_consent_recorded',
            'entity_type' => 'PrivacyConsent',
        ]);
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
            'privacy_consent' => true,
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
            'privacy_consent' => true,
        ])->assertRedirect('/login');

        $this->assertDatabaseHas('users', [
            'id' => $deletedParent->id,
            'email' => 'reused-parent@example.com',
            'name' => 'Reactivated Parent',
            'club_id' => $club->id,
            'status' => 'pending',
        ]);
    }

    public function test_parent_registration_consumes_a_limited_invite_atomically(): void
    {
        [$church, $club] = $this->parentRegistrationContext('Limited');
        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'LIMITED001',
            'uses_left' => 1,
            'status' => 'active',
        ]);

        $this->post('/register-parent', $this->parentRegistrationPayload($church, $club, $invite, [
            'email' => '  FIRST.PARENT@EXAMPLE.TEST  ',
        ]))->assertRedirect('/login');

        $this->assertSame(0, $invite->fresh()->uses_left);
        $this->assertDatabaseHas('users', ['email' => 'first.parent@example.test']);

        $this->post('/register-parent', $this->parentRegistrationPayload($church, $club, $invite, [
            'name' => 'Second Parent',
            'email' => 'second.parent@example.test',
        ]))->assertSessionHasErrors('invite_code');

        $this->assertSame(0, $invite->fresh()->uses_left);
        $this->assertDatabaseMissing('users', ['email' => 'second.parent@example.test']);
    }

    public function test_failed_parent_registration_does_not_consume_the_invite_or_create_partial_records(): void
    {
        [$church, $club] = $this->parentRegistrationContext('Rollback');
        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'ROLLBACK01',
            'uses_left' => 1,
            'status' => 'active',
        ]);

        $payload = $this->parentRegistrationPayload($church, $club, $invite, [
            'email' => 'rollback.parent@example.test',
            'church_name' => 'Wrong Church',
        ]);

        $this->post('/register-parent', $payload)->assertSessionHasErrors('church_name');

        $this->assertSame(1, $invite->fresh()->uses_left);
        $this->assertDatabaseMissing('users', ['email' => 'rollback.parent@example.test']);
        $this->assertDatabaseCount('club_user', 0);
    }

    private function parentRegistrationContext(string $prefix): array
    {
        $church = Church::create([
            'church_name' => "$prefix Registration Church",
            'email' => strtolower($prefix).'@example.test',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => "$prefix Registration Club",
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        return [$church, $club];
    }

    private function parentRegistrationPayload(
        Church $church,
        Club $club,
        ChurchInviteCode $invite,
        array $overrides = [],
    ): array {
        return array_merge([
            'name' => 'First Parent',
            'email' => 'first.parent@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'invite_code' => strtolower($invite->code),
            'privacy_consent' => true,
        ], $overrides);
    }
}
