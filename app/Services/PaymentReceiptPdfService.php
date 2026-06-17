<?php

namespace App\Services;

use App\Models\FundraiserSale;
use App\Models\PaymentReceipt;
use App\Services\Finance\FinanceFundraiserService;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;

class PaymentReceiptPdfService
{
    public function __construct(
        private readonly DocumentValidationService $documentValidationService,
        private readonly ClubLogoService $clubLogoService,
    ) {
    }

    public function make(PaymentReceipt $receipt, $generatedBy = null)
    {
        $receipt = $this->loadReceiptContext($receipt);
        $payment = $receipt->payment;
        $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;
        $staffDetail = $payment ? ClubHelper::staffDetail($payment->staff) : null;
        $club = $receipt->club ?? $payment?->club;
        $recipientName = $receipt->parentUser?->name ?? $receipt->staffUser?->name ?? $memberDetail['name'] ?? $staffDetail['name'] ?? $payment?->payer_name ?? '-';
        $conceptName = $this->receiptConceptName($payment);
        $fundraiserOrder = $this->fundraiserOrderForPayment($payment);
        $isCancellationReceipt = $payment && (
            (float) $payment->amount_paid < 0
            || !empty($payment->canceling_id)
            || !empty($payment->reversed_payment_id)
        );
        $receiptTitle = $isCancellationReceipt ? 'Recibo de cancelacion' : 'Recibo de ingreso';
        $generatedAt = now();
        $validation = $this->documentValidationService->create(
            documentType: $isCancellationReceipt ? 'payment_cancellation_receipt' : 'payment_receipt',
            title: $receiptTitle,
            snapshot: [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => optional($receipt->issued_at)->toISOString(),
                'club_id' => $club?->id,
                'payment_id' => $payment?->id,
                'payment_date' => optional($payment?->payment_date)->toDateString(),
                'amount_paid' => $payment?->amount_paid,
                'payment_type' => $payment?->payment_type,
                'concept' => $conceptName,
                'account' => $payment?->account?->label ?? $payment?->pay_to,
                'recipient_name' => $recipientName,
                'recipient_email' => $receipt->issued_to_email,
                'is_cancellation' => $isCancellationReceipt,
                'canceling_payment_id' => $payment?->canceling_id ?: $payment?->reversed_payment_id,
                'member_name' => $memberDetail['name'] ?? null,
                'staff_name' => $staffDetail['name'] ?? null,
                'payer_name' => $payment?->payer_name,
                'fundraiser_order' => $fundraiserOrder,
            ],
            metadata: [
                'Recibo' => $receipt->receipt_number,
                'Club' => $club?->club_name ?? '-',
                'Pagador' => $recipientName,
                'Concepto' => $conceptName,
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
            'concept_name' => $conceptName,
            'receiptTitle' => $receiptTitle,
            'isCancellationReceipt' => $isCancellationReceipt,
            'originalPaymentId' => $payment?->canceling_id ?: $payment?->reversed_payment_id,
            'fundraiserOrder' => $fundraiserOrder,
            'clubLogoDataUri' => $this->clubLogoService->dataUri($club),
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4');
    }

    public function loadReceiptContext(PaymentReceipt $receipt): PaymentReceipt
    {
        $receipt->loadMissing([
            'club:id,club_name,church_name,logo_path',
            'payment.club:id,club_name,church_name,logo_path',
            'payment.member:id,type,id_data,parent_id',
            'payment.staff:id,type,id_data,user_id',
            'payment.concept:id,concept,amount,reusable',
            'payment.allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
            'payment.allocations.concept:id,concept,event_id,event_fee_component_id',
            'payment.allocations.concept.event:id,title,start_at',
            'payment.allocations.concept.eventFeeComponent:id,label,amount,is_required,sort_order',
            'payment.account:id,club_id,pay_to,label',
            'payment.receivedBy:id,name,email',
            'parentUser:id,name,email',
            'staffUser:id,name,email',
        ]);

        return $receipt;
    }

    public function receiptConceptName($payment): string
    {
        if (!$payment) {
            return '-';
        }

        if ($payment->relationLoaded('allocations') && $payment->allocations->isNotEmpty()) {
            return $payment->allocations->first()?->concept?->event?->title
                ?: $payment->concept_text
                ?: 'Pago de evento';
        }

        return $payment->concept?->concept ?? $payment->concept_text ?? '-';
    }

    private function fundraiserOrderForPayment($payment): ?array
    {
        if (
            !$payment
            || $payment->source_type !== FinanceFundraiserService::SOURCE_TYPE
            || !$payment->source_id
        ) {
            return null;
        }

        $sale = FundraiserSale::query()
            ->with(['fundraiserEvent:id,name,fundraiser_type', 'items'])
            ->whereKey($payment->source_id)
            ->where('payment_id', $payment->id)
            ->first();

        if (!$sale) {
            return null;
        }

        return [
            'event_name' => $sale->fundraiserEvent?->name,
            'event_type' => $sale->fundraiserEvent?->fundraiser_type,
            'customer_name' => $sale->customer_name,
            'sale_date' => optional($sale->sale_date)->toDateString(),
            'payment_type' => $sale->payment_type,
            'total_amount' => (float) $sale->total_amount,
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ];
    }
}
