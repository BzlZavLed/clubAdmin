<?php

namespace Tests\Feature;

use App\Models\AdventurerYearlyApplication;
use App\Models\AdventurerYearlyApplicationSignature;
use App\Models\Association;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssociationFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_association_sees_its_active_clubs_and_their_forms_in_submission_order(): void
    {
        [$association, $district, $director] = $this->associationContext('honors');
        [, $outsideDistrict] = $this->associationContext('honors', 'Outside');

        $club = $this->club($district, 'Alpha Adventurers', 'adventurers');
        $pathfinderClub = $this->club($district, 'Beta Pathfinders', 'pathfinders');
        $outsideClub = $this->club($outsideDistrict, 'Outside Adventurers', 'adventurers');

        $older = $this->yearlyApplication($club, '2025', '2025-09-01 10:00:00');
        $newer = $this->yearlyApplication($club, '2026', '2026-09-01 10:00:00');
        $this->yearlyApplication($outsideClub, '2026', '2026-10-01 10:00:00');

        $this->actingAs($director)
            ->get(route('association.forms'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Association/Forms')
                ->where('association.id', $association->id)
                ->has('clubs', 2)
                ->where('clubs.0.club_name', 'Alpha Adventurers')
                ->where('clubs.1.club_name', 'Beta Pathfinders')
                ->has('submissions', 2)
                ->where('submissions.0.id', $newer->id)
                ->where('submissions.1.id', $older->id)
                ->where('latest_by_club.0.latest.adventurer_yearly_application.id', $newer->id)
                ->missing('latest_by_club.0.latest.pathfinder_annual_application'));
    }

    public function test_association_can_only_download_documents_from_its_own_clubs(): void
    {
        [, $district, $director] = $this->associationContext('honors');
        [, $outsideDistrict] = $this->associationContext('honors', 'Outside');
        $inside = $this->yearlyApplication($this->club($district, 'Inside Club', 'adventurers'), '2026', now());
        $outside = $this->yearlyApplication($this->club($outsideDistrict, 'Outside Club', 'adventurers'), '2026', now());
        Storage::fake('public');
        Storage::disk('public')->put('forms/inside.docx', 'inside');
        Storage::disk('public')->put('forms/outside.docx', 'outside');
        $inside->update(['docx_path' => 'forms/inside.docx', 'docx_file_name' => 'inside.docx']);
        $outside->update(['docx_path' => 'forms/outside.docx', 'docx_file_name' => 'outside.docx']);

        $this->actingAs($director)
            ->get(route('association.forms.download', ['formType' => 'adventurer_yearly_application', 'formId' => $inside->id]))
            ->assertOk()
            ->assertDownload('inside.docx');

        $this->actingAs($director)
            ->get(route('association.forms.download', ['formType' => 'adventurer_yearly_application', 'formId' => $outside->id]))
            ->assertNotFound();
    }

    public function test_association_can_view_a_saved_form_read_only_but_not_an_outside_form(): void
    {
        [, $district, $director] = $this->associationContext('honors');
        [, $outsideDistrict] = $this->associationContext('honors', 'Outside');
        $inside = $this->yearlyApplication($this->club($district, 'Inside Club', 'adventurers'), '2026', now());
        $outside = $this->yearlyApplication($this->club($outsideDistrict, 'Outside Club', 'adventurers'), '2026', now());
        AdventurerYearlyApplicationSignature::query()->create([
            'adventurer_yearly_application_id' => $inside->id,
            'role' => 'pastor',
            'signer_name' => 'Pastor Example',
            'signer_email' => 'pastor@example.com',
            'signature_type' => 'drawn',
            'signature_path' => 'signatures/pastor.png',
            'request_token' => 'preview-signature-token',
            'requested_at' => now()->subDay(),
            'signed_at' => now(),
            'expires_at' => now()->addDays(10),
            'status' => 'signed',
        ]);

        $this->actingAs($director)
            ->get(route('association.forms.show', ['formType' => 'adventurer_yearly_application', 'formId' => $inside->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Association/FormShow')
                ->where('form.id', $inside->id)
                ->where('form.club.name', 'Inside Club')
                ->where('form.title_en', 'Adventurer Yearly Application')
                ->where('form.sections.0.fields.0.value', '2026')
                ->has('form.signatures', 1)
                ->where('form.signatures.0.role', 'pastor')
                ->where('form.signatures.0.signer_name', 'Pastor Example')
                ->where('form.signatures.0.status', 'signed')
                ->where('form.signatures.0.signature_url', fn ($url) => str_contains($url, '/storage/signatures/pastor.png')));

        $this->actingAs($director)
            ->get(route('association.forms.show', ['formType' => 'adventurer_yearly_application', 'formId' => $outside->id]))
            ->assertNotFound();
    }

    public function test_forms_page_is_not_available_for_a_folders_association(): void
    {
        [, , $director] = $this->associationContext('carpetas');

        $this->actingAs($director)
            ->get(route('association.forms'))
            ->assertNotFound();
    }

    private function associationContext(string $evaluationSystem, string $suffix = 'Home'): array
    {
        $union = Union::query()->create([
            'name' => $suffix.' Union',
            'evaluation_system' => $evaluationSystem,
            'status' => 'active',
        ]);
        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => $suffix.' Association',
            'status' => 'active',
        ]);
        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => $suffix.' District',
            'status' => 'active',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return [$association, $district, $director];
    }

    private function club(District $district, string $name, string $type): Club
    {
        return Club::query()->create([
            'club_name' => $name,
            'church_name' => $name.' Church',
            'district_id' => $district->id,
            'club_type' => $type,
            'evaluation_system' => 'honors',
            'status' => 'active',
        ]);
    }

    private function yearlyApplication(Club $club, string $year, $createdAt): AdventurerYearlyApplication
    {
        $application = AdventurerYearlyApplication::query()->create([
            'club_id' => $club->id,
            'application_year' => $year,
            'application_date' => substr((string) $createdAt, 0, 10),
            'club_name' => $club->club_name,
            'sponsoring_church' => $club->church_name,
            'delivery_status' => 'saved',
        ]);
        $application->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $application->refresh();
    }
}
