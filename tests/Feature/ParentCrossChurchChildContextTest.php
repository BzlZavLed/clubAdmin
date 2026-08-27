<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\ParentChildLinkRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ParentCrossChurchChildContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_only_enroll_children_in_clubs_from_their_account_church(): void
    {
        [$homeChurch, $homeClub] = $this->churchAndClub('Laurel', 'Laurel Adventurers');
        [, $secondHomeClub] = $this->churchAndClub('Laurel Second', 'Laurel Pathfinders', 'pathfinders', $homeChurch);
        [, $foreignClub] = $this->churchAndClub('Carpetas', 'Carpetas Adventurers');
        $parent = $this->parent($homeChurch, $homeClub);

        $this->actingAs($parent)
            ->get(route('parent.apply'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Apply')
                ->has('clubs', 2)
                ->where('clubs.0.id', $homeClub->id)
                ->where('clubs.1.id', $secondHomeClub->id));

        $this->actingAs($parent)
            ->post(route('parent.apply.submit'), ['club_id' => $foreignClub->id])
            ->assertForbidden();
    }

    public function test_director_created_cross_church_child_is_linked_and_drives_parent_views(): void
    {
        [$homeChurch, $homeClub] = $this->churchAndClub('Laurel', 'Laurel Adventurers');
        [$foreignChurch, $foreignClub, $foreignDirector] = $this->churchAndClub('Carpetas', 'Carpetas Adventurers');
        $foreignClub->update(['evaluation_system' => 'carpetas']);
        $parent = $this->parent($homeChurch, $homeClub);
        $homeMember = $this->child($homeClub, $parent, 'Home Child');

        $this->actingAs($foreignDirector)
            ->post(route('members.store'), $this->adventurerPayload($foreignClub, $parent, 'Foreign Parent'))
            ->assertRedirect();

        $foreignMember = Member::query()
            ->where('club_id', $foreignClub->id)
            ->where('type', 'adventurers')
            ->firstOrFail();
        $this->assertSame($parent->id, $foreignMember->parent_id);

        $this->actingAs($parent)
            ->get(route('parent.payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Payments')
                ->has('children', 2)
                ->where('children.0.name', 'Foreign Parent')
                ->where('children.0.church_name', $foreignChurch->church_name)
                ->where('children.1.name', 'Home Child')
                ->where('children.1.church_name', $homeChurch->church_name));

        $this->actingAs($parent)
            ->get(route('parent.carpeta-investidura'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/CarpetaInvestidura')
                ->has('children', 1)
                ->where('children.0.member_id', $foreignMember->id)
                ->where('children.0.church_name', $foreignChurch->church_name));

        $this->actingAs($parent)
            ->getJson(route('parent.workplan.data', ['member_id' => $foreignMember->id]))
            ->assertOk()
            ->assertJsonPath('selected_member_id', $foreignMember->id)
            ->assertJsonPath('selected_club_id', $foreignClub->id)
            ->assertJsonFragment([
                'member_id' => $foreignMember->id,
                'name' => 'Foreign Parent',
                'church_name' => $foreignChurch->church_name,
            ]);

        $unrelatedParent = User::factory()->create(['profile_type' => 'parent', 'status' => 'active']);
        $unrelatedMember = $this->child($homeClub, $unrelatedParent, 'Unrelated Child');
        $this->actingAs($parent)
            ->getJson(route('parent.workplan.data', ['member_id' => $unrelatedMember->id]))
            ->assertForbidden();

        $this->assertSame($parent->id, $homeMember->parent_id);
    }

    public function test_director_created_child_with_matching_parent_email_but_different_last_name_is_not_auto_linked(): void
    {
        [$homeChurch, $homeClub] = $this->churchAndClub('Home', 'Home Adventurers');
        [, $foreignClub, $foreignDirector] = $this->churchAndClub('Foreign', 'Foreign Adventurers');
        $parent = $this->parent($homeChurch, $homeClub)->forceFill(['name' => 'Maria Santos']);
        $parent->save();

        $this->actingAs($foreignDirector)
            ->post(route('members.store'), $this->adventurerPayload($foreignClub, $parent, 'Elena Rivera'))
            ->assertRedirect();

        $detail = MemberAdventurer::query()->where('applicant_name', 'Elena Rivera')->firstOrFail();
        $this->assertDatabaseHas('members', [
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'parent_id' => null,
        ]);
    }

    public function test_three_matches_link_immediately_two_require_director_and_fewer_are_rejected(): void
    {
        [$homeChurch, $homeClub, $homeDirector] = $this->churchAndClub('Laurel', 'Laurel Adventurers');
        [, $foreignClub, $foreignDirector] = $this->churchAndClub('Carpetas', 'Carpetas Adventurers');
        $parent = $this->parent($homeChurch, $homeClub)->forceFill([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@example.test',
        ]);
        $parent->save();

        $matching = $this->unlinkedChild($homeClub, $parent, 'Elena Santos');
        $wrongLastName = $this->unlinkedChild($homeClub, $parent, 'Elena Rivera');
        $wrongParentName = $this->unlinkedChild($homeClub, $parent, 'Lucas Santos', [
            'parent_name' => 'Mario Santos',
        ]);
        $wrongEmail = $this->unlinkedChild($homeClub, $parent, 'Rosa Santos', [
            'email_address' => 'different@example.test',
        ]);
        $oneMatch = $this->unlinkedChild($homeClub, $parent, 'Pedro Santos', [
            'parent_name' => 'Different Parent',
            'email_address' => 'different-parent@example.test',
        ]);
        $foreign = $this->unlinkedChild($foreignClub, $parent, 'Nora Santos');
        $alreadyOwned = $this->unlinkedChild($homeClub, $parent, 'Isabel Santos');
        $otherParent = User::factory()->create(['profile_type' => 'parent', 'status' => 'active']);
        Member::query()
            ->where('type', 'adventurers')
            ->where('id_data', $alreadyOwned->id)
            ->update(['parent_id' => $otherParent->id]);

        $this->actingAs($parent)
            ->getJson(route('parent.children.linkable'))
            ->assertOk()
            ->assertJsonCount(4, 'linkable')
            ->assertJsonFragment(['id_data' => $matching->id, 'matched_count' => 3, 'requires_director_approval' => false])
            ->assertJsonFragment(['id_data' => $wrongEmail->id, 'matched_count' => 2, 'requires_director_approval' => true]);

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $matching->id,
            ])
            ->assertOk();
        $this->assertDatabaseHas('members', [
            'id_data' => $matching->id,
            'parent_id' => $parent->id,
        ]);

        foreach ([$wrongLastName, $wrongParentName, $wrongEmail] as $needsDirector) {
            $this->actingAs($parent)
                ->postJson(route('parent.children.link'), [
                    'member_type' => 'adventurers',
                    'id_data' => $needsDirector->id,
                ])
                ->assertStatus(202)
                ->assertJsonPath('status', 'pending');
        }
        $this->assertDatabaseCount('parent_child_link_requests', 3);
        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $wrongEmail->id,
            ])
            ->assertStatus(202);
        $this->assertDatabaseCount('parent_child_link_requests', 3);

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $oneMatch->id,
            ])
            ->assertStatus(422);

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $foreign->id,
            ])
            ->assertForbidden();

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $alreadyOwned->id,
            ])
            ->assertStatus(409);

        $approveRequest = ParentChildLinkRequest::query()->where('id_data', $wrongEmail->id)->firstOrFail();
        $rejectRequest = ParentChildLinkRequest::query()->where('id_data', $wrongParentName->id)->firstOrFail();
        $expireRequest = ParentChildLinkRequest::query()->where('id_data', $wrongLastName->id)->firstOrFail();

        $this->actingAs($homeDirector)
            ->get(route('club.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('enrollment_confirmation_requests.child_link_requests', 3));

        $this->actingAs($foreignDirector)
            ->postJson(route('club.child-link-requests.approve', $approveRequest))
            ->assertForbidden();

        $this->actingAs($homeDirector)
            ->postJson(route('club.child-link-requests.approve', $approveRequest))
            ->assertOk();
        $this->assertDatabaseHas('parent_child_link_requests', [
            'id' => $approveRequest->id,
            'status' => 'approved',
            'decided_by_user_id' => $homeDirector->id,
        ]);
        $this->assertDatabaseHas('members', [
            'id_data' => $wrongEmail->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($homeDirector)
            ->postJson(route('club.child-link-requests.reject', $rejectRequest), [
                'decision_note' => 'Please update the registered parent name.',
            ])
            ->assertOk();
        $this->assertDatabaseHas('parent_child_link_requests', [
            'id' => $rejectRequest->id,
            'status' => 'rejected',
            'decision_note' => 'Please update the registered parent name.',
        ]);
        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $wrongParentName->id,
            ])
            ->assertStatus(409);

        $expireRequest->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($homeDirector)->get(route('club.dashboard'))->assertOk();
        $this->assertDatabaseHas('parent_child_link_requests', [
            'id' => $expireRequest->id,
            'status' => 'expired',
        ]);

        $parentChildrenResponse = $this->actingAs($parent)
            ->get(route('parent-links.index.parent'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('link_requests', 3));
        $requestStatuses = collect($parentChildrenResponse->viewData('page')['props']['link_requests'])->pluck('status');
        $this->assertTrue($requestStatuses->contains('approved'));
        $this->assertTrue($requestStatuses->contains('rejected'));
        $this->assertTrue($requestStatuses->contains('expired'));
    }

    public function test_unverified_email_does_not_count_as_an_identity_factor(): void
    {
        [$church, $club] = $this->churchAndClub('Unverified', 'Unverified Adventurers');
        $parent = $this->parent($church, $club)->forceFill([
            'name' => 'Maria Santos',
            'email' => 'unverified.santos@example.test',
            'email_verified_at' => null,
        ]);
        $parent->save();
        $child = $this->unlinkedChild($club, $parent, 'Elena Santos');

        $this->actingAs($parent)
            ->getJson(route('parent.children.linkable'))
            ->assertOk()
            ->assertJsonPath('linkable.0.matched_count', 2)
            ->assertJsonPath('linkable.0.match_factors.email', false)
            ->assertJsonPath('linkable.0.requires_director_approval', true);

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'adventurers',
                'id_data' => $child->id,
            ])
            ->assertStatus(202);
    }

    public function test_manual_pathfinder_link_requires_a_matching_guardian_name_and_email_pair(): void
    {
        [$church, $club] = $this->churchAndClub('Laurel', 'Laurel Pathfinders', 'pathfinders');
        $parent = $this->parent($church, $club)->forceFill([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@example.test',
        ]);
        $parent->save();
        $pathfinder = MemberPathfinder::create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'church_name' => $club->church_name,
            'applicant_name' => 'Daniel Santos',
            'birthdate' => '2013-01-01',
            'father_guardian_name' => 'Other Guardian',
            'father_guardian_email' => 'other@example.test',
            'father_guardian_phone' => '555-0101',
            'mother_guardian_name' => $parent->name,
            'mother_guardian_email' => $parent->email,
            'status' => 'active',
        ]);

        $this->actingAs($parent)
            ->postJson(route('parent.children.link'), [
                'member_type' => 'pathfinders',
                'id_data' => $pathfinder->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('members', [
            'type' => 'pathfinders',
            'id_data' => $pathfinder->id,
            'parent_id' => $parent->id,
        ]);
    }

    private function churchAndClub(
        string $churchName,
        string $clubName,
        string $clubType = 'adventurers',
        ?Church $church = null
    ): array {
        $church ??= Church::create([
            'church_name' => $churchName,
            'email' => strtolower(str_replace(' ', '-', $churchName)).'@example.test',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => $clubName,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => $clubType,
            'status' => 'active',
        ]);
        $director->update(['club_id' => $club->id]);
        $director->clubs()->attach($club->id, ['status' => 'active']);

        return [$church, $club, $director];
    }

    private function parent(Church $church, Club $club): User
    {
        return User::factory()->create([
            'name' => 'Test Parent',
            'email' => 'parent@example.test',
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function child(Club $club, User $parent, string $name): Member
    {
        $detail = MemberAdventurer::create($this->adventurerPayload($club, $parent, $name));

        return Member::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
    }

    private function unlinkedChild(Club $club, User $parent, string $name, array $overrides = []): MemberAdventurer
    {
        $detail = MemberAdventurer::create(array_merge(
            $this->adventurerPayload($club, $parent, $name),
            $overrides
        ));
        Member::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'parent_id' => null,
            'status' => 'active',
        ]);

        return $detail;
    }

    private function adventurerPayload(Club $club, User $parent, string $name): array
    {
        return [
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
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
            'signature_type' => 'typed',
            'signature' => $parent->name,
            'status' => 'active',
        ];
    }
}
