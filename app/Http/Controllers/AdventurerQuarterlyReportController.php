<?php

namespace App\Http\Controllers;

use App\Models\AdventurerQuarterlyReport;
use App\Models\Club;
use App\Services\AdventurerQuarterlyReportDocumentService;
use App\Support\ClubHelper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdventurerQuarterlyReportController extends Controller
{
    public function store(
        Request $request,
        Club $club,
        AdventurerQuarterlyReportDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);

        $validated = $request->validate([
            'reporting_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'reporting_period' => ['required', Rule::in(AdventurerQuarterlyReport::PERIODS)],
            'club_name' => ['required', 'string', 'max:255'],
            'director_name' => ['required', 'string', 'max:255'],
            'cell_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email:rfc', 'max:255'],
            'membership_boys' => ['required', 'integer', 'min:0', 'max:9999'],
            'membership_girls' => ['required', 'integer', 'min:0', 'max:9999'],
            'staff_males' => ['required', 'integer', 'min:0', 'max:9999'],
            'staff_females' => ['required', 'integer', 'min:0', 'max:9999'],
            'news_item' => ['nullable', 'string', 'max:5000'],
            'meetings_held' => ['required', 'integer', 'min:0', 'max:99'],
            'class_a_uniform_worn' => ['required', 'boolean'],
            'attendance_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'awards_taught' => ['required', 'integer', 'min:0', 'max:99'],
            'curriculum_taught' => ['required', 'boolean'],
            'outreach_activity' => ['nullable', 'string', 'max:1000'],
            'staff_meetings_held' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $existing = AdventurerQuarterlyReport::query()
            ->where('club_id', $club->id)
            ->where('reporting_year', $validated['reporting_year'])
            ->where('reporting_period', $validated['reporting_period'])
            ->first();
        $submittedAt = $existing?->submitted_at ?: now();
        $dueDate = $this->dueDate((int) $validated['reporting_year'], $validated['reporting_period']);
        $submittedOnTime = $submittedAt->lte($dueDate->endOfDay());
        $newsItem = trim((string) ($validated['news_item'] ?? ''));
        $outreachActivity = trim((string) ($validated['outreach_activity'] ?? ''));

        $points = [
            'meetings_points' => min(((int) $validated['meetings_held']) * 10, 30),
            'uniform_points' => $validated['class_a_uniform_worn'] ? 45 : 0,
            'attendance_points' => ((float) $validated['attendance_percentage']) >= 51 ? 60 : 30,
            'awards_points' => min(((int) $validated['awards_taught']) * 10, 30),
            'curriculum_points' => $validated['curriculum_taught'] ? 45 : 0,
            'outreach_points' => $outreachActivity !== '' ? 30 : 0,
            'staff_meetings_points' => min(((int) $validated['staff_meetings_held']) * 15, 30),
            'promptness_points' => $submittedOnTime ? 15 : 0,
            'news_item_points' => $newsItem !== '' ? 15 : 0,
        ];

        $report = AdventurerQuarterlyReport::query()->updateOrCreate(
            [
                'club_id' => $club->id,
                'reporting_year' => $validated['reporting_year'],
                'reporting_period' => $validated['reporting_period'],
            ],
            [
                ...$validated,
                'created_by_user_id' => $existing?->created_by_user_id ?: $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                'due_date' => $dueDate->toDateString(),
                'submitted_at' => $submittedAt,
                'submitted_on_time' => $submittedOnTime,
                'membership_total' => (int) $validated['membership_boys'] + (int) $validated['membership_girls'],
                'staff_total' => (int) $validated['staff_males'] + (int) $validated['staff_females'],
                'news_item' => $newsItem ?: null,
                'outreach_activity' => $outreachActivity ?: null,
                ...$points,
                'total_points' => array_sum($points),
            ]
        );

        $documentService->generate($report->load('club'));

        return response()->json([
            'message' => 'Reporte trimestral de Aventureros guardado.',
            'data' => $this->payload($report->refresh()),
        ]);
    }

    public function download(
        Request $request,
        Club $club,
        AdventurerQuarterlyReport $report,
        AdventurerQuarterlyReportDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        abort_unless((int) $report->club_id === (int) $club->id, 404);

        $report = $documentService->generate($report);

        return Storage::disk('public')->download($report->docx_path, $report->docx_file_name);
    }

    private function dueDate(int $reportingYear, string $period): CarbonImmutable
    {
        return match ($period) {
            AdventurerQuarterlyReport::PERIOD_SEP_OCT => CarbonImmutable::create($reportingYear, 11, 1),
            AdventurerQuarterlyReport::PERIOD_NOV_DEC => CarbonImmutable::create($reportingYear + 1, 1, 1),
            AdventurerQuarterlyReport::PERIOD_JAN_FEB => CarbonImmutable::create($reportingYear + 1, 3, 1),
            AdventurerQuarterlyReport::PERIOD_MAR_APR => CarbonImmutable::create($reportingYear + 1, 5, 1),
        };
    }

    private function authorizeClub(Request $request, Club $club): void
    {
        if ($request->user()?->profile_type === 'superadmin') {
            return;
        }

        abort_unless(
            collect(ClubHelper::clubIdsForUser($request->user()))
                ->contains(fn ($clubId) => (int) $clubId === (int) $club->id),
            403
        );
    }

    private function ensureAdventurerHonorsClub(Club $club): void
    {
        abort_unless(
            $club->club_type === 'adventurers'
            && ($club->evaluation_system ?: 'honors') === 'honors',
            404
        );
    }

    private function payload(AdventurerQuarterlyReport $report): array
    {
        return [
            'id' => $report->id,
            'club_id' => $report->club_id,
            'reporting_year' => $report->reporting_year,
            'reporting_period' => $report->reporting_period,
            'due_date' => optional($report->due_date)->toDateString(),
            'submitted_at' => optional($report->submitted_at)->toIso8601String(),
            'submitted_on_time' => $report->submitted_on_time,
            'club_name' => $report->club_name,
            'director_name' => $report->director_name,
            'cell_number' => $report->cell_number,
            'email_address' => $report->email_address,
            'membership_boys' => $report->membership_boys,
            'membership_girls' => $report->membership_girls,
            'membership_total' => $report->membership_total,
            'staff_males' => $report->staff_males,
            'staff_females' => $report->staff_females,
            'staff_total' => $report->staff_total,
            'news_item' => $report->news_item,
            'meetings_held' => $report->meetings_held,
            'class_a_uniform_worn' => $report->class_a_uniform_worn,
            'attendance_percentage' => (float) $report->attendance_percentage,
            'awards_taught' => $report->awards_taught,
            'curriculum_taught' => $report->curriculum_taught,
            'outreach_activity' => $report->outreach_activity,
            'staff_meetings_held' => $report->staff_meetings_held,
            'meetings_points' => $report->meetings_points,
            'uniform_points' => $report->uniform_points,
            'attendance_points' => $report->attendance_points,
            'awards_points' => $report->awards_points,
            'curriculum_points' => $report->curriculum_points,
            'outreach_points' => $report->outreach_points,
            'staff_meetings_points' => $report->staff_meetings_points,
            'promptness_points' => $report->promptness_points,
            'news_item_points' => $report->news_item_points,
            'total_points' => $report->total_points,
            'docx_file_name' => $report->docx_file_name,
            'updated_at' => optional($report->updated_at)->toIso8601String(),
        ];
    }
}
