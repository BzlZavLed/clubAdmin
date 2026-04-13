<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminHierarchyManagementTest extends TestCase
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

    public function test_superadmin_can_open_hierarchy_management_pages(): void
    {
        $user = $this->superadmin();

        $this->actingAs($user)
            ->get('/super-admin/unions')
            ->assertOk();

        $this->actingAs($user)
            ->get('/super-admin/associations')
            ->assertOk();

        $this->actingAs($user)
            ->get('/super-admin/districts')
            ->assertOk();
    }

    public function test_superadmin_can_create_union_association_and_district(): void
    {
        $user = $this->superadmin();

        $this->actingAs($user)
            ->post('/super-admin/unions', [
                'name' => 'Union Test',
            ])
            ->assertRedirect();

        $union = Union::query()->where('name', 'Union Test')->first();

        $this->assertNotNull($union);

        $this->actingAs($user)
            ->post('/super-admin/associations', [
                'union_id' => $union->id,
                'name' => 'Association Test',
            ])
            ->assertRedirect();

        $association = Association::query()->where('name', 'Association Test')->first();

        $this->assertNotNull($association);

        $this->actingAs($user)
            ->post('/super-admin/districts', [
                'association_id' => $association->id,
                'name' => 'District Test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('districts', [
            'association_id' => $association->id,
            'name' => 'District Test',
            'status' => 'active',
        ]);
    }

    public function test_superadmin_can_update_and_soft_delete_hierarchy_entities(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union Alpha',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Alpha',
            'status' => 'active',
        ]);

        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Alpha',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->put("/super-admin/unions/{$union->id}", [
                'name' => 'Union Beta',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->put("/super-admin/associations/{$association->id}", [
                'union_id' => $union->id,
                'name' => 'Association Beta',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->put("/super-admin/districts/{$district->id}", [
                'association_id' => $association->id,
                'name' => 'District Beta',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->delete("/super-admin/districts/{$district->id}")
            ->assertRedirect();

        $this->actingAs($user)
            ->delete("/super-admin/associations/{$association->id}")
            ->assertRedirect();

        $this->actingAs($user)
            ->delete("/super-admin/unions/{$union->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('unions', [
            'id' => $union->id,
            'name' => 'Union Beta',
            'status' => 'deleted',
        ]);

        $this->assertDatabaseHas('associations', [
            'id' => $association->id,
            'name' => 'Association Beta',
            'status' => 'deleted',
        ]);

        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'name' => 'District Beta',
            'status' => 'deleted',
        ]);
    }
}
