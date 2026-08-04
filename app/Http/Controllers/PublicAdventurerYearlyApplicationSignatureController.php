<?php

namespace App\Http\Controllers;

use App\Models\AdventurerYearlyApplicationSignature;
use App\Services\AdventurerYearlyApplicationDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PublicAdventurerYearlyApplicationSignatureController extends Controller
{
    public function show(string $token): Response
    {
        $signature = $this->signatureFromToken($token);

        return Inertia::render('Public/AdventurerYearlyApplicationSignature', [
            'signature_request' => $this->payload($signature),
            'submit_url' => route('adventurer-yearly-applications.signatures.submit', ['token' => $token]),
        ]);
    }

    public function submit(
        Request $request,
        string $token,
        AdventurerYearlyApplicationDocumentService $documentService,
    ) {
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

        $documentService->generate($signature->application->refresh()->load(['club', 'signatures']));

        return response()->json([
            'message' => 'Firma guardada.',
            'data' => $this->payload($signature->refresh()),
        ]);
    }

    private function signatureFromToken(string $token): AdventurerYearlyApplicationSignature
    {
        $signature = AdventurerYearlyApplicationSignature::query()
            ->with('application.club')
            ->where('request_token', $token)
            ->firstOrFail();

        abort_if(
            $signature->expires_at && $signature->expires_at->isPast() && ! $signature->signed_at,
            410,
            'El enlace de firma expiro.'
        );

        return $signature;
    }

    private function storeSignatureImage(
        string $signatureData,
        AdventurerYearlyApplicationSignature $signature,
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
            .$signature->adventurer_yearly_application_id
            .'/'.$signature->role.'-'.now()->format('YmdHis').'.png';
        Storage::disk('public')->put($path, $image);

        return $path;
    }

    private function payload(AdventurerYearlyApplicationSignature $signature): array
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
                'club_name' => $application->club_name,
                'application_year' => $application->application_year,
                'application_date' => optional($application->application_date)->toDateString(),
                'sponsoring_church' => $application->sponsoring_church,
                'pastor' => $application->pastor,
                'elected_club_director' => $application->elected_club_director,
                'email_address' => $application->email_address,
                'cell_number' => $application->cell_number,
                'home_address' => $application->home_address,
            ],
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            AdventurerYearlyApplicationSignature::ROLE_PASTOR => 'Church Pastor',
            AdventurerYearlyApplicationSignature::ROLE_HEAD_ELDER => 'Head Elder',
            AdventurerYearlyApplicationSignature::ROLE_CHURCH_CLERK => 'Church Clerk',
            AdventurerYearlyApplicationSignature::ROLE_DIRECTOR => 'Club Director',
            default => $role,
        };
    }
}
