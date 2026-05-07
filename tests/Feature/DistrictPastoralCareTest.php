<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberNote;
use App\Models\MemberPastoralCare;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictPastoralCareTest extends TestCase
{
    use RefreshDatabase;

    public function test_club_can_mark_member_as_non_sda_and_district_can_manage_pastoral_care(): void
    {
        [$district, $club, $director] = $this->createDistrictClub();

        $this->actingAs($director)->post('/members', [
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
            'applicant_name' => 'Carlos Visitante',
            'birthdate' => '2014-03-15',
            'age' => 12,
            'grade' => '6',
            'mailing_address' => '123 Main St',
            'cell_number' => '(555) 111 2222',
            'emergency_contact' => 'Maria Visitante',
            'investiture_classes' => [],
            'allergies' => null,
            'physical_restrictions' => null,
            'health_history' => null,
            'parent_name' => 'Maria Visitante',
            'parent_cell' => '(555) 111 3333',
            'home_address' => '123 Main St',
            'email_address' => 'maria@example.com',
            'signature' => 'Maria Visitante',
            'is_sda' => false,
        ])->assertRedirect();

        $member = Member::query()->where('club_id', $club->id)->firstOrFail();
        $this->assertFalse((bool) $member->is_sda);
        $this->assertNull($member->baptism_date);
        $this->assertDatabaseHas('member_pastoral_care', [
            'member_id' => $member->id,
            'district_id' => $district->id,
            'status' => 'active',
        ]);

        $this->actingAs($director)
            ->getJson(route('clubs.members', $club->id))
            ->assertOk()
            ->assertJsonPath('members.0.is_sda', false);

        $mentor = $this->createSdaMentor($club);
        $pastor = User::factory()->create([
            'profile_type' => 'district_pastor',
            'role_key' => 'district_pastor',
            'scope_type' => 'district',
            'scope_id' => $district->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($pastor)->get(route('district.pastoral-care'));
        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertSame('District/PastoralCare', $page['component']);
        $this->assertSame('Carlos Visitante', $page['props']['members'][0]['name']);

        $this->actingAs($pastor)->patch(route('district.pastoral-care.update', $member), [
            'bible_study_active' => true,
            'bible_study_teacher' => 'Pr. Gomez',
            'bible_study_started_at' => '2026-05-10',
            'baptism_date' => '2026-09-20',
            'mentor_member_id' => $mentor->id,
        ])->assertRedirect();

        $this->actingAs($pastor)->post(route('district.pastoral-care.notes.store', $member), [
            'subject' => 'Visita familiar',
            'body' => 'Familia abierta a seguimiento pastoral.',
            'color' => 'blue',
        ])->assertRedirect();

        $olderNote = MemberNote::query()->where('member_id', $member->id)->firstOrFail();
        $olderNote->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $this->actingAs($pastor)->post(route('district.pastoral-care.notes.store', $member), [
            'subject' => 'Oración',
            'body' => 'Pedir apoyo de oración esta semana.',
            'color' => 'yellow',
        ])->assertRedirect();

        $member->refresh();
        $this->assertTrue((bool) $member->is_sda);
        $this->assertSame('2026-09-20', $member->baptism_date->toDateString());

        $care = MemberPastoralCare::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertTrue((bool) $care->bible_study_active);
        $this->assertSame('Pr. Gomez', $care->bible_study_teacher);
        $this->assertSame($mentor->id, $care->mentor_member_id);
        $this->assertSame('new_believer', $care->status);
        $this->assertSame('2028-03-20', $care->new_believer_until->toDateString());

        $response = $this->actingAs($pastor)->get(route('district.pastoral-care'));
        $page = $response->viewData('page');
        $this->assertSame('Oración', $page['props']['members'][0]['notes'][0]['subject']);
        $this->assertSame('Visita familiar', $page['props']['members'][0]['notes'][1]['subject']);

        $note = MemberNote::query()->where('member_id', $member->id)->where('subject', 'Visita familiar')->firstOrFail();
        $this->assertSame('Visita familiar', $note->subject);
        $this->assertSame('Familia abierta a seguimiento pastoral.', $note->body);
        $this->assertSame('blue', $note->color);

        $this->actingAs($pastor)->delete(route('district.pastoral-care.notes.destroy', $note))
            ->assertRedirect();
        $this->assertSoftDeleted('member_notes', ['id' => $note->id]);
    }

    protected function createDistrictClub(): array
    {
        $union = Union::query()->create(['name' => 'Union Pastoral', 'status' => 'active']);
        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Pastoral',
            'status' => 'active',
        ]);
        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Pastoral',
            'status' => 'active',
        ]);
        $church = Church::query()->create([
            'district_id' => $district->id,
            'church_name' => 'Central Church',
            'email' => 'central@example.com',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'status' => 'active',
        ]);
        $club = Club::query()->create([
            'user_id' => $director->id,
            'club_name' => 'Central Adventurers',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'district_id' => $district->id,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        return [$district, $club, $director];
    }

    protected function createSdaMentor(Club $club): Member
    {
        $detail = MemberAdventurer::query()->create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
            'applicant_name' => 'Ana Mentora',
            'birthdate' => '2012-02-01',
            'age' => 14,
            'grade' => '8',
            'mailing_address' => '456 Mentor St',
            'cell_number' => '(555) 222 3333',
            'emergency_contact' => 'Tutor Mentor',
            'investiture_classes' => [],
            'parent_name' => 'Tutor Mentor',
            'parent_cell' => '(555) 222 4444',
            'home_address' => '456 Mentor St',
            'email_address' => 'mentor@example.com',
            'signature' => 'Tutor Mentor',
            'status' => 'active',
        ]);

        return Member::query()->create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'class_id' => null,
            'parent_id' => null,
            'assigned_staff_id' => null,
            'status' => 'active',
            'is_sda' => true,
            'baptism_date' => '2024-01-01',
        ]);
    }
}
