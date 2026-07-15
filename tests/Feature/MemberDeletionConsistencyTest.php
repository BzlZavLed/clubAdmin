<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberMasterGuide;
use App\Models\MemberPathfinder;
use App\Models\PaymentConcept;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDeletionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_adventurer_marks_detail_and_unified_member_deleted(): void
    {
        [$director, $club] = $this->directorAndClub();
        $detail = $this->adventurer($club);
        $member = $this->unified($club, 'adventurers', $detail->id);

        $this->actingAs($director)->deleteJson(route('members.destroy', $detail->id), [
            'member_type' => 'adventurers', 'member_record_id' => $member->id, 'notes_deleted' => 'Duplicate record',
        ])->assertOk();

        $this->assertDatabaseHas('members_adventurers', ['id' => $detail->id, 'status' => 'deleted', 'notes_deleted' => 'Duplicate record']);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'status' => 'deleted']);
    }

    public function test_deleting_a_temp_pathfinder_marks_detail_and_unified_member_deleted(): void
    {
        [$director, $club] = $this->directorAndClub();
        $detail = MemberPathfinder::create(['club_id' => $club->id, 'applicant_name' => 'Pathfinder']);
        $member = $this->unified($club, 'temp_pathfinder', $detail->id);

        $this->actingAs($director)->deleteJson(route('members.destroy', $detail->id), [
            'member_type' => 'temp_pathfinder', 'member_record_id' => $member->id,
        ])->assertOk();

        $this->assertDatabaseHas('members_pathfinders', ['id' => $detail->id, 'status' => 'deleted']);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'status' => 'deleted']);
    }

    public function test_deleting_a_master_guide_marks_detail_and_unified_member_deleted(): void
    {
        [$director, $club] = $this->directorAndClub();
        $detail = MemberMasterGuide::create(['club_id' => $club->id, 'applicant_name' => 'Master Guide']);
        $member = $this->unified($club, 'master_guide', $detail->id);
        $detail->update(['member_id' => $member->id]);

        $this->actingAs($director)->deleteJson(route('members.destroy', $detail->id), [
            'member_type' => 'master_guide', 'member_record_id' => $member->id, 'notes_deleted' => 'Moved away',
        ])->assertOk();

        $this->assertDatabaseHas('member_master_guides', ['id' => $detail->id, 'status' => 'deleted', 'notes_deleted' => 'Moved away']);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'status' => 'deleted']);
    }

    public function test_delete_rolls_back_detail_update_when_the_matching_unified_member_is_missing(): void
    {
        [$director, $club] = $this->directorAndClub();
        $detail = $this->adventurer($club);
        $unrelated = $this->unified($club, 'pathfinders', $detail->id);

        $this->actingAs($director)->deleteJson(route('members.destroy', $detail->id), [
            'member_type' => 'adventurers', 'member_record_id' => $unrelated->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('members_adventurers', ['id' => $detail->id, 'status' => 'active']);
        $this->assertDatabaseHas('members', ['id' => $unrelated->id, 'status' => 'active']);
    }

    public function test_removing_a_member_charge_excludes_only_that_member_without_deactivating_the_shared_concept(): void
    {
        [$director, $club] = $this->directorAndClub();
        $firstDetail = $this->adventurer($club);
        $firstMember = $this->unified($club, 'adventurers', $firstDetail->id);
        $secondDetail = $this->adventurer($club);
        $secondMember = $this->unified($club, 'adventurers', $secondDetail->id);
        $concept = PaymentConcept::create([
            'club_id' => $club->id, 'concept' => 'Club fee', 'amount' => 25,
            'type' => 'mandatory', 'pay_to' => 'club_budget', 'status' => 'active', 'created_by' => $director->id,
        ]);
        $concept->scopes()->create(['scope_type' => 'club_wide', 'club_id' => $club->id]);

        $this->actingAs($director)
            ->deleteJson(route('members.charges.destroy', ['member' => $firstMember->id, 'paymentConcept' => $concept->id]))
            ->assertOk();

        $this->assertDatabaseHas('payment_concepts', ['id' => $concept->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_concept_scopes', [
            'payment_concept_id' => $concept->id, 'scope_type' => 'member_excluded', 'member_id' => $firstMember->id,
        ]);
        $this->actingAs($director)->getJson(route('members.charges.index', $firstMember))->assertJsonCount(0, 'data.charges');
        $this->actingAs($director)->getJson(route('members.charges.index', $secondMember))->assertJsonCount(1, 'data.charges');
    }

    private function directorAndClub(): array
    {
        $director = User::factory()->create(['profile_type' => 'club_director', 'role_key' => 'club_director', 'status' => 'active']);
        $club = Club::create([
            'user_id' => $director->id, 'club_name' => 'Consistency Club', 'church_name' => 'Church',
            'director_name' => $director->name, 'club_type' => 'adventurers', 'status' => 'active',
        ]);
        $director->update(['club_id' => $club->id]);

        return [$director, $club];
    }

    private function adventurer(Club $club): MemberAdventurer
    {
        return MemberAdventurer::create([
            'club_id' => $club->id, 'club_name' => $club->club_name, 'director_name' => $club->director_name,
            'church_name' => $club->church_name, 'applicant_name' => 'Adventurer', 'birthdate' => '2015-01-01',
            'age' => 10, 'grade' => '5', 'mailing_address' => 'Address', 'cell_number' => '555-0100',
            'emergency_contact' => 'Parent', 'parent_name' => 'Parent', 'parent_cell' => '555-0101',
            'home_address' => 'Address', 'email_address' => 'parent@example.test', 'signature' => 'Parent', 'status' => 'active',
        ]);
    }

    private function unified(Club $club, string $type, int $detailId): Member
    {
        return Member::create(['type' => $type, 'id_data' => $detailId, 'club_id' => $club->id, 'status' => 'active']);
    }
}
