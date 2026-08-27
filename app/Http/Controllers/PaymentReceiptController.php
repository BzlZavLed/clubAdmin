<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Models\Member;
use App\Services\DocumentValidationService;
use App\Services\PaymentReceiptPdfService;
use App\Services\PaymentReceiptService;
use App\Support\ClubHelper;
use App\Support\GeneratedPdfResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentReceiptController extends Controller
{
    public function download(Request $request, PaymentReceipt $receipt, PaymentReceiptPdfService $receiptPdfService)
    {
        $receipt = $this->loadReceiptContext($receipt);
        $this->authorizeReceipt($request->user(), $receipt);
        $this->markAsDownloaded(collect([$receipt]));

        return GeneratedPdfResponse::fromDomPdf(
            $receiptPdfService->make($receipt, $request->user()),
            'generated/payment-receipts',
            $receipt->receipt_number,
            "{$receipt->receipt_number}.pdf",
            $request
        );
    }

    public function publicDownload(Request $request, PaymentReceipt $receipt, PaymentReceiptPdfService $receiptPdfService)
    {
        $receipt = $this->loadReceiptContext($receipt);
        $this->markAsDownloaded(collect([$receipt]));

        return GeneratedPdfResponse::fromDomPdf(
            $receiptPdfService->make($receipt),
            'generated/payment-receipts',
            $receipt->receipt_number,
            "{$receipt->receipt_number}.pdf",
            $request
        );
    }

    public function publicQr(PaymentReceipt $receipt, PaymentReceiptService $paymentReceiptService, DocumentValidationService $documentValidationService)
    {
        $receipt = $this->loadReceiptContext($receipt);

        return response($documentValidationService->qrCodeSvg($paymentReceiptService->publicDownloadUrl($receipt)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadBulk(Request $request, PaymentReceiptPdfService $receiptPdfService)
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
                $receiptPdfService->make($receipt, $request->user())->output()
            );
        }

        $zip->close();

        return response()->download($zipPath, "{$zipName}.zip")->deleteFileAfterSend(true);
    }

    public function sendManual(Request $request, PaymentReceipt $receipt, PaymentReceiptService $paymentReceiptService)
    {
        $receipt = $this->loadReceiptContext($receipt);
        $this->authorizeReceipt($request->user(), $receipt);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $receipt = $paymentReceiptService->queueEmail($receipt, $validated['email']);

        return response()->json([
            'message' => 'Receipt email queued.',
            'data' => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'issued_to_email' => $receipt->issued_to_email,
                'delivery_status' => $receipt->delivery_status,
            ],
        ]);
    }

    public function parentIndex(Request $request)
    {
        $user = $request->user();
        $memberIds = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->pluck('id');

        $receipts = PaymentReceipt::query()
            ->where(function ($query) use ($user, $memberIds) {
                $query->where('parent_user_id', $user->id)
                    ->orWhereIn('member_id', $memberIds);
            })
            ->with([
                'club:id,club_name',
                'payment:id,club_id,member_id,staff_id,amount_paid,payment_date,payment_type,payment_concept_id,concept_text,pay_to',
                'payment.member:id,type,id_data,parent_id',
                'payment.staff:id,type,id_data,user_id',
                'payment.concept:id,concept,amount,reusable',
                'payment.allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
                'payment.allocations.concept:id,concept,event_id,event_fee_component_id',
                'payment.allocations.concept.event:id,title,start_at',
                'payment.allocations.concept.eventFeeComponent:id,label,amount,is_required,sort_order',
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
                'payment.allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
                'payment.allocations.concept:id,concept,event_id,event_fee_component_id',
                'payment.allocations.concept.event:id,title,start_at',
                'payment.allocations.concept.eventFeeComponent:id,label,amount,is_required,sort_order',
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
            'concept_name' => $this->receiptConceptName($payment),
            'member_name' => $memberDetail['name'] ?? null,
            'staff_name' => $staffDetail['name'] ?? null,
            'payer_name' => $memberDetail['name'] ?? $staffDetail['name'] ?? $payment?->payer_name,
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

    protected function receiptConceptName($payment): string
    {
        if (!$payment) {
            return '—';
        }

        if ($payment->relationLoaded('allocations') && $payment->allocations->isNotEmpty()) {
            return $payment->allocations->first()?->concept?->event?->title
                ?: $payment->concept_text
                ?: 'Pago de evento';
        }

        return $payment->concept?->concept ?? $payment->concept_text ?? '—';
    }

    protected function authorizeReceipt($user, PaymentReceipt $receipt): void
    {
        if (!$user) {
            abort(401);
        }

        if ($user->status !== null && $user->status !== 'active') {
            abort(403);
        }

        if ($user->profile_type === 'superadmin') {
            return;
        }

        if (
            $user->profile_type === 'parent'
            && $user->canAccessParentPortal()
            && (
                ($receipt->parent_user_id && (int) $receipt->parent_user_id === (int) $user->id)
                || (
                    $receipt->payment?->member?->parent_id
                    && (int) $receipt->payment->member->parent_id === (int) $user->id
                )
            )
        ) {
            return;
        }

        if (
            $receipt->staff_user_id
            && (int) $receipt->staff_user_id === (int) $user->id
            && $user->hasVerifiedEmail()
        ) {
            return;
        }

        if (
            in_array($user->profile_type, ['club_director', 'club_personal', 'treasurer'], true)
            && $user->hasVerifiedEmail()
        ) {
            $clubIds = ClubHelper::clubIdsForUser($user);
            if ($clubIds->contains((int) $receipt->club_id)) {
                return;
            }
        }

        abort(403);
    }

    protected function loadReceiptContext(PaymentReceipt $receipt): PaymentReceipt
    {
        return app(PaymentReceiptPdfService::class)->loadReceiptContext($receipt);
    }
}
