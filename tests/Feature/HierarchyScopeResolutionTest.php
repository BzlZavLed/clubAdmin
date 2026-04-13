<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use App\Support\ClubHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HierarchyScopeResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_district_scope_resolves_only_churches_and_clubs_within_district(): void
    {
        [$districtA, $districtB] = $this->seedTwoDistrictsInOneAssociation();

        $churchA1 = Church::create(['church_name' => 'Church A1', 'email' => 'a1@example.com', 'district_id' => $districtA->id]);
        $churchA2 = Church::create(['church_name' => 'Church A2', 'email' => 'a2@example.com', 'district_id' => $districtA->id]);
        $churchB1 = Church::create(['church_name' => 'Church B1', 'email' => 'b1@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Club A1', 'church_id' => $churchA1->id, 'church_name' => $churchA1->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Club A2', 'church_id' => $churchA2->id, 'church_name' => $churchA2->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Club B1', 'church_id' => $churchB1->id, 'church_name' => $churchB1->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $districtA->id,
            'status' => 'active',
        ]);

        $this->assertCount(2, ClubHelper::churchIdsForUser($user));
        $this->assertCount(2, ClubHelper::clubIdsForUser($user));
    }

    public function test_association_scope_resolves_churches_and_clubs_across_all_districts_in_association(): void
    {
        [$districtA, $districtB] = $this->seedTwoDistrictsInOneAssociation();

        $churchA = Church::create(['church_name' => 'Association Church A', 'email' => 'assoc-a@example.com', 'district_id' => $districtA->id]);
        $churchB = Church::create(['church_name' => 'Association Church B', 'email' => 'assoc-b@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Association Club A', 'church_id' => $churchA->id, 'church_name' => $churchA->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Association Club B', 'church_id' => $churchB->id, 'church_name' => $churchB->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $districtA->association_id,
            'status' => 'active',
        ]);

        $this->assertCount(2, ClubHelper::churchIdsForUser($user));
        $this->assertCount(2, ClubHelper::clubIdsForUser($user));
    }

    public function test_union_scope_resolves_churches_and_clubs_across_all_associations_in_union(): void
    {
        $union = Union::create(['name' => 'Union Scope', 'status' => 'active']);
        $associationA = Association::create(['name' => 'Assoc Scope A', 'union_id' => $union->id, 'status' => 'active']);
        $associationB = Association::create(['name' => 'Assoc Scope B', 'union_id' => $union->id, 'status' => 'active']);

        $districtA = District::create(['name' => 'District Scope A', 'association_id' => $associationA->id, 'status' => 'active']);
        $districtB = District::create(['name' => 'District Scope B', 'association_id' => $associationB->id, 'status' => 'active']);

        $churchA = Church::create(['church_name' => 'Union Church A', 'email' => 'union-a@example.com', 'district_id' => $districtA->id]);
        $churchB = Church::create(['church_name' => 'Union Church B', 'email' => 'union-b@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Union Club A', 'church_id' => $churchA->id, 'church_name' => $churchA->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Union Club B', 'church_id' => $churchB->id, 'church_name' => $churchB->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'union_youth_director',
            'role_key' => 'union_youth_director',
            'scope_type' => 'union',
            'scope_id' => $union->id,
            'status' => 'active',
        ]);

        $this->assertCount(2, ClubHelper::churchIdsForUser($user));
        $this->assertCount(2, ClubHelper::clubIdsForUser($user));
    }

    public function test_district_widget_data_includes_churches_and_clubs_for_current_district(): void
    {
        [$districtA, $districtB] = $this->seedTwoDistrictsInOneAssociation();

        $churchA = Church::create(['church_name' => 'Widget Church A', 'email' => 'widget-a@example.com', 'district_id' => $districtA->id]);
        $churchB = Church::create(['church_name' => 'Widget Church B', 'email' => 'widget-b@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Widget Club A', 'church_id' => $churchA->id, 'church_name' => $churchA->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Widget Club B', 'church_id' => $churchB->id, 'church_name' => $churchB->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $districtA->id,
            'status' => 'active',
        ]);

        $widget = ClubHelper::hierarchyWidgetDataForUser($user);

        $this->assertSame('district', $widget['level']);
        $this->assertCount(1, $widget['districts']);
        $this->assertSame('Widget Church A', $widget['districts'][0]['churches'][0]['name']);
        $this->assertSame('Widget Club A', $widget['districts'][0]['churches'][0]['clubs'][0]['name']);
    }

    public function test_association_widget_data_includes_all_districts_for_association(): void
    {
        [$districtA, $districtB] = $this->seedTwoDistrictsInOneAssociation();

        $churchA = Church::create(['church_name' => 'Assoc Widget Church A', 'email' => 'assoc-widget-a@example.com', 'district_id' => $districtA->id]);
        $churchB = Church::create(['church_name' => 'Assoc Widget Church B', 'email' => 'assoc-widget-b@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Assoc Widget Club A', 'church_id' => $churchA->id, 'church_name' => $churchA->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Assoc Widget Club B', 'church_id' => $churchB->id, 'church_name' => $churchB->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $districtA->association_id,
            'status' => 'active',
        ]);

        $widget = ClubHelper::hierarchyWidgetDataForUser($user);

        $this->assertSame('association', $widget['level']);
        $this->assertCount(2, $widget['districts']);
        $this->assertSame(2, $widget['summary']['churches']);
        $this->assertSame(2, $widget['summary']['clubs']);
    }

    public function test_union_widget_data_includes_associations_districts_churches_and_clubs(): void
    {
        $union = Union::create(['name' => 'Widget Union', 'status' => 'active']);
        $associationA = Association::create(['name' => 'Widget Assoc A', 'union_id' => $union->id, 'status' => 'active']);
        $associationB = Association::create(['name' => 'Widget Assoc B', 'union_id' => $union->id, 'status' => 'active']);

        $districtA = District::create(['name' => 'Widget District A', 'association_id' => $associationA->id, 'status' => 'active']);
        $districtB = District::create(['name' => 'Widget District B', 'association_id' => $associationB->id, 'status' => 'active']);

        $churchA = Church::create(['church_name' => 'Widget Union Church A', 'email' => 'widget-union-a@example.com', 'district_id' => $districtA->id]);
        $churchB = Church::create(['church_name' => 'Widget Union Church B', 'email' => 'widget-union-b@example.com', 'district_id' => $districtB->id]);
        $owner = User::factory()->create();

        Club::create(['user_id' => $owner->id, 'club_name' => 'Widget Union Club A', 'church_id' => $churchA->id, 'church_name' => $churchA->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);
        Club::create(['user_id' => $owner->id, 'club_name' => 'Widget Union Club B', 'church_id' => $churchB->id, 'church_name' => $churchB->church_name, 'director_name' => $owner->name, 'creation_date' => now()->toDateString(), 'club_type' => 'adventurers', 'status' => 'active']);

        $user = User::factory()->create([
            'profile_type' => 'union_youth_director',
            'role_key' => 'union_youth_director',
            'scope_type' => 'union',
            'scope_id' => $union->id,
            'status' => 'active',
        ]);

        $widget = ClubHelper::hierarchyWidgetDataForUser($user);

        $this->assertSame('union', $widget['level']);
        $this->assertCount(2, $widget['associations']);
        $this->assertSame(2, $widget['summary']['districts']);
        $this->assertSame(2, $widget['summary']['churches']);
        $this->assertSame(2, $widget['summary']['clubs']);
    }

    private function seedTwoDistrictsInOneAssociation(): array
    {
        $union = Union::create(['name' => 'Shared Union', 'status' => 'active']);
        $association = Association::create(['name' => 'Shared Association', 'union_id' => $union->id, 'status' => 'active']);
        $districtA = District::create(['name' => 'District A', 'association_id' => $association->id, 'status' => 'active']);
        $districtB = District::create(['name' => 'District B', 'association_id' => $association->id, 'status' => 'active']);

        return [$districtA, $districtB];
    }
}
