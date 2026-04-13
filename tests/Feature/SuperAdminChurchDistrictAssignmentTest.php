<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminChurchDistrictAssignmentTest extends TestCase
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

    public function test_superadmin_church_manage_page_loads_district_hierarchy(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union One',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association One',
            'status' => 'active',
        ]);

        District::query()->create([
            'association_id' => $association->id,
            'name' => 'District One',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/super-admin/churches/manage')
            ->assertOk();
    }

    public function test_superadmin_can_assign_a_church_to_a_district(): void
    {
        $user = $this->superadmin();

        $union = Union::query()->create([
            'name' => 'Union Two',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Two',
            'status' => 'active',
        ]);

        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Two',
            'status' => 'active',
        ]);

        $church = Church::query()->create([
            'church_name' => 'Church Alpha',
            'email' => 'church-alpha@example.com',
        ]);

        $this->actingAs($user)
            ->put("/churches/{$church->id}", [
                'district_id' => $district->id,
                'church_name' => 'Church Alpha',
                'address' => null,
                'ethnicity' => null,
                'phone_number' => null,
                'email' => 'church-alpha@example.com',
                'pastor_name' => null,
                'pastor_email' => null,
                'conference' => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('churches', [
            'id' => $church->id,
            'district_id' => $district->id,
        ]);
    }
}
