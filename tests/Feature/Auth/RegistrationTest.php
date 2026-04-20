<?php

namespace Tests\Feature\Auth;

use App\Models\Church;
use App\Models\ChurchInviteCode;
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
}
