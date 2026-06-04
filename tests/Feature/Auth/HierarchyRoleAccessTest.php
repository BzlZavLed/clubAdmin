<?php

namespace Tests\Feature\Auth;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Member;
use App\Models\Staff;
use App\Models\StaffAdventurer;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HierarchyRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_district_pastor_can_login_and_access_district_dashboard(): void
    {
        $districtId = District::query()->insertGetId([
            'association_id' => Association::query()->insertGetId([
                'union_id' => Union::query()->insertGetId([
                    'name' => 'Union A',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'name' => 'Association A',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'District A',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $districtId,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/district/dashboard');
        $this->assertAuthenticated();

        $this->get('/district/dashboard')
            ->assertOk();
    }

    public function test_district_secretary_can_login_and_access_district_dashboard(): void
    {
        $unionId = Union::query()->insertGetId([
            'name' => 'Union B',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $associationId = Association::query()->insertGetId([
            'union_id' => $unionId,
            'name' => 'Association B',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $districtId = District::query()->insertGetId([
            'association_id' => $associationId,
            'name' => 'District B',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'profile_type' => 'district_secretary',
            'role_key' => 'district_secretary',
            'scope_type' => 'district',
            'scope_id' => $districtId,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/district/dashboard');
        $this->assertAuthenticated();

        $this->get('/district/dashboard')
            ->assertOk();
    }

    public function test_association_youth_director_can_login_and_access_association_dashboard(): void
    {
        $unionId = Union::query()->insertGetId([
            'name' => 'Union C',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $associationId = Association::query()->insertGetId([
            'union_id' => $unionId,
            'name' => 'Association C',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $associationId,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/association/dashboard');
        $this->assertAuthenticated();

        $this->get('/association/dashboard')
            ->assertOk();
    }

    public function test_union_youth_director_can_login_and_access_union_dashboard(): void
    {
        $unionId = Union::query()->insertGetId([
            'name' => 'Union D',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'profile_type' => 'union_youth_director',
            'role_key' => 'union_youth_director',
            'scope_type' => 'union',
            'scope_id' => $unionId,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/union/dashboard');
        $this->assertAuthenticated();

        $this->get('/union/dashboard')
            ->assertOk();
    }

    public function test_treasurer_can_access_finances_but_not_director_or_personal_areas(): void
    {
        $church = Church::query()->create([
            'church_name' => 'Treasurer Church',
        ]);

        $club = Club::query()->create([
            'user_id' => null,
            'club_name' => 'Treasurer Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => null,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $treasurer = User::factory()->create([
            'profile_type' => 'treasurer',
            'role_key' => 'treasurer',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        DB::table('club_user')->insert([
            'club_id' => $club->id,
            'user_id' => $treasurer->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $treasurer->email,
            'password' => 'password',
        ])->assertRedirect('/club-director/finance/cashbox');

        $this->actingAs($treasurer)
            ->get(route('club.director.finance.cashbox', ['club_id' => $club->id]))
            ->assertOk();

        $this->actingAs($treasurer)
            ->getJson(route('club.finance-engine.actionables', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.scope.role', 'treasurer');

        $this->actingAs($treasurer)
            ->getJson(route('club.members'))
            ->assertForbidden();

        $this->actingAs($treasurer)
            ->getJson(route('club.staff'))
            ->assertForbidden();

        $this->actingAs($treasurer)
            ->getJson(route('club.my-club'))
            ->assertForbidden();

        $this->actingAs($treasurer)
            ->getJson(route('clubPersonal.dashboard'))
            ->assertForbidden();
    }

    public function test_club_director_can_promote_club_user_to_treasurer_from_staff_management(): void
    {
        $church = Church::query()->create([
            'church_name' => 'Promotion Church',
        ]);

        $club = Club::query()->create([
            'user_id' => null,
            'club_name' => 'Promotion Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => null,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club->update([
            'user_id' => $director->id,
            'director_name' => $director->name,
        ]);

        $staffUser = User::factory()->create([
            'profile_type' => 'club_personal',
            'role_key' => 'club_personal',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        DB::table('club_user')->insert([
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($director)
            ->postJson(route('staff.makeTreasurer', $staffUser), [
                'club_id' => $club->id,
            ])
            ->assertOk()
            ->assertJsonPath('profile_type', 'treasurer');

        $staffUser->refresh();

        $this->assertSame('treasurer', $staffUser->profile_type);
        $this->assertSame('treasurer', $staffUser->role_key);
        $this->assertSame('club', $staffUser->scope_type);
        $this->assertSame($club->id, $staffUser->scope_id);
        $this->assertSame($club->id, $staffUser->club_id);

        $this->actingAs($staffUser)
            ->getJson(route('club.finance-engine.actionables', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.scope.role', 'treasurer');

        $this->actingAs($staffUser)
            ->getJson(route('club.staff'))
            ->assertForbidden();
    }

    public function test_staff_account_table_hides_create_staff_when_staff_profile_matches_by_email(): void
    {
        $church = Church::query()->create([
            'church_name' => 'Staff Match Church',
        ]);

        $club = Club::query()->create([
            'user_id' => null,
            'club_name' => 'Staff Match Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => null,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club->update([
            'user_id' => $director->id,
            'director_name' => $director->name,
        ]);

        $staffUser = User::factory()->create([
            'name' => 'Existing Staff',
            'email' => 'existing.staff@example.test',
            'profile_type' => 'treasurer',
            'role_key' => 'treasurer',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $staffDetail = StaffAdventurer::query()->create([
            'date_of_record' => now()->toDateString(),
            'name' => $staffUser->name,
            'dob' => '1990-01-01',
            'address' => '123 Staff St',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33101',
            'cell_phone' => '555-0100',
            'church_name' => $church->church_name,
            'club_name' => $club->club_name,
            'email' => $staffUser->email,
            'club_id' => $club->id,
            'has_health_limitation' => false,
            'unlawful_sexual_conduct' => '0',
            'sterling_volunteer_completed' => true,
            'applicant_signature' => $staffUser->name,
            'application_signed_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        Staff::query()->create([
            'type' => 'adventurers',
            'id_data' => $staffDetail->id,
            'club_id' => $club->id,
            'user_id' => null,
            'status' => 'active',
        ]);

        DB::table('club_user')->insert([
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->actingAs($director)
            ->getJson(route('clubs.staff', ['clubId' => $club->id, 'churchId' => $church->id]))
            ->assertOk()
            ->json();

        $account = collect($payload['sub_role_users'])
            ->firstWhere('id', $staffUser->id);

        $this->assertNotNull($account);
        $this->assertFalse($account['create_staff']);
    }

    public function test_club_director_can_promote_existing_parent_account_to_treasurer(): void
    {
        $church = Church::query()->create([
            'church_name' => 'Parent Treasurer Church',
        ]);

        $club = Club::query()->create([
            'user_id' => null,
            'club_name' => 'Parent Treasurer Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => null,
            'creation_date' => now()->toDateString(),
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club->update([
            'user_id' => $director->id,
            'director_name' => $director->name,
        ]);

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'scope_type' => null,
            'scope_id' => null,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Member::query()->create([
            'type' => 'pathfinders',
            'id_data' => 1,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $this->actingAs($director)
            ->postJson(route('staff.makeTreasurer', $parent), [
                'club_id' => $club->id,
            ])
            ->assertOk()
            ->assertJsonPath('profile_type', 'treasurer');

        $parent->refresh();

        $this->assertSame('treasurer', $parent->profile_type);
        $this->assertSame('treasurer', $parent->role_key);
        $this->assertSame('club', $parent->scope_type);
        $this->assertSame($club->id, $parent->scope_id);
        $this->assertSame($club->id, $parent->club_id);

        $this->assertDatabaseHas('club_user', [
            'club_id' => $club->id,
            'user_id' => $parent->id,
            'status' => 'active',
        ]);
    }
}
