<?php

namespace Tests\Feature\Auth;

use App\Models\Association;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
