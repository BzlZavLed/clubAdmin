<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ParentHelpController extends Controller
{
    private const ACCOUNT_GUIDE_PATH = 'output/pdf/parent-account-and-child-registration-guide.pdf';

    private const PAYMENTS_GUIDE_PATH = 'output/pdf/parent-payments-guide.pdf';

    public function index(): Response
    {
        return Inertia::render('Parent/Help', [
            'account_guide_url' => route('parent.help.guide'),
            'payments_guide_url' => route('parent.help.payments-guide'),
        ]);
    }

    public function guide(): BinaryFileResponse
    {
        return $this->inlinePdf(self::ACCOUNT_GUIDE_PATH, 'parent-account-and-child-registration-guide.pdf');
    }

    public function paymentsGuide(): BinaryFileResponse
    {
        return $this->inlinePdf(self::PAYMENTS_GUIDE_PATH, 'parent-payments-guide.pdf');
    }

    private function inlinePdf(string $relativePath, string $filename): BinaryFileResponse
    {
        $path = base_path($relativePath);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
