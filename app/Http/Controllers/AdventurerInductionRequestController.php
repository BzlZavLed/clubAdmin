<?php

namespace App\Http\Controllers;

use App\Models\AdventurerInductionRequest;
use App\Models\Club;
use App\Services\AdventurerInductionRequestDocumentService;
use App\Services\Mail\MailerService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdventurerInductionRequestController extends Controller
{
    public function store(
        Request $request,
        Club $club,
        AdventurerInductionRequestDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'requested_attendee' => ['required', 'string', 'max:255'],
            'club_name' => ['required', 'string', 'max:255'],
            'induction_date' => ['required', 'date'],
            'induction_time' => ['required', 'date_format:H:i'],
            'induction_place' => ['required', 'string', 'max:255'],
            'directions' => ['nullable', 'string', 'max:5000'],
        ]);

        $inductionRequest = isset($validated['id'])
            ? AdventurerInductionRequest::query()->findOrFail($validated['id'])
            : AdventurerInductionRequest::query()->firstOrNew([
                'club_id' => $club->id,
                'induction_date' => $validated['induction_date'],
                'induction_time' => $validated['induction_time'],
            ]);
        abort_unless(! $inductionRequest->exists || (int) $inductionRequest->club_id === (int) $club->id, 404);

        $inductionRequest->forceFill([
            'club_id' => $club->id,
            'created_by_user_id' => $inductionRequest->created_by_user_id ?: $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
            'requested_attendee' => trim($validated['requested_attendee']),
            'club_name' => trim($validated['club_name']),
            'induction_date' => $validated['induction_date'],
            'induction_time' => $validated['induction_time'],
            'induction_place' => trim($validated['induction_place']),
            'directions' => trim((string) ($validated['directions'] ?? '')) ?: null,
            'received_at' => $inductionRequest->received_at ?: now(),
            'status' => $inductionRequest->status ?: 'submitted',
        ])->save();

        $documentService->generate($inductionRequest->load('club'));

        return response()->json([
            'message' => 'Solicitud de inducción de Aventureros guardada.',
            'data' => $this->payload($inductionRequest->refresh()),
        ]);
    }

    public function download(
        Request $request,
        Club $club,
        AdventurerInductionRequest $inductionRequest,
        AdventurerInductionRequestDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        abort_unless((int) $inductionRequest->club_id === (int) $club->id, 404);

        $inductionRequest = $documentService->generate($inductionRequest);

        return Storage::disk('public')->download(
            $inductionRequest->docx_path,
            $inductionRequest->docx_file_name,
        );
    }

    public function send(
        Request $request,
        Club $club,
        AdventurerInductionRequest $inductionRequest,
        AdventurerInductionRequestDocumentService $documentService,
        MailerService $mailerService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        abort_unless((int) $inductionRequest->club_id === (int) $club->id, 404);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        try {
            $inductionRequest->forceFill([
                'emailed_at' => now(),
                'last_sent_to_email' => $validated['email'],
            ])->save();
            $inductionRequest = $documentService->generate($inductionRequest->load('club'));
            $mailLog = $mailerService->sendAdventurerInductionRequest(
                $inductionRequest,
                $validated['email'],
                $request->user()->id,
            );
        } catch (\Throwable $exception) {
            $inductionRequest->forceFill([
                'emailed_at' => null,
                'status' => 'failed',
            ])->save();
            throw $exception;
        }

        return response()->json([
            'message' => 'Solicitud de inducción de Aventureros enviada.',
            'data' => $this->payload($inductionRequest->refresh()),
            'mail' => [
                'id' => $mailLog->email_uid,
                'status' => $mailLog->status,
                'recipient_email' => $mailLog->recipient_email,
            ],
        ]);
    }

    private function authorizeClub(Request $request, Club $club): void
    {
        if ($request->user()?->profile_type === 'superadmin') {
            return;
        }

        abort_unless(
            collect(ClubHelper::clubIdsForUser($request->user()))
                ->contains(fn ($clubId) => (int) $clubId === (int) $club->id),
            403,
        );
    }

    private function ensureAdventurerHonorsClub(Club $club): void
    {
        abort_unless(
            $club->club_type === 'adventurers'
            && ($club->evaluation_system ?: 'honors') === 'honors',
            404,
        );
    }

    private function payload(AdventurerInductionRequest $inductionRequest): array
    {
        return [
            'id' => $inductionRequest->id,
            'club_id' => $inductionRequest->club_id,
            'requested_attendee' => $inductionRequest->requested_attendee,
            'club_name' => $inductionRequest->club_name,
            'induction_date' => optional($inductionRequest->induction_date)->toDateString(),
            'induction_time' => substr((string) $inductionRequest->induction_time, 0, 5),
            'induction_place' => $inductionRequest->induction_place,
            'directions' => $inductionRequest->directions,
            'received_at' => optional($inductionRequest->received_at)->toIso8601String(),
            'emailed_at' => optional($inductionRequest->emailed_at)->toIso8601String(),
            'last_sent_to_email' => $inductionRequest->last_sent_to_email,
            'status' => $inductionRequest->status,
            'docx_file_name' => $inductionRequest->docx_file_name,
            'updated_at' => optional($inductionRequest->updated_at)->toIso8601String(),
        ];
    }
}
