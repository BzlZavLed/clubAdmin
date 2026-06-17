<?php

namespace App\Http\Controllers;

use App\Models\PathfinderAnnualApplicationSignature;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicPathfinderAnnualApplicationSignatureController extends Controller
{
    public function show(string $token): Response
    {
        $signature = $this->signatureFromToken($token);

        return Inertia::render('Public/PathfinderAnnualApplicationSignature', [
            'signature_request' => $this->payload($signature),
            'submit_url' => route('pathfinder-annual-applications.signatures.submit', ['token' => $token]),
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $signature = $this->signatureFromToken($token);

        if ($signature->signed_at) {
            return response()->json([
                'message' => 'Esta solicitud ya fue firmada.',
                'data' => $this->payload($signature),
            ], 422);
        }

        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:1500000'],
            'acknowledged' => ['accepted'],
        ]);

        $path = $this->storeSignatureImage($validated['signature_data'], $signature);

        if ($signature->signature_path && $signature->signature_path !== $path) {
            Storage::disk('public')->delete($signature->signature_path);
        }

        $signature->forceFill([
            'signer_name' => trim($validated['signer_name']),
            'signature_type' => 'drawn',
            'signature_text' => null,
            'signature_path' => $path,
            'signed_at' => now(),
            'status' => 'signed',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
        ])->save();

        $this->generateApplicationPdf($signature->application->refresh()->load(['club', 'signatures']), force: true);

        return response()->json([
            'message' => 'Firma guardada.',
            'data' => $this->payload($signature->refresh()),
        ]);
    }

    private function signatureFromToken(string $token): PathfinderAnnualApplicationSignature
    {
        $signature = PathfinderAnnualApplicationSignature::query()
            ->with('application.club')
            ->where('request_token', $token)
            ->firstOrFail();

        abort_if($signature->expires_at && $signature->expires_at->isPast() && !$signature->signed_at, 410, 'El enlace de firma expiro.');

        return $signature;
    }

    private function storeSignatureImage(string $signatureData, PathfinderAnnualApplicationSignature $signature): string
    {
        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $signatureData, $matches)) {
            abort(response()->json([
                'message' => 'La firma debe enviarse como imagen PNG.',
                'errors' => ['signature_data' => ['La firma debe enviarse como imagen PNG.']],
            ], 422));
        }

        $image = base64_decode($matches[1], true);
        if (!$image || strlen($image) > 1024 * 1024) {
            abort(response()->json([
                'message' => 'La firma no es valida o es demasiado grande.',
                'errors' => ['signature_data' => ['La firma no es valida o es demasiado grande.']],
            ], 422));
        }

        $path = 'pathfinder-annual-application-signatures/'
            . $signature->pathfinder_annual_application_id
            . '/'
            . $signature->role
            . '-'
            . now()->format('YmdHis')
            . '.png';

        Storage::disk('public')->put($path, $image);

        return $path;
    }

    private function generateApplicationPdf($application, bool $force = false): void
    {
        if (!$force && $application->pdf_path && Storage::disk('public')->exists($application->pdf_path)) {
            return;
        }

        $path = 'generated/pathfinder-annual-applications/'
            . $application->club_id
            . '/'
            . Str::slug($application->application_year)
            . '-'
            . now()->format('YmdHis')
            . '.pdf';

        $pdf = Pdf::loadView('pdf.pathfinder_annual_application_form', [
            'application' => $application,
            'signaturesByRole' => $application->signatures->keyBy('role'),
            'signatureImages' => $this->signatureImages($application),
        ])->setPaper('letter', 'portrait');

        Storage::disk('public')->put($path, $pdf->output());

        $application->forceFill([
            'pdf_path' => $path,
            'pdf_file_name' => 'pathfinder-annual-application-'
                . Str::slug($application->club?->club_name ?: 'club')
                . '-'
                . Str::slug($application->application_year)
                . '.pdf',
        ])->save();
    }

    private function signatureImages($application): array
    {
        return $application->signatures
            ->filter(fn (PathfinderAnnualApplicationSignature $signature) => $signature->signature_path && Storage::disk('public')->exists($signature->signature_path))
            ->mapWithKeys(function (PathfinderAnnualApplicationSignature $signature) {
                $mime = Storage::disk('public')->mimeType($signature->signature_path) ?: 'image/png';

                return [
                    $signature->role => 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($signature->signature_path)),
                ];
            })
            ->all();
    }

    private function payload(PathfinderAnnualApplicationSignature $signature): array
    {
        $application = $signature->application;

        return [
            'role' => $signature->role,
            'role_label' => $this->roleLabel($signature->role),
            'signer_name' => $signature->signer_name,
            'signer_email' => $signature->signer_email,
            'signed_at' => optional($signature->signed_at)->toDateTimeString(),
            'signature_url' => $signature->signature_url,
            'application' => [
                'club_name' => $application->club?->club_name,
                'application_year' => $application->application_year,
                'due_date' => optional($application->due_date)->toDateString(),
                'sponsoring_church' => $application->sponsoring_church,
                'pastor' => $application->pastor,
                'elected_club_director' => $application->elected_club_director,
                'mailing_address' => $application->mailing_address,
                'director_phone_number' => $application->director_phone_number,
                'board_approval_date' => optional($application->board_approval_date)->toDateString(),
            ],
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            PathfinderAnnualApplicationSignature::ROLE_PASTOR => 'Church Pastor',
            PathfinderAnnualApplicationSignature::ROLE_HEAD_ELDER => 'Head Elder',
            PathfinderAnnualApplicationSignature::ROLE_DIRECTOR => 'Club Director',
            default => $role,
        };
    }
}
