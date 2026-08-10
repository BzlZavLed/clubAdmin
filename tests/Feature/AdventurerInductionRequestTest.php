<?php

namespace Tests\Feature;

use App\Mail\AdventurerInductionRequestMail;
use App\Models\AdventurerInductionRequest;
use App\Models\Church;
use App\Models\Club;
use App\Models\MailDeliveryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdventurerInductionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_adventurer_honors_director_can_save_update_and_download_induction_request(): void
    {
        Storage::fake('public');
        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');

        $storeResponse = $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.store', $club), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.requested_attendee', 'Area Coordinator')
            ->assertJsonPath('data.induction_date', '2026-10-24')
            ->assertJsonPath('data.induction_time', '18:30')
            ->assertJsonPath('data.status', 'submitted');

        $inductionRequest = AdventurerInductionRequest::query()->firstOrFail();
        $this->assertNotNull($inductionRequest->received_at);
        $this->assertNotNull($inductionRequest->docx_path);
        Storage::disk('public')->assertExists($inductionRequest->docx_path);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open(Storage::disk('public')->path($inductionRequest->docx_path)) === true);
        $documentXml = $archive->getFromName('word/document.xml');
        $this->assertIsString($documentXml);
        $this->assertStringContainsString('Adventurer Induction Attendance', $documentXml);
        $this->assertStringContainsString('Area Coordinator', $documentXml);
        $this->assertStringContainsString('Grace Church Fellowship Hall', $documentXml);
        $this->assertStringContainsString('For Office Use Only', $documentXml);
        $archive->close();

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.store', $club), [
                ...$this->payload(),
                'id' => $inductionRequest->id,
                'induction_place' => 'Grace Church Sanctuary',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $inductionRequest->id)
            ->assertJsonPath('data.induction_place', 'Grace Church Sanctuary');

        $this->assertSame(1, AdventurerInductionRequest::query()->count());

        $this->actingAs($director)
            ->get(route('clubs.adventurer-induction-requests.download', [$club, $inductionRequest]))
            ->assertOk()
            ->assertDownload($inductionRequest->docx_file_name);

        $this->assertSame($inductionRequest->id, $storeResponse->json('data.id'));
    }

    public function test_induction_request_is_rejected_for_ineligible_clubs(): void
    {
        Storage::fake('public');
        [$pathfinderDirector, $pathfinderClub] = $this->makeDirectorAndClub('pathfinders', 'honors');
        $this->actingAs($pathfinderDirector)
            ->postJson(route('clubs.adventurer-induction-requests.store', $pathfinderClub), $this->payload())
            ->assertNotFound();

        [$foldersDirector, $foldersClub] = $this->makeDirectorAndClub('adventurers', 'carpetas');
        $this->actingAs($foldersDirector)
            ->postJson(route('clubs.adventurer-induction-requests.store', $foldersClub), $this->payload())
            ->assertNotFound();
    }

    public function test_director_can_email_generated_induction_request_to_a_given_address(): void
    {
        Storage::fake('public');
        Mail::fake();
        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.store', $club), $this->payload())
            ->assertOk();
        $inductionRequest = AdventurerInductionRequest::query()->firstOrFail();

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.send', [$club, $inductionRequest]), [
                'email' => 'coordinator@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.last_sent_to_email', 'coordinator@example.com')
            ->assertJsonPath('data.status', 'emailed')
            ->assertJsonPath('mail.status', 'sent')
            ->assertJsonPath('mail.recipient_email', 'coordinator@example.com');

        $inductionRequest->refresh();
        $this->assertNotNull($inductionRequest->emailed_at);
        $this->assertSame('coordinator@example.com', $inductionRequest->last_sent_to_email);
        $this->assertSame('emailed', $inductionRequest->status);
        $this->assertDatabaseHas('mail_delivery_logs', [
            'mail_key' => 'adventurer_induction_request',
            'recipient_email' => 'coordinator@example.com',
            'status' => 'sent',
            'loggable_type' => $inductionRequest->getMorphClass(),
            'loggable_id' => $inductionRequest->id,
        ]);

        Mail::assertSent(AdventurerInductionRequestMail::class, function (AdventurerInductionRequestMail $mail) use ($inductionRequest) {
            return $mail->hasTo('coordinator@example.com')
                && count($mail->attachments()) === 1
                && $mail->inductionRequest->is($inductionRequest);
        });
    }

    public function test_induction_request_email_requires_a_valid_address_and_matching_club(): void
    {
        Storage::fake('public');
        Mail::fake();
        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');
        [, $otherClub] = $this->makeDirectorAndClub('adventurers', 'honors');

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.store', $club), $this->payload())
            ->assertOk();
        $inductionRequest = AdventurerInductionRequest::query()
            ->where('club_id', $club->id)
            ->firstOrFail();

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.send', [$club, $inductionRequest]), [
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-induction-requests.send', [$otherClub, $inductionRequest]), [
                'email' => 'coordinator@example.com',
            ])
            ->assertForbidden();

        Mail::assertNothingSent();
        $this->assertSame(0, MailDeliveryLog::query()->count());
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
            'requested_attendee' => 'Area Coordinator',
            'club_name' => 'Little Lights',
            'induction_date' => '2026-10-24',
            'induction_time' => '18:30',
            'induction_place' => 'Grace Church Fellowship Hall',
            'directions' => 'Enter through the east parking lot and use the fellowship hall entrance.',
        ];
    }
}
