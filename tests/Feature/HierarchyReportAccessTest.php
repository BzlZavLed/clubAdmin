<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HierarchyReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_district_role_can_access_report_pages(): void
    {
        $district = $this->seedDistrictHierarchy();

        $user = User::factory()->create([
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $district->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('district.reports.assistance'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('district.reports.finances'))
            ->assertOk();
    }

    public function test_scoped_report_endpoints_reject_club_outside_scope(): void
    {
        $districtA = $this->seedDistrictHierarchy('A');
        $districtB = $this->seedDistrictHierarchy('B');

        $churchA = Church::create([
            'church_name' => 'Scoped Church A',
            'email' => 'scoped-a@example.com',
            'district_id' => $districtA->id,
        ]);
        $churchB = Church::create([
            'church_name' => 'Scoped Church B',
            'email' => 'scoped-b@example.com',
            'district_id' => $districtB->id,
        ]);

        $owner = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'church',
            'scope_id' => $churchA->id,
            'church_id' => $churchA->id,
            'church_name' => $churchA->church_name,
        ]);

        $clubA = Club::create([
            'user_id' => $owner->id,
            'club_name' => 'Scoped Club A',
            'church_id' => $churchA->id,
            'church_name' => $churchA->church_name,
            'director_name' => $owner->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        $clubB = Club::create([
            'user_id' => $owner->id,
            'club_name' => 'Scoped Club B',
            'church_id' => $churchB->id,
            'church_name' => $churchB->church_name,
            'director_name' => $owner->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        $districtUser = User::factory()->create([
            'profile_type' => 'district_secretary',
            'role_key' => 'district_secretary',
            'scope_type' => 'district',
            'scope_id' => $districtA->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($districtUser)
            ->get(route('financial.preload', ['club_id' => $clubA->id]))
            ->assertOk();

        $this->actingAs($districtUser)
            ->get(route('financial.preload', ['club_id' => $clubB->id]))
            ->assertNotFound();
    }

    private function seedDistrictHierarchy(string $suffix = ''): District
    {
        $union = Union::create(['name' => 'Report Union ' . $suffix, 'status' => 'active']);
        $association = Association::create([
            'name' => 'Report Association ' . $suffix,
            'union_id' => $union->id,
            'status' => 'active',
        ]);

        return District::create([
            'name' => 'Report District ' . $suffix,
            'association_id' => $association->id,
            'status' => 'active',
        ]);
    }
}
