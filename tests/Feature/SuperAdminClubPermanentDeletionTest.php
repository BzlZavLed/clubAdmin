<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\Staff;
use App\Models\TreasuryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminClubPermanentDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_must_generate_archive_then_clean_data_then_delete_club(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'password' => Hash::make('super-password'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        [$club, $director] = $this->club('Archive Club');
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $detail = MemberAdventurer::create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $director->name,
            'church_name' => $club->church_name,
            'applicant_name' => 'Archive Child',
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
            'signature_type' => 'typed',
            'status' => 'active',
        ]);
        $member = Member::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $staff = Staff::create([
            'type' => 'adventurers',
            'id_data' => 999999,
            'club_id' => $club->id,
            'user_id' => $director->id,
            'status' => 'active',
        ]);
        TreasuryMovement::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'created_by_user_id' => $director->id,
            'movement_type' => TreasuryMovement::TYPE_CASH_DEPOSIT,
            'to_location' => TreasuryMovement::LOCATION_BANK,
            'amount' => 125.50,
            'movement_date' => '2026-08-01',
            'reference' => 'ARCHIVE-TEST',
        ]);

        $this->actingAs($superadmin)
            ->deleteJson(route('superadmin.clubs.data.clean', $club->id), [
                'current_password' => 'super-password',
                'confirmation' => 'DELETE CLUB DATA '.$club->club_name,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('archive');

        $archive = $this->get(route('superadmin.clubs.financial-archive', $club->id));
        $archive->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertHeader('x-club-financial-archive', 'generated');

        $clean = $this->deleteJson(route('superadmin.clubs.data.clean', $club->id), [
            'current_password' => 'super-password',
            'confirmation' => 'DELETE CLUB DATA '.$club->club_name,
        ])->assertOk()
            ->assertJsonPath('summary.members_deleted', 1)
            ->assertJsonPath('summary.staff_deleted', 1)
            ->assertJsonPath('summary.payments_deleted', 0);

        $this->assertDatabaseMissing('members', ['id' => $member->id]);
        $this->assertDatabaseMissing('members_adventurers', ['id' => $detail->id]);
        $this->assertDatabaseMissing('staff', ['id' => $staff->id]);
        $this->assertDatabaseMissing('treasury_movements', ['club_id' => $club->id]);
        $this->assertDatabaseMissing('users', ['id' => $parent->id]);
        $this->assertDatabaseMissing('users', ['id' => $director->id]);
        $this->assertDatabaseHas('clubs', ['id' => $club->id, 'status' => 'inactive', 'user_id' => null]);

        $this->deleteJson(route('superadmin.clubs.delete', $club->id), [
            'current_password' => 'super-password',
            'confirmation' => 'DELETE CLUB '.$club->club_name,
        ])->assertOk()
            ->assertJsonPath('redirect_url', route('superadmin.clubs.manage'));

        $this->assertDatabaseMissing('clubs', ['id' => $club->id]);
        $this->assertDatabaseHas('users', ['id' => $superadmin->id]);
    }

    public function test_non_superadmin_cannot_access_archive_or_deletion_endpoints(): void
    {
        [$club, $director] = $this->club('Protected Club');

        $this->actingAs($director)
            ->get(route('superadmin.clubs.financial-archive', $club->id))
            ->assertRedirect();

        $this->deleteJson(route('superadmin.clubs.data.clean', $club->id), [
            'current_password' => 'password',
            'confirmation' => 'DELETE CLUB DATA '.$club->club_name,
        ])->assertForbidden();

        $this->assertDatabaseHas('clubs', ['id' => $club->id]);
    }

    public function test_user_with_a_child_in_another_club_is_detached_but_not_deleted(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'password' => Hash::make('super-password'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        [$targetClub] = $this->club('Target Club');
        [$otherClub] = $this->club('Other Club');
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'club_id' => $targetClub->id,
            'church_id' => $targetClub->church_id,
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $targetMember = Member::create([
            'type' => 'adventurers', 'id_data' => 900001, 'club_id' => $targetClub->id,
            'parent_id' => $parent->id, 'status' => 'active',
        ]);
        $otherMember = Member::create([
            'type' => 'adventurers', 'id_data' => 900002, 'club_id' => $otherClub->id,
            'parent_id' => $parent->id, 'status' => 'active',
        ]);

        $this->actingAs($superadmin)->get(route('superadmin.clubs.financial-archive', $targetClub->id))->assertOk();
        $this->deleteJson(route('superadmin.clubs.data.clean', $targetClub->id), [
            'current_password' => 'super-password',
            'confirmation' => 'DELETE CLUB DATA '.$targetClub->club_name,
        ])->assertOk()
            ->assertJsonPath('summary.cross_club_users_preserved', 1);

        $this->assertDatabaseHas('users', [
            'id' => $parent->id,
            'club_id' => $otherClub->id,
            'church_id' => $otherClub->church_id,
            'church_name' => $otherClub->church_name,
        ]);
        $this->assertDatabaseMissing('members', ['id' => $targetMember->id]);
        $this->assertDatabaseHas('members', ['id' => $otherMember->id, 'parent_id' => $parent->id]);
    }

    public function test_cleanup_is_blocked_if_financial_data_changes_after_archive_download(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'password' => Hash::make('super-password'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        [$club, $director] = $this->club('Changing Finance Club');

        $this->actingAs($superadmin)->get(route('superadmin.clubs.financial-archive', $club->id))->assertOk();
        TreasuryMovement::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'created_by_user_id' => $director->id,
            'movement_type' => TreasuryMovement::TYPE_CASH_DEPOSIT,
            'to_location' => TreasuryMovement::LOCATION_CASH,
            'amount' => 10,
            'movement_date' => '2026-08-27',
        ]);

        $this->deleteJson(route('superadmin.clubs.data.clean', $club->id), [
            'current_password' => 'super-password',
            'confirmation' => 'DELETE CLUB DATA '.$club->club_name,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('archive');

        $this->assertDatabaseHas('clubs', ['id' => $club->id, 'status' => 'active']);
        $this->assertDatabaseHas('treasury_movements', ['club_id' => $club->id, 'amount' => 10]);
    }

    private function club(string $name): array
    {
        $church = Church::create(['church_name' => $name.' Church']);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'password' => Hash::make('password'),
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => $name,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $director->update(['club_id' => $club->id]);
        DB::table('club_user')->insert([
            'club_id' => $club->id,
            'user_id' => $director->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$club, $director];
    }
}
