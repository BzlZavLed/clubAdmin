<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\ClubParentEnrollmentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileParentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_secure_enrollment_parent_cannot_bypass_activation_on_mobile(): void
    {
        [, $club] = $this->churchAndClub();
        $link = ClubParentEnrollmentLink::query()->create([
            'club_id' => $club->id,
            'token' => str_repeat('m', 64),
        ]);
        $parent = $this->parent($club, [
            'email_verified_at' => null,
            'secure_enrollment_link_id' => $link->id,
            'parent_activation_method' => null,
        ]);

        $this->postJson(route('api.mobile.login'), [
            'email' => $parent->email,
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Parent account activation is required.',
                'code' => 'PARENT_ACTIVATION_REQUIRED',
            ]);

        $this->actingAs($parent)
            ->getJson(route('api.mobile.parent.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('code', 'PARENT_ACTIVATION_REQUIRED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verified_and_director_activated_parents_can_use_mobile(): void
    {
        [, $club] = $this->churchAndClub();
        $link = ClubParentEnrollmentLink::query()->create([
            'club_id' => $club->id,
            'token' => str_repeat('a', 64),
        ]);
        $verified = $this->parent($club, [
            'email' => 'verified-parent@example.test',
            'secure_enrollment_link_id' => $link->id,
            'parent_activation_method' => 'email',
        ]);
        $directorActivated = $this->parent($club, [
            'email' => 'director-parent@example.test',
            'email_verified_at' => null,
            'secure_enrollment_link_id' => $link->id,
            'parent_activation_method' => 'director',
        ]);

        foreach ([$verified, $directorActivated] as $parent) {
            $this->postJson(route('api.mobile.login'), [
                'email' => $parent->email,
                'password' => 'password123',
            ])
                ->assertOk()
                ->assertJsonStructure(['token', 'user'])
                ->assertJsonPath('user.id', $parent->id);

            $this->actingAs($parent)
                ->getJson(route('api.mobile.parent.dashboard'))
                ->assertOk();
        }
    }

    public function test_non_active_parent_loses_access_even_with_an_existing_session(): void
    {
        [, $club] = $this->churchAndClub();
        $parent = $this->parent($club, ['status' => 'pending']);

        $this->assertFalse($parent->canAccessParentPortal());

        $this->actingAs($parent)
            ->getJson(route('api.mobile.parent.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('code', 'PARENT_ACTIVATION_REQUIRED');

        $this->actingAs($parent)
            ->get(route('parent.dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    private function churchAndClub(): array
    {
        $church = Church::query()->create([
            'church_name' => 'Mobile Access Church',
            'email' => 'mobile-access-church@example.test',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club = Club::query()->create([
            'user_id' => $director->id,
            'club_name' => 'Mobile Access Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        return [$church, $club];
    }

    private function parent(Club $club, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Mobile Parent',
            'email' => 'mobile-parent@example.test',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'status' => 'active',
        ], $overrides));
    }
}
