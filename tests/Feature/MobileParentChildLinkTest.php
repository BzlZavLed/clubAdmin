<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileParentChildLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_parent_cannot_link_a_child_from_another_church_or_steal_an_owned_child(): void
    {
        [$homeChurch, $homeClub] = $this->churchAndClub('Home');
        [, $foreignClub] = $this->churchAndClub('Foreign');
        $parent = $this->parent($homeChurch, $homeClub, 'Maria Santos', 'maria@example.test');
        $otherParent = $this->parent($homeChurch, $homeClub, 'Other Santos', 'other@example.test');

        $foreign = $this->unlinkedChild($foreignClub, $parent, 'Elena Santos');
        $owned = $this->unlinkedChild($homeClub, $parent, 'Lucas Santos', $otherParent->id);

        $this->actingAs($parent)
            ->postJson(route('api.mobile.parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $foreign->id,
            ])
            ->assertForbidden();

        $this->actingAs($parent)
            ->postJson(route('api.mobile.parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $owned->id,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('members', [
            'type' => 'adventurers',
            'id_data' => $foreign->id,
            'parent_id' => null,
        ]);
        $this->assertDatabaseHas('members', [
            'type' => 'adventurers',
            'id_data' => $owned->id,
            'parent_id' => $otherParent->id,
        ]);
    }

    public function test_mobile_parent_uses_the_same_immediate_and_director_approval_identity_rules_as_web(): void
    {
        [$church, $club] = $this->churchAndClub('Home');
        $parent = $this->parent($church, $club, 'Maria Santos', 'maria@example.test');
        $immediate = $this->unlinkedChild($club, $parent, 'Elena Santos');
        $approval = $this->unlinkedChild($club, $parent, 'Lucas Santos', null, [
            'parent_name' => 'Different Guardian',
        ]);

        $this->actingAs($parent)
            ->postJson(route('api.mobile.parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $immediate->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'linked');

        $this->actingAs($parent)
            ->postJson(route('api.mobile.parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $approval->id,
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('members', [
            'type' => 'adventurers',
            'id_data' => $immediate->id,
            'parent_id' => $parent->id,
        ]);
        $this->assertDatabaseHas('members', [
            'type' => 'adventurers',
            'id_data' => $approval->id,
            'parent_id' => null,
        ]);
        $this->assertDatabaseHas('parent_child_link_requests', [
            'parent_user_id' => $parent->id,
            'member_type' => 'adventurers',
            'id_data' => $approval->id,
            'status' => 'pending',
        ]);
    }

    private function churchAndClub(string $name): array
    {
        $church = Church::create([
            'church_name' => "$name Church",
            'email' => strtolower($name).'@example.test',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => "$name Adventurers",
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        return [$church, $club, $director];
    }

    private function parent(Church $church, Club $club, string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
    }

    private function unlinkedChild(
        Club $club,
        User $parent,
        string $name,
        ?int $ownerId = null,
        array $overrides = []
    ): MemberAdventurer {
        $detail = MemberAdventurer::create(array_merge([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'church_name' => $club->church_name,
            'director_name' => $club->director_name,
            'applicant_name' => $name,
            'birthdate' => '2017-01-01',
            'age' => 9,
            'grade' => '3',
            'mailing_address' => '1 Main Street',
            'cell_number' => '555-0100',
            'emergency_contact' => $parent->name,
            'parent_name' => $parent->name,
            'parent_cell' => '555-0101',
            'home_address' => '1 Main Street',
            'email_address' => $parent->email,
            'signature' => $parent->name,
            'status' => 'active',
        ], $overrides));

        Member::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'parent_id' => $ownerId,
            'status' => 'active',
        ]);

        return $detail;
    }
}
