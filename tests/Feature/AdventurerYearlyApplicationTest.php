<?php

namespace Tests\Feature;

use App\Mail\AdventurerYearlyApplicationMail;
use App\Mail\AdventurerYearlyApplicationSignatureRequestMail;
use App\Models\AdventurerYearlyApplication;
use App\Models\AdventurerYearlyApplicationSignature;
use App\Models\Church;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdventurerYearlyApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adventurer_honors_director_can_submit_download_and_email_yearly_application(): void
    {
        Storage::fake('public');
        Mail::fake();

        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');
        $payload = $this->payload();

        $storeResponse = $this->actingAs($director)
            ->postJson(route('clubs.adventurer-yearly-applications.store', $club), $payload)
            ->assertOk()
            ->assertJsonPath('data.application_year', '2026')
            ->assertJsonPath('data.club_name', 'Little Lights');

        $application = AdventurerYearlyApplication::query()->firstOrFail();
        $this->assertCount(4, $application->signatures);
        $this->assertNotNull($application->docx_path);
        Storage::disk('public')->assertExists($application->docx_path);
        $this->assertSame(5, count($application->other_board_members));

        $this->actingAs($director)
            ->get(route('clubs.adventurer-yearly-applications.download', [$club, $application]))
            ->assertOk()
            ->assertDownload($application->docx_file_name);

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-yearly-applications.send', [$club, $application]), [
                'email' => 'conference@example.com',
            ])
            ->assertUnprocessable();

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-yearly-applications.director-signature', [$club, $application]), [
                'signature_type' => 'typed',
                'signer_name' => 'Jamie Director',
                'signature_text' => 'Jamie Director',
            ])
            ->assertOk();

        foreach (['pastor', 'head_elder', 'church_clerk'] as $role) {
            $this->actingAs($director)
                ->postJson(route('clubs.adventurer-yearly-applications.signature-requests', [$club, $application]), [
                    'role' => $role,
                    'name' => ucfirst(str_replace('_', ' ', $role)),
                    'email' => $role.'@example.com',
                ])
                ->assertOk();

            $signature = AdventurerYearlyApplicationSignature::query()
                ->where('adventurer_yearly_application_id', $application->id)
                ->where('role', $role)
                ->firstOrFail();

            $this->postJson(route('adventurer-yearly-applications.signatures.submit', $signature->request_token), [
                'signer_name' => $signature->signer_name,
                'signature_data' => $this->signaturePng(),
                'acknowledged' => true,
            ])->assertOk();
        }

        $generatedApplication = $application->fresh();
        $archive = new ZipArchive;
        $this->assertTrue(
            $archive->open(Storage::disk('public')->path($generatedApplication->docx_path)) === true
        );
        $documentXml = $archive->getFromName('word/document.xml');
        $this->assertIsString($documentXml);
        $this->assertStringContainsString('<w:drawing>', $documentXml);
        $this->assertStringNotContainsString('<w:pict>', $documentXml);
        $embeddedImages = collect(range(0, $archive->numFiles - 1))
            ->map(fn (int $index) => $archive->getNameIndex($index))
            ->filter(fn ($name) => str_starts_with((string) $name, 'word/media/'));
        $this->assertCount(4, $embeddedImages);
        $archive->close();

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-yearly-applications.send', [$club, $application]), [
                'email' => 'conference@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.delivery_status', 'sent')
            ->assertJsonPath('data.last_sent_to_email', 'conference@example.com');

        Mail::assertSent(AdventurerYearlyApplicationMail::class, function ($mail) {
            return $mail->hasTo('conference@example.com');
        });
        Mail::assertSent(AdventurerYearlyApplicationSignatureRequestMail::class, 3);
    }

    public function test_yearly_application_is_rejected_for_non_adventurer_or_folders_clubs(): void
    {
        Storage::fake('public');

        [$pathfinderDirector, $pathfinderClub] = $this->makeDirectorAndClub('pathfinders', 'honors');
        $this->actingAs($pathfinderDirector)
            ->postJson(route('clubs.adventurer-yearly-applications.store', $pathfinderClub), $this->payload())
            ->assertNotFound();

        [$foldersDirector, $foldersClub] = $this->makeDirectorAndClub('adventurers', 'carpetas');
        $this->actingAs($foldersDirector)
            ->postJson(route('clubs.adventurer-yearly-applications.store', $foldersClub), $this->payload())
            ->assertNotFound();
    }

    public function test_pathfinder_application_authorization_accepts_collection_of_club_ids(): void
    {
        Storage::fake('public');
        [$director, $club] = $this->makeDirectorAndClub('pathfinders', 'honors');

        $this->actingAs($director)
            ->postJson(route('clubs.pathfinder-annual-applications.store', $club), [
                'application_year' => '2026',
                'sponsoring_church' => 'Grace Church',
                'pastor' => 'Pastor Grace',
                'elected_club_director' => 'Jamie Director',
                'mailing_address' => '100 Main Street',
                'director_phone_number' => '410-555-0101',
            ])
            ->assertOk();
    }

    private function makeDirectorAndClub(string $clubType, string $evaluationSystem): array
    {
        $church = Church::create([
            'church_name' => 'Grace Church '.uniqid(),
            'pastor_name' => 'Pastor Grace',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Little Lights',
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'pastor_name' => $church->pastor_name,
            'club_type' => $clubType,
            'evaluation_system' => $evaluationSystem,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club->users()->attach($director->id, ['status' => 'active']);
        $director->forceFill(['club_id' => $club->id])->save();

        return [$director, $club];
    }

    private function payload(): array
    {
        return [
            'application_year' => '2026',
            'application_date' => '2026-08-04',
            'club_name' => 'Little Lights',
            'sponsoring_church' => 'Grace Church',
            'pastor' => 'Pastor Grace',
            'elected_club_director' => 'Jamie Director',
            'email_address' => 'director@example.com',
            'cell_number' => '410-555-0101',
            'home_address' => '100 Main Street, Baltimore, MD',
            'church_pastor_signature' => 'Pastor Grace',
            'head_elder_signature' => 'Alex Elder',
            'church_clerk_signature' => 'Taylor Clerk',
            'club_director_signature' => 'Jamie Director',
            'signature_date' => '2026-08-04',
            'other_board_members' => ['Jordan One', 'Morgan Two'],
        ];
    }

    private function signaturePng(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL4WQAAAABJRU5ErkJggg==';
    }
}
