<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\User;
use App\Models\Workplan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentWorkplanExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_exports_require_an_owned_child_and_restrict_class_plans_to_that_child(): void
    {
        [$parent, $member, $club, $ownClass, $otherClass] = $this->workplanContext();

        foreach (['parent.workplan.pdf', 'parent.workplan.ics', 'parent.workplan.class-plans.pdf'] as $routeName) {
            $this->actingAs($parent)
                ->getJson(route($routeName, ['club_id' => $club->id]))
                ->assertUnprocessable();
        }

        $otherParent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'status' => 'active',
        ]);
        $otherMember = Member::query()->create([
            'type' => 'adventurers',
            'id_data' => 880002,
            'club_id' => $club->id,
            'class_id' => $otherClass->id,
            'parent_id' => $otherParent->id,
            'status' => 'active',
        ]);

        foreach (['parent.workplan.pdf', 'parent.workplan.ics', 'parent.workplan.class-plans.pdf'] as $routeName) {
            $this->actingAs($parent)
                ->getJson(route($routeName, ['club_id' => $club->id, 'member_id' => $otherMember->id]))
                ->assertForbidden();
        }

        $this->actingAs($parent)
            ->getJson(route('parent.workplan.class-plans.pdf', [
                'club_id' => $club->id,
                'member_id' => $member->id,
                'class_id' => $otherClass->id,
            ]))
            ->assertForbidden();

        $this->actingAs($parent)
            ->get(route('parent.workplan.pdf', ['club_id' => $club->id, 'member_id' => $member->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($parent)
            ->get(route('parent.workplan.ics', ['club_id' => $club->id, 'member_id' => $member->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8')
            ->assertSee('BEGIN:VCALENDAR');

        $this->actingAs($parent)
            ->get(route('parent.workplan.class-plans.pdf', [
                'club_id' => $club->id,
                'member_id' => $member->id,
                'class_id' => $ownClass->id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function workplanContext(): array
    {
        $church = Church::query()->create([
            'church_name' => 'Export Church',
            'email' => 'export-church@example.test',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club = Club::query()->create([
            'user_id' => $director->id,
            'club_name' => 'Export Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $director->forceFill(['club_id' => $club->id])->save();
        $ownClass = ClubClass::query()->create([
            'club_id' => $club->id,
            'class_order' => 1,
            'class_name' => 'Own Class',
        ]);
        $otherClass = ClubClass::query()->create([
            'club_id' => $club->id,
            'class_order' => 2,
            'class_name' => 'Other Class',
        ]);
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $member = Member::query()->create([
            'type' => 'adventurers',
            'id_data' => 880001,
            'club_id' => $club->id,
            'class_id' => $ownClass->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $workplan = Workplan::query()->create([
            'club_id' => $club->id,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'timezone' => 'America/New_York',
        ]);
        $workplan->events()->create([
            'date' => now()->addWeek()->toDateString(),
            'meeting_type' => 'sabbath',
            'title' => 'Authorized family calendar event',
            'status' => 'active',
            'created_by' => $director->id,
        ]);

        return [$parent, $member, $club, $ownClass, $otherClass];
    }
}
