<?php

namespace Tests\Feature;

use App\Models\AdventurerQuarterlyReport;
use App\Models\Church;
use App\Models\Club;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdventurerQuarterlyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_adventurer_honors_director_can_save_score_and_download_quarterly_report(): void
    {
        Storage::fake('public');
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');

        $response = $this->actingAs($director)
            ->postJson(route('clubs.adventurer-quarterly-reports.store', $club), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.reporting_period', 'sep_oct')
            ->assertJsonPath('data.membership_total', 21)
            ->assertJsonPath('data.staff_total', 8)
            ->assertJsonPath('data.submitted_on_time', true)
            ->assertJsonPath('data.total_points', 300);

        $report = AdventurerQuarterlyReport::query()->firstOrFail();
        $this->assertSame('2026-11-01', $report->due_date->toDateString());
        $this->assertSame(30, $report->meetings_points);
        $this->assertSame(60, $report->attendance_points);
        $this->assertNotNull($report->docx_path);
        Storage::disk('public')->assertExists($report->docx_path);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open(Storage::disk('public')->path($report->docx_path)) === true);
        $documentXml = $archive->getFromName('word/document.xml');
        $this->assertIsString($documentXml);
        $this->assertStringContainsString('Quarterly Report', $documentXml);
        $this->assertStringContainsString('Community food drive', $documentXml);
        $this->assertStringContainsString('TOTAL POINTS', $documentXml);
        $this->assertStringContainsString('<w:drawing>', $documentXml);
        $archive->close();

        $this->actingAs($director)
            ->get(route('clubs.adventurer-quarterly-reports.download', [$club, $report]))
            ->assertOk()
            ->assertDownload($report->docx_file_name);

        $this->assertSame($report->id, $response->json('data.id'));
    }

    public function test_same_club_year_and_period_updates_existing_report(): void
    {
        Storage::fake('public');
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        [$director, $club] = $this->makeDirectorAndClub('adventurers', 'honors');

        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-quarterly-reports.store', $club), $this->payload())
            ->assertOk();
        $firstSubmission = AdventurerQuarterlyReport::query()->firstOrFail()->submitted_at;

        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        $this->actingAs($director)
            ->postJson(route('clubs.adventurer-quarterly-reports.store', $club), [
                ...$this->payload(),
                'meetings_held' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.meetings_points', 20)
            ->assertJsonPath('data.submitted_on_time', true);

        $this->assertSame(1, AdventurerQuarterlyReport::query()->count());
        $this->assertTrue(AdventurerQuarterlyReport::query()->firstOrFail()->submitted_at->equalTo($firstSubmission));
    }

    public function test_quarterly_report_is_rejected_for_ineligible_clubs(): void
    {
        Storage::fake('public');
        [$pathfinderDirector, $pathfinderClub] = $this->makeDirectorAndClub('pathfinders', 'honors');
        $this->actingAs($pathfinderDirector)
            ->postJson(route('clubs.adventurer-quarterly-reports.store', $pathfinderClub), $this->payload())
            ->assertNotFound();

        [$foldersDirector, $foldersClub] = $this->makeDirectorAndClub('adventurers', 'carpetas');
        $this->actingAs($foldersDirector)
            ->postJson(route('clubs.adventurer-quarterly-reports.store', $foldersClub), $this->payload())
            ->assertNotFound();
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
            'reporting_year' => 2026,
            'reporting_period' => 'sep_oct',
            'club_name' => 'Little Lights',
            'director_name' => 'Jamie Director',
            'cell_number' => '410-555-0101',
            'email_address' => 'director@example.com',
            'membership_boys' => 10,
            'membership_girls' => 11,
            'staff_males' => 3,
            'staff_females' => 5,
            'news_item' => 'Our club completed a nature walk and service project.',
            'meetings_held' => 3,
            'class_a_uniform_worn' => true,
            'attendance_percentage' => 75,
            'awards_taught' => 3,
            'curriculum_taught' => true,
            'outreach_activity' => 'Community food drive',
            'staff_meetings_held' => 2,
        ];
    }
}
