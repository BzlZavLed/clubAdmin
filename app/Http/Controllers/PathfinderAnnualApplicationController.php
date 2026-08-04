<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\PathfinderAnnualApplication;
use App\Models\PathfinderAnnualApplicationSignature;
use App\Services\Mail\MailerService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PathfinderAnnualApplicationController extends Controller
{
    public function store(Request $request, Club $club)
    {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);

        $validated = $this->validatedPayload($request);
        $application = PathfinderAnnualApplication::query()->updateOrCreate(
            [
                'club_id' => $club->id,
                'application_year' => $validated['application_year'],
            ],
            [
                ...$validated,
                'created_by_user_id' => PathfinderAnnualApplication::query()
                    ->where('club_id', $club->id)
                    ->where('application_year', $validated['application_year'])
                    ->value('created_by_user_id') ?: $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                'other_board_members' => array_values(array_filter($validated['other_board_members'] ?? [])),
            ]
        );

        $this->ensureSignatureRows($application->refresh());
        $this->generatePdf($application->load(['club', 'signatures']), force: true);

        return response()->json([
            'message' => 'Aplicacion anual guardada.',
            'data' => $this->payload($application->refresh()),
        ]);
    }

    public function download(Request $request, Club $club, PathfinderAnnualApplication $application)
    {
        $this->authorizeClub($request, $club);
        $this->ensureOwnsApplication($club, $application);

        $this->generatePdf($application->load(['club', 'signatures']), force: true);
        $application->refresh();

        return Storage::disk('public')->download(
            $application->pdf_path,
            $application->pdf_file_name ?: $this->pdfFilename($application)
        );
    }

    public function saveDirectorSignature(Request $request, Club $club, PathfinderAnnualApplication $application)
    {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'signature_type' => ['required', 'in:typed,drawn'],
            'signer_name' => ['required', 'string', 'max:255'],
            'signature_text' => ['nullable', 'required_if:signature_type,typed', 'string', 'max:255'],
            'signature_data' => ['nullable', 'required_if:signature_type,drawn', 'string', 'max:1500000'],
        ]);

        $signature = $this->signatureForRole($application, PathfinderAnnualApplicationSignature::ROLE_DIRECTOR);
        $signaturePath = $signature->signature_path;

        if ($validated['signature_type'] === 'drawn') {
            $signaturePath = $this->storeSignatureImage($validated['signature_data'], $application, PathfinderAnnualApplicationSignature::ROLE_DIRECTOR);
        }

        if (
            $signature->signature_path
            && (
                $validated['signature_type'] === 'typed'
                || $signature->signature_path !== $signaturePath
            )
        ) {
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

        $this->generatePdf($application->refresh()->load(['club', 'signatures']), force: true);

        return response()->json([
            'message' => 'Firma del director guardada.',
            'data' => $this->payload($application->refresh()),
        ]);
    }

    public function requestSignature(
        Request $request,
        Club $club,
        PathfinderAnnualApplication $application,
        MailerService $mailerService,
    ) {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'role' => ['required', 'in:pastor,head_elder'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
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

        $mailLog = $mailerService->sendPathfinderAnnualApplicationSignatureRequest(
            signature: $signature->refresh()->load('application.club'),
            userId: $request->user()->id,
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

    public function send(Request $request, Club $club, PathfinderAnnualApplication $application, MailerService $mailerService)
    {
        $this->authorizeClub($request, $club);
        $this->ensurePathfinderHonorsClub($club);
        $this->ensureOwnsApplication($club, $application);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
        ]);

        abort_unless($this->hasRequiredSignatures($application), 422, 'La aplicacion requiere las tres firmas antes de enviarse.');

        $this->generatePdf($application->load(['club', 'signatures']), force: true);
        $application->refresh();

        $mailLog = $mailerService->sendPathfinderAnnualApplication($application->load('club'), $validated['email'], $request->user()->id);

        return response()->json([
            'message' => 'Aplicacion anual enviada por correo.',
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
            'application_year' => ['required', 'string', 'max:20'],
            'due_date' => ['nullable', 'date'],
            'sponsoring_church' => ['required', 'string', 'max:255'],
            'pastor' => ['nullable', 'string', 'max:255'],
            'elected_club_director' => ['nullable', 'string', 'max:255'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'director_phone_number' => ['nullable', 'string', 'max:50'],
            'church_pastor_signature' => ['nullable', 'string', 'max:255'],
            'head_elder_signature' => ['nullable', 'string', 'max:255'],
            'club_director_signature' => ['nullable', 'string', 'max:255'],
            'board_approval_date' => ['nullable', 'date'],
        ]);
    }

    private function generatePdf(PathfinderAnnualApplication $application, bool $force = false): void
    {
        $application->loadMissing(['club', 'signatures']);

        if (! $force && $application->pdf_path && Storage::disk('public')->exists($application->pdf_path)) {
            return;
        }

        $pdf = Pdf::loadView('pdf.pathfinder_annual_application_form', [
            'application' => $application,
            'signaturesByRole' => $application->signatures->keyBy('role'),
            'signatureImages' => $this->signatureImages($application),
        ])->setPaper('letter', 'portrait');

        $path = 'generated/pathfinder-annual-applications/'
            .$application->club_id
            .'/'
            .Str::slug($application->application_year)
            .'-'
            .now()->format('YmdHis')
            .'.pdf';

        Storage::disk('public')->put($path, $pdf->output());

        $application->forceFill([
            'pdf_path' => $path,
            'pdf_file_name' => $this->pdfFilename($application),
        ])->save();
    }

    private function pdfFilename(PathfinderAnnualApplication $application): string
    {
        return 'pathfinder-annual-application-'
            .Str::slug($application->club?->club_name ?: 'club')
            .'-'
            .Str::slug($application->application_year)
            .'.pdf';
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

    private function ensurePathfinderHonorsClub(Club $club): void
    {
        abort_unless(
            $club->club_type === 'pathfinders'
            && ($club->evaluation_system ?: 'honors') === 'honors',
            404
        );
    }

    private function ensureOwnsApplication(Club $club, PathfinderAnnualApplication $application): void
    {
        abort_unless((int) $application->club_id === (int) $club->id, 404);
    }

    private function signatureForRole(PathfinderAnnualApplication $application, string $role): PathfinderAnnualApplicationSignature
    {
        return PathfinderAnnualApplicationSignature::query()->firstOrCreate([
            'pathfinder_annual_application_id' => $application->id,
            'role' => $role,
        ], [
            'status' => 'pending',
        ]);
    }

    private function ensureSignatureRows(PathfinderAnnualApplication $application): void
    {
        foreach ([
            PathfinderAnnualApplicationSignature::ROLE_DIRECTOR,
            PathfinderAnnualApplicationSignature::ROLE_PASTOR,
            PathfinderAnnualApplicationSignature::ROLE_HEAD_ELDER,
        ] as $role) {
            $this->signatureForRole($application, $role);
        }
    }

    private function storeSignatureImage(string $signatureData, PathfinderAnnualApplication $application, string $role): string
    {
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

        $path = 'pathfinder-annual-application-signatures/'
            .$application->id
            .'/'
            .$role
            .'-'
            .now()->format('YmdHis')
            .'.png';

        Storage::disk('public')->put($path, $image);

        return $path;
    }

    private function signatureImages(PathfinderAnnualApplication $application): array
    {
        return $application->signatures
            ->filter(fn (PathfinderAnnualApplicationSignature $signature) => $signature->signature_path && Storage::disk('public')->exists($signature->signature_path))
            ->mapWithKeys(function (PathfinderAnnualApplicationSignature $signature) {
                $mime = Storage::disk('public')->mimeType($signature->signature_path) ?: 'image/png';

                return [
                    $signature->role => 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($signature->signature_path)),
                ];
            })
            ->all();
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            PathfinderAnnualApplicationSignature::ROLE_DIRECTOR => 'Club Director',
            PathfinderAnnualApplicationSignature::ROLE_PASTOR => 'Church Pastor',
            PathfinderAnnualApplicationSignature::ROLE_HEAD_ELDER => 'Head Elder',
            default => $role,
        };
    }

    private function hasRequiredSignatures(PathfinderAnnualApplication $application): bool
    {
        $signedRoles = $application->loadMissing('signatures')->signatures
            ->filter(fn (PathfinderAnnualApplicationSignature $signature) => $signature->signed_at)
            ->pluck('role')
            ->all();

        return empty(array_diff([
            PathfinderAnnualApplicationSignature::ROLE_DIRECTOR,
            PathfinderAnnualApplicationSignature::ROLE_PASTOR,
            PathfinderAnnualApplicationSignature::ROLE_HEAD_ELDER,
        ], $signedRoles));
    }

    private function payload(PathfinderAnnualApplication $application): array
    {
        return [
            'id' => $application->id,
            'club_id' => $application->club_id,
            'application_year' => $application->application_year,
            'due_date' => optional($application->due_date)->toDateString(),
            'sponsoring_church' => $application->sponsoring_church,
            'pastor' => $application->pastor,
            'elected_club_director' => $application->elected_club_director,
            'mailing_address' => $application->mailing_address,
            'director_phone_number' => $application->director_phone_number,
            'church_pastor_signature' => $application->church_pastor_signature,
            'head_elder_signature' => $application->head_elder_signature,
            'club_director_signature' => $application->club_director_signature,
            'board_approval_date' => optional($application->board_approval_date)->toDateString(),
            'pdf_url' => $application->pdf_url,
            'last_sent_to_email' => $application->last_sent_to_email,
            'delivery_status' => $application->delivery_status,
            'sent_at' => optional($application->sent_at)->toIso8601String(),
            'updated_at' => optional($application->updated_at)->toIso8601String(),
            'signatures_complete' => $this->hasRequiredSignatures($application),
            'signatures' => $application->loadMissing('signatures')->signatures
                ->map(fn (PathfinderAnnualApplicationSignature $signature) => [
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
