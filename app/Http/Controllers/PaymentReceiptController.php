<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Services\ClubLogoService;
use App\Services\DocumentValidationService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentReceiptController extends Controller
{
    public function download(Request $request, PaymentReceipt $receipt, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $receipt = $this->loadReceiptContext($receipt);
        $this->authorizeReceipt($request->user(), $receipt);
        $this->markAsDownloaded(collect([$receipt]));

        return $this->makeReceiptPdf($receipt, $documentValidationService, $clubLogoService, $request->user())->download("{$receipt->receipt_number}.pdf");
    }

    public function downloadBulk(Request $request, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService)
    {
        $validated = $request->validate([
            'receipt_ids' => ['required', 'array', 'min:1'],
            'receipt_ids.*' => ['integer', 'exists:payment_receipts,id'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $receipts = PaymentReceipt::query()
            ->whereIn('id', $validated['receipt_ids'])
            ->get()
            ->map(fn ($receipt) => $this->loadReceiptContext($receipt))
            ->values();

        abort_if($receipts->isEmpty(), 404);

        foreach ($receipts as $receipt) {
            $this->authorizeReceipt($request->user(), $receipt);
        }

        $this->markAsDownloaded($receipts);

        $zipName = Str::slug($validated['label'] ?: 'payment-receipts');
        $zipPath = storage_path('app/temp/' . uniqid($zipName . '-', true) . '.zip');
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0775, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            abort(500, 'No se pudo crear el ZIP de recibos.');
        }

        foreach ($receipts as $receipt) {
            $zip->addFromString(
                "{$receipt->receipt_number}.pdf",
                $this->makeReceiptPdf($receipt, $documentValidationService, $clubLogoService, $request->user())->output()
            );
        }

        $zip->close();

        return response()->download($zipPath, "{$zipName}.zip")->deleteFileAfterSend(true);
    }

    public function parentIndex(Request $request)
    {
        $user = $request->user();

        $receipts = PaymentReceipt::query()
            ->where('parent_user_id', $user->id)
            ->with([
                'club:id,club_name',
                'payment:id,club_id,member_id,staff_id,amount_paid,payment_date,payment_type,payment_concept_id,concept_text,pay_to',
                'payment.member:id,type,id_data,parent_id',
                'payment.staff:id,type,id_data,user_id',
                'payment.concept:id,concept,amount,reusable',
            ])
            ->latest('issued_at')
            ->get()
            ->map(fn ($receipt) => $this->transformReceipt($receipt))
            ->values();

        return response()->json(['data' => $receipts]);
    }

    public function staffIndex(Request $request)
    {
        $user = $request->user();

        $receipts = PaymentReceipt::query()
            ->where('staff_user_id', $user->id)
            ->with([
                'club:id,club_name',
                'payment:id,club_id,member_id,staff_id,amount_paid,payment_date,payment_type,payment_concept_id,concept_text,pay_to',
                'payment.member:id,type,id_data,parent_id',
                'payment.staff:id,type,id_data,user_id',
                'payment.concept:id,concept,amount,reusable',
            ])
            ->latest('issued_at')
            ->get()
            ->map(fn ($receipt) => $this->transformReceipt($receipt))
            ->values();

        return response()->json(['data' => $receipts]);
    }

    protected function transformReceipt(PaymentReceipt $receipt): array
    {
        $payment = $receipt->payment;
        $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;
        $staffDetail = $payment ? ClubHelper::staffDetail($payment->staff) : null;

        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'issued_at' => optional($receipt->issued_at)->toDateString(),
            'delivery_status' => $receipt->delivery_status,
            'issued_to_type' => $receipt->issued_to_type,
            'issued_to_email' => $receipt->issued_to_email,
            'last_downloaded_at' => optional($receipt->last_downloaded_at)->toDateTimeString(),
            'club_name' => $receipt->club?->club_name,
            'amount_paid' => $payment?->amount_paid,
            'payment_date' => optional($payment?->payment_date)->toDateString(),
            'payment_type' => $payment?->payment_type,
            'concept_name' => $payment?->concept?->concept ?? $payment?->concept_text,
            'member_name' => $memberDetail['name'] ?? null,
            'staff_name' => $staffDetail['name'] ?? null,
            'download_url' => route('payment-receipts.download', $receipt),
        ];
    }

    protected function markAsDownloaded($receipts): void
    {
        $ids = collect($receipts)
            ->pluck('id')
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        PaymentReceipt::query()
            ->whereIn('id', $ids)
            ->update(['last_downloaded_at' => Carbon::now()]);
    }

    protected function authorizeReceipt($user, PaymentReceipt $receipt): void
    {
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            return;
        }

        if ($receipt->parent_user_id && (int) $receipt->parent_user_id === (int) $user->id) {
            return;
        }

        if ($receipt->staff_user_id && (int) $receipt->staff_user_id === (int) $user->id) {
            return;
        }

        if (in_array($user->profile_type, ['club_director', 'club_personal'], true)) {
            $clubIds = ClubHelper::clubIdsForUser($user);
            if ($clubIds->contains((int) $receipt->club_id)) {
                return;
            }
        }

        abort(403);
    }

    protected function loadReceiptContext(PaymentReceipt $receipt): PaymentReceipt
    {
        $receipt->loadMissing([
            'club:id,club_name,church_name,logo_path',
            'payment.club:id,club_name,church_name,logo_path',
            'payment.member:id,type,id_data,parent_id',
            'payment.staff:id,type,id_data,user_id',
            'payment.concept:id,concept,amount,reusable',
            'payment.account:id,club_id,pay_to,label',
            'payment.receivedBy:id,name,email',
            'parentUser:id,name,email',
            'staffUser:id,name,email',
        ]);

        return $receipt;
    }

    protected function makeReceiptPdf(PaymentReceipt $receipt, DocumentValidationService $documentValidationService, ClubLogoService $clubLogoService, $generatedBy = null)
    {
        $payment = $receipt->payment;
        $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;
        $staffDetail = $payment ? ClubHelper::staffDetail($payment->staff) : null;
        $club = $receipt->club ?? $payment?->club;
        $recipientName = $receipt->parentUser?->name ?? $receipt->staffUser?->name ?? $memberDetail['name'] ?? $staffDetail['name'] ?? '—';
        $generatedAt = now();
        $validation = $documentValidationService->create(
            documentType: 'payment_receipt',
            title: 'Recibo de ingreso',
            snapshot: [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => optional($receipt->issued_at)->toISOString(),
                'club_id' => $club?->id,
                'payment_id' => $payment?->id,
                'payment_date' => optional($payment?->payment_date)->toDateString(),
                'amount_paid' => $payment?->amount_paid,
                'payment_type' => $payment?->payment_type,
                'concept' => $payment?->concept?->concept ?? $payment?->concept_text,
                'account' => $payment?->account?->label ?? $payment?->pay_to,
                'recipient_name' => $recipientName,
                'recipient_email' => $receipt->issued_to_email,
                'member_name' => $memberDetail['name'] ?? null,
                'staff_name' => $staffDetail['name'] ?? null,
            ],
            metadata: [
                'Recibo' => $receipt->receipt_number,
                'Club' => $club?->club_name ?? '—',
                'Pagador' => $recipientName,
                'Concepto' => $payment?->concept?->concept ?? $payment?->concept_text ?? '—',
                'Importe' => '$' . number_format((float) ($payment?->amount_paid ?? 0), 2),
            ],
            generatedBy: $generatedBy,
            generatedAt: $generatedAt,
        );

        return Pdf::loadView('pdf.payment_receipt', [
            'receipt' => $receipt,
            'payment' => $payment,
            'club' => $club,
            'member_name' => $memberDetail['name'] ?? null,
            'staff_name' => $staffDetail['name'] ?? null,
            'recipient_name' => $recipientName,
            'recipient_email' => $receipt->issued_to_email,
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4');
    }
}
