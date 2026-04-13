<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminHierarchyUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function superadmin(): User
    {
        return User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'sub_role' => null,
            'scope_type' => 'global',
            'scope_id' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_superadmin_users_page_loads_hierarchy_sources(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union Manage',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Manage',
            'status' => 'active',
        ]);

        District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Manage',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/super-admin/users')
            ->assertOk();
    }

    public function test_superadmin_can_create_district_scoped_user(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union Scope',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Scope',
            'status' => 'active',
        ]);

        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Scope',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/super-admin/users', [
                'name' => 'District Pastor',
                'email' => 'district-pastor@example.com',
                'password' => 'password',
                'profile_type' => 'district_pastor',
                'district_id' => $district->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'district-pastor@example.com',
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $district->id,
            'church_id' => null,
            'club_id' => null,
        ]);
    }

    public function test_superadmin_can_create_association_and_union_scoped_users(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union Root',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Root',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/super-admin/users', [
                'name' => 'Association Youth',
                'email' => 'association-youth@example.com',
                'password' => 'password',
                'profile_type' => 'association_youth_director',
                'association_id' => $association->id,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post('/super-admin/users', [
                'name' => 'Union Youth',
                'email' => 'union-youth@example.com',
                'password' => 'password',
                'profile_type' => 'union_youth_director',
                'union_id' => $union->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'association-youth@example.com',
            'scope_type' => 'association',
            'scope_id' => $association->id,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'union-youth@example.com',
            'scope_type' => 'union',
            'scope_id' => $union->id,
        ]);
    }
}
