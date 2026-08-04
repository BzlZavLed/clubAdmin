<?php

namespace App\Http\Controllers;

use App\Models\AdventurerYearlyApplication;
use App\Models\AdventurerYearlyApplicationSignature;
use App\Models\Club;
use App\Services\AdventurerYearlyApplicationDocumentService;
use App\Services\Mail\MailerService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdventurerYearlyApplicationController extends Controller
{
    public function store(
        Request $request,
        Club $club,
        AdventurerYearlyApplicationDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);

        $validated = $this->validatedPayload($request);
        $existing = AdventurerYearlyApplication::query()
            ->where('club_id', $club->id)
            ->where('application_year', $validated['application_year'])
            ->first();

        $application = AdventurerYearlyApplication::query()->updateOrCreate(
            [
                'club_id' => $club->id,
                'application_year' => $validated['application_year'],
            ],
            [
                ...$validated,
                'created_by_user_id' => $existing?->created_by_user_id ?: $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                'other_board_members' => array_pad(
                    array_slice(array_values(array_filter(
                        $validated['other_board_members'] ?? [],
                        fn ($value) => trim((string) $value) !== ''
                    )), 0, 5),
                    5,
                    ''
                ),
            ]
        );

        $this->ensureSignatureRows($application->refresh());
        $documentService->generate($application->load(['club', 'signatures']));

        return response()->json([
            'message' => 'Solicitud anual de Aventureros guardada.',
            'data' => $this->payload($application->refresh()),
        ]);
    }

    public function download(
        Request $request,
        Club $club,
        AdventurerYearlyApplication $application,
        AdventurerYearlyApplicationDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $application = $documentService->generate($application);

        return Storage::disk('public')->download(
            $application->docx_path,
            $application->docx_file_name
        );
    }

    public function send(
        Request $request,
        Club $club,
        AdventurerYearlyApplication $application,
        AdventurerYearlyApplicationDocumentService $documentService,
        MailerService $mailerService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        abort_unless(
            $this->hasRequiredSignatures($application),
            422,
            'La solicitud requiere las cuatro firmas antes de enviarse.'
        );

        $application = $documentService->generate($application);
        $mailLog = $mailerService->sendAdventurerYearlyApplication(
            $application,
            $validated['email'],
            $request->user()->id,
        );

        return response()->json([
            'message' => 'Solicitud anual de Aventureros enviada.',
            'data' => $this->payload($application->refresh()),
            'mail' => [
                'id' => $mailLog->email_uid,
                'status' => $mailLog->status,
                'recipient_email' => $mailLog->recipient_email,
            ],
        ]);
    }

    public function saveDirectorSignature(
        Request $request,
        Club $club,
        AdventurerYearlyApplication $application,
        AdventurerYearlyApplicationDocumentService $documentService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'signature_type' => ['required', 'in:typed,drawn'],
            'signer_name' => ['required', 'string', 'max:255'],
            'signature_text' => ['nullable', 'required_if:signature_type,typed', 'string', 'max:255'],
            'signature_data' => ['nullable', 'required_if:signature_type,drawn', 'string', 'max:1500000'],
        ]);

        $signature = $this->signatureForRole($application, AdventurerYearlyApplicationSignature::ROLE_DIRECTOR);
        $signaturePath = $signature->signature_path;
        if ($validated['signature_type'] === 'drawn') {
            $signaturePath = $this->storeSignatureImage(
                $validated['signature_data'],
                $application,
                AdventurerYearlyApplicationSignature::ROLE_DIRECTOR
            );
        }

        if ($signature->signature_path && ($validated['signature_type'] === 'typed' || $signature->signature_path !== $signaturePath)) {
            Storage::disk('public')->delete($signature->signature_path);
        }

        $signature->forceFill([
            'signer_name' => trim($validated['signer_name']),
            'signer_email' => $request->user()?->email,
            'signature_type' => $validated['signature_type'],
            'signature_text' => $validated['signature_type'] === 'typed' ? trim($validated['signature_text']) : null,
            'signature_path' => $validated['signature_type'] === 'drawn' ? $signaturePath : null,
            'signed_at' => now(),
            'status' => 'signed',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
        ])->save();

        $application->forceFill(['signature_date' => now()->toDateString()])->save();
        $documentService->generate($application->refresh()->load(['club', 'signatures']));

        return response()->json([
            'message' => 'Firma del director guardada.',
            'data' => $this->payload($application->refresh()),
        ]);
    }

    public function requestSignature(
        Request $request,
        Club $club,
        AdventurerYearlyApplication $application,
        MailerService $mailerService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensureAdventurerHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'role' => ['required', 'in:pastor,head_elder,church_clerk'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $signature = $this->signatureForRole($application, $validated['role']);
        $signature->forceFill([
            'signer_name' => $validated['name'] ?: $signature->signer_name ?: $this->roleLabel($validated['role']),
            'signer_email' => $validated['email'],
            'request_token' => Str::random(64),
            'requested_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => $signature->signed_at ? 'signed' : 'requested',
        ])->save();

        $mailLog = $mailerService->sendAdventurerYearlyApplicationSignatureRequest(
            $signature->refresh()->load('application.club'),
            $request->user()->id,
        );

        return response()->json([
            'message' => 'Solicitud de firma enviada.',
            'data' => $this->payload($application->refresh()),
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
            'application_year' => ['required', 'digits:4'],
            'application_date' => ['required', 'date'],
            'club_name' => ['required', 'string', 'max:255'],
            'sponsoring_church' => ['required', 'string', 'max:255'],
            'pastor' => ['nullable', 'string', 'max:255'],
            'elected_club_director' => ['nullable', 'string', 'max:255'],
            'email_address' => ['nullable', 'email:rfc', 'max:255'],
            'cell_number' => ['nullable', 'string', 'max:50'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'church_pastor_signature' => ['nullable', 'string', 'max:255'],
            'head_elder_signature' => ['nullable', 'string', 'max:255'],
            'church_clerk_signature' => ['nullable', 'string', 'max:255'],
            'club_director_signature' => ['nullable', 'string', 'max:255'],
            'signature_date' => ['nullable', 'date'],
            'other_board_members' => ['nullable', 'array', 'max:5'],
            'other_board_members.*' => ['nullable', 'string', 'max:255'],
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

    private function ensureOwnsApplication(Club $club, AdventurerYearlyApplication $application): void
    {
        abort_unless((int) $application->club_id === (int) $club->id, 404);
    }

    private function signatureForRole(
        AdventurerYearlyApplication $application,
        string $role,
    ): AdventurerYearlyApplicationSignature {
        return AdventurerYearlyApplicationSignature::query()->firstOrCreate([
            'adventurer_yearly_application_id' => $application->id,
            'role' => $role,
        ], ['status' => 'pending']);
    }

    private function ensureSignatureRows(AdventurerYearlyApplication $application): void
    {
        foreach ([
            AdventurerYearlyApplicationSignature::ROLE_DIRECTOR,
            AdventurerYearlyApplicationSignature::ROLE_PASTOR,
            AdventurerYearlyApplicationSignature::ROLE_HEAD_ELDER,
            AdventurerYearlyApplicationSignature::ROLE_CHURCH_CLERK,
        ] as $role) {
            $this->signatureForRole($application, $role);
        }
    }

    private function storeSignatureImage(
        string $signatureData,
        AdventurerYearlyApplication $application,
        string $role,
    ): string {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $signatureData, $matches)) {
            abort(response()->json([
                'message' => 'La firma debe enviarse como imagen PNG.',
                'errors' => ['signature_data' => ['La firma debe enviarse como imagen PNG.']],
            ], 422));
        }

        $image = base64_decode($matches[1], true);
        if (! $image || strlen($image) > 1024 * 1024) {
            abort(response()->json([
                'message' => 'La firma no es valida o es demasiado grande.',
                'errors' => ['signature_data' => ['La firma no es valida o es demasiado grande.']],
            ], 422));
        }

        $path = 'adventurer-yearly-application-signatures/'
            .$application->id.'/'.$role.'-'.now()->format('YmdHis').'.png';
        Storage::disk('public')->put($path, $image);

        return $path;
    }

    private function hasRequiredSignatures(AdventurerYearlyApplication $application): bool
    {
        $signedRoles = $application->loadMissing('signatures')->signatures
            ->filter(fn (AdventurerYearlyApplicationSignature $signature) => $signature->signed_at)
            ->pluck('role')
            ->all();

        return empty(array_diff([
            AdventurerYearlyApplicationSignature::ROLE_DIRECTOR,
            AdventurerYearlyApplicationSignature::ROLE_PASTOR,
            AdventurerYearlyApplicationSignature::ROLE_HEAD_ELDER,
            AdventurerYearlyApplicationSignature::ROLE_CHURCH_CLERK,
        ], $signedRoles));
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            AdventurerYearlyApplicationSignature::ROLE_DIRECTOR => 'Club Director',
            AdventurerYearlyApplicationSignature::ROLE_PASTOR => 'Church Pastor',
            AdventurerYearlyApplicationSignature::ROLE_HEAD_ELDER => 'Head Elder',
            AdventurerYearlyApplicationSignature::ROLE_CHURCH_CLERK => 'Church Clerk',
            default => $role,
        };
    }

    private function payload(AdventurerYearlyApplication $application): array
    {
        return [
            'id' => $application->id,
            'club_id' => $application->club_id,
            'application_year' => $application->application_year,
            'application_date' => optional($application->application_date)->toDateString(),
            'club_name' => $application->club_name,
            'sponsoring_church' => $application->sponsoring_church,
            'pastor' => $application->pastor,
            'elected_club_director' => $application->elected_club_director,
            'email_address' => $application->email_address,
            'cell_number' => $application->cell_number,
            'home_address' => $application->home_address,
            'church_pastor_signature' => $application->church_pastor_signature,
            'head_elder_signature' => $application->head_elder_signature,
            'church_clerk_signature' => $application->church_clerk_signature,
            'club_director_signature' => $application->club_director_signature,
            'signature_date' => optional($application->signature_date)->toDateString(),
            'other_board_members' => array_pad($application->other_board_members ?: [], 5, ''),
            'docx_file_name' => $application->docx_file_name,
            'last_sent_to_email' => $application->last_sent_to_email,
            'delivery_status' => $application->delivery_status,
            'sent_at' => optional($application->sent_at)->toIso8601String(),
            'updated_at' => optional($application->updated_at)->toIso8601String(),
            'signatures_complete' => $this->hasRequiredSignatures($application),
            'signatures' => $application->loadMissing('signatures')->signatures
                ->map(fn (AdventurerYearlyApplicationSignature $signature) => [
                    'id' => $signature->id,
                    'role' => $signature->role,
                    'signer_name' => $signature->signer_name,
                    'signer_email' => $signature->signer_email,
                    'signature_type' => $signature->signature_type,
                    'signature_text' => $signature->signature_text,
                    'signature_url' => $signature->signature_url,
                    'status' => $signature->status,
                    'requested_at' => optional($signature->requested_at)->toIso8601String(),
                    'signed_at' => optional($signature->signed_at)->toIso8601String(),
                    'expires_at' => optional($signature->expires_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
