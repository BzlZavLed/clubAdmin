<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\PathfinderMonthlyReport;
use App\Models\PathfinderMonthlyReportAttachment;
use App\Services\Mail\MailerService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class PathfinderMonthlyReportController extends Controller
{
    public function store(Request $request, Club $club)
    {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);

        $validated = $this->validatedPayload($request);

        $report = PathfinderMonthlyReport::query()->updateOrCreate(
            [
                'club_id' => $club->id,
                'report_year' => $validated['report_year'],
                'report_month' => $validated['report_month'],
            ],
            [
                ...collect($validated)->except(['volunteer_proofs', 'activity_photos'])->all(),
                'created_by_user_id' => PathfinderMonthlyReport::query()
                    ->where('club_id', $club->id)
                    ->where('report_year', $validated['report_year'])
                    ->where('report_month', $validated['report_month'])
                    ->value('created_by_user_id') ?: $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]
        );

        $this->storeAttachments($request, $report, 'volunteer_proofs', PathfinderMonthlyReportAttachment::KIND_VOLUNTEER_PROOF);
        $this->storeAttachments($request, $report, 'activity_photos', PathfinderMonthlyReportAttachment::KIND_ACTIVITY_PHOTO);
        $this->generatePdf($report->refresh()->load(['club', 'attachments']), force: true);

        return response()->json([
            'message' => 'Reporte mensual guardado.',
            'data' => $this->payload($report->refresh()->load('attachments')),
        ]);
    }

    public function download(Request $request, Club $club, PathfinderMonthlyReport $report)
    {
        $this->authorizeClub($request, $club);
        $this->ensureOwnsReport($club, $report);

        $this->generatePdf($report->load(['club', 'attachments']), force: true);
        $report->refresh();

        return Storage::disk('public')->download(
            $report->pdf_path,
            $report->pdf_file_name ?: $this->pdfFilename($report)
        );
    }

    public function send(Request $request, Club $club, PathfinderMonthlyReport $report, MailerService $mailerService)
    {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);
        $this->ensureOwnsReport($club, $report);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
        ]);

        $this->generatePdf($report->load(['club', 'attachments']), force: true);
        $report->refresh();

        $mailLog = $mailerService->sendPathfinderMonthlyReport($report->load(['club', 'attachments']), $validated['email'], $request->user()->id);

        return response()->json([
            'message' => 'Reporte mensual enviado por correo.',
            'data' => $this->payload($report->refresh()->load('attachments')),
            'mail' => [
                'id' => $mailLog->email_uid,
                'status' => $mailLog->status,
                'recipient_email' => $mailLog->recipient_email,
            ],
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'report_year' => ['required', 'string', 'max:9'],
            'report_month' => ['required', 'string', 'max:20'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'area' => ['nullable', 'string', 'max:80'],
            'church_and_club_name' => ['required', 'string', 'max:255'],
            'pathfinders_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'tlt_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'staff_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'meetings_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'bible_studies_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'baptisms_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'campouts_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'field_trips_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'honors_completed_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'honors_completed_list' => ['nullable', 'string', 'max:10000'],
            'outreach_activities' => ['nullable', 'string', 'max:10000'],
            'notable_activities' => ['nullable', 'string', 'max:10000'],
            'may_share_photos' => ['nullable', 'boolean'],
            'volunteer_proofs' => ['nullable', 'array'],
            'volunteer_proofs.*' => ['file', 'max:10854', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,rtf,html,zip,mp3,wma,mpg,flv,avi,jpg,jpeg,png,gif'],
            'activity_photos' => ['nullable', 'array'],
            'activity_photos.*' => ['file', 'max:10854', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,rtf,html,zip,mp3,wma,mpg,flv,avi,jpg,jpeg,png,gif'],
        ]);
    }

    private function storeAttachments(Request $request, PathfinderMonthlyReport $report, string $input, string $kind): void
    {
        if (!$request->hasFile($input)) {
            return;
        }

        foreach ($request->file($input, []) as $file) {
            $path = $file->store(
                'pathfinder-monthly-reports/' . $report->id . '/' . $kind,
                'public'
            );

            $report->attachments()->create([
                'kind' => $kind,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    private function generatePdf(PathfinderMonthlyReport $report, bool $force = false): void
    {
        $report->loadMissing(['club', 'attachments']);

        if (!$force && $report->pdf_path && Storage::disk('public')->exists($report->pdf_path)) {
            return;
        }

        $path = 'generated/pathfinder-monthly-reports/'
            . $report->club_id
            . '/'
            . Str::slug($report->report_year . '-' . $report->report_month)
            . '-'
            . now()->format('YmdHis')
            . '.pdf';

        $pdf = Pdf::loadView('pdf.pathfinder_monthly_report', [
            'report' => $report,
        ])->setPaper('letter', 'portrait');

        $pdfOutput = $pdf->output();
        $pdfOutput = $this->appendEvidenceToPdf($pdfOutput, $report);

        Storage::disk('public')->put($path, $pdfOutput);

        $report->forceFill([
            'pdf_path' => $path,
            'pdf_file_name' => $this->pdfFilename($report),
        ])->save();
    }

    private function pdfFilename(PathfinderMonthlyReport $report): string
    {
        return 'pathfinder-monthly-report-'
            . Str::slug($report->club?->club_name ?: 'club')
            . '-'
            . Str::slug($report->report_year)
            . '-'
            . Str::slug($report->report_month)
            . '.pdf';
    }

    private function evidencePages(PathfinderMonthlyReport $report): array
    {
        return $report->attachments
            ->map(fn (PathfinderMonthlyReportAttachment $attachment) => $this->evidencePageForAttachment($attachment))
            ->values()
            ->all();
    }

    private function evidencePageForAttachment(PathfinderMonthlyReportAttachment $attachment): array
    {
        $disk = Storage::disk($attachment->disk ?: 'public');
        $mimeType = (string) ($attachment->mime_type ?: '');
        $path = $attachment->path;
        $isImage = Str::startsWith($mimeType, 'image/');
        $isPdf = $this->isPdfAttachment($attachment);

        $page = [
            'title' => $attachment->kind === PathfinderMonthlyReportAttachment::KIND_VOLUNTEER_PROOF
                ? 'Verified Volunteer proof'
                : 'Activity photo/event evidence',
            'file_name' => $attachment->original_name ?: basename((string) $path),
            'mime_type' => $mimeType ?: 'application/octet-stream',
            'size' => $attachment->size,
            'type' => 'metadata',
            'note' => 'This file is included in the submission and attached to the email.',
            'data_uri' => null,
        ];

        if ($isImage && $path && $disk->exists($path)) {
            $page['type'] = 'image';
            $page['data_uri'] = 'data:' . ($mimeType ?: 'image/jpeg') . ';base64,' . base64_encode($disk->get($path));
            $page['note'] = null;
        } elseif ($isPdf) {
            $page['type'] = 'pdf';
            $page['note'] = 'PDF pages follow this divider page.';
        }

        return $page;
    }

    private function appendEvidenceToPdf(string $basePdfContents, PathfinderMonthlyReport $report): string
    {
        if ($report->attachments->isEmpty()) {
            return $basePdfContents;
        }

        $tempDirectory = storage_path('app/tmp/pathfinder-monthly-reports');
        File::ensureDirectoryExists($tempDirectory);

        $temporaryPaths = [];
        $basePath = $this->temporaryPdfPath($tempDirectory, $basePdfContents, 'monthly-base-');
        $temporaryPaths[] = $basePath;

        try {
            $merger = new Fpdi();
            $this->appendPdfToMerger($merger, $basePath);

            foreach ($report->attachments as $attachment) {
                $pagePdf = Pdf::loadView('pdf.pathfinder_monthly_report_evidence_page', [
                    'page' => $this->evidencePageForAttachment($attachment),
                ])->setPaper('letter', 'portrait')->output();

                $pagePath = $this->temporaryPdfPath($tempDirectory, $pagePdf, 'monthly-evidence-');
                $temporaryPaths[] = $pagePath;
                $this->appendPdfToMerger($merger, $pagePath);

                if ($this->isPdfAttachment($attachment)) {
                    $attachmentPath = $this->attachmentAbsolutePath($attachment);

                    if (!$attachmentPath || !is_file($attachmentPath)) {
                        continue;
                    }

                    try {
                        $this->appendPdfToMerger($merger, $attachmentPath);
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }

            return $merger->Output('S');
        } finally {
            foreach ($temporaryPaths as $temporaryPath) {
                if ($temporaryPath && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }
    }

    private function temporaryPdfPath(string $directory, string $contents, string $prefix): string
    {
        $path = tempnam($directory, $prefix);
        file_put_contents($path, $contents);

        return $path;
    }

    private function appendPdfToMerger(Fpdi $merger, string $path): void
    {
        $pageCount = $merger->setSourceFile($path);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $merger->importPage($pageNumber);
            $size = $merger->getTemplateSize($templateId);

            $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $merger->useTemplate($templateId);
        }
    }

    private function isPdfAttachment(PathfinderMonthlyReportAttachment $attachment): bool
    {
        $name = Str::lower($attachment->original_name ?: $attachment->path ?: '');

        return $attachment->mime_type === 'application/pdf' || Str::endsWith($name, '.pdf');
    }

    private function attachmentAbsolutePath(PathfinderMonthlyReportAttachment $attachment): ?string
    {
        $disk = Storage::disk($attachment->disk ?: 'public');

        if (!$attachment->path || !$disk->exists($attachment->path)) {
            return null;
        }

        return method_exists($disk, 'path') ? $disk->path($attachment->path) : null;
    }

    private function authorizeClub(Request $request, Club $club): void
    {
        if ($request->user()?->profile_type === 'superadmin') {
            return;
        }

        abort_unless(in_array((int) $club->id, ClubHelper::clubIdsForUser($request->user()), true), 403);
    }

    private function ensurePathfinderHonorsClub(Club $club): void
    {
        abort_unless($club->club_type === 'pathfinders' && ($club->evaluation_system ?: 'honors') === 'honors', 404);
    }

    private function ensureOwnsReport(Club $club, PathfinderMonthlyReport $report): void
    {
        abort_unless((int) $report->club_id === (int) $club->id, 404);
    }

    private function payload(PathfinderMonthlyReport $report): array
    {
        $report->loadMissing('attachments');

        return [
            'id' => $report->id,
            'club_id' => $report->club_id,
            'report_year' => $report->report_year,
            'report_month' => $report->report_month,
            'full_name' => $report->full_name,
            'email' => $report->email,
            'area' => $report->area,
            'church_and_club_name' => $report->church_and_club_name,
            'pathfinders_count' => $report->pathfinders_count,
            'tlt_count' => $report->tlt_count,
            'staff_count' => $report->staff_count,
            'meetings_count' => $report->meetings_count,
            'bible_studies_count' => $report->bible_studies_count,
            'baptisms_count' => $report->baptisms_count,
            'campouts_count' => $report->campouts_count,
            'field_trips_count' => $report->field_trips_count,
            'honors_completed_count' => $report->honors_completed_count,
            'honors_completed_list' => $report->honors_completed_list,
            'outreach_activities' => $report->outreach_activities,
            'notable_activities' => $report->notable_activities,
            'may_share_photos' => $report->may_share_photos,
            'pdf_url' => $report->pdf_url,
            'last_sent_to_email' => $report->last_sent_to_email,
            'delivery_status' => $report->delivery_status,
            'sent_at' => optional($report->sent_at)->toIso8601String(),
            'updated_at' => optional($report->updated_at)->toIso8601String(),
            'attachments' => $report->attachments
                ->map(fn (PathfinderMonthlyReportAttachment $attachment) => [
                    'id' => $attachment->id,
                    'kind' => $attachment->kind,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'url' => $attachment->url,
                ])
                ->values()
                ->all(),
        ];
    }
}
