<?php

namespace App\Services;

use App\Jobs\SendPaymentReceiptEmail;
use App\Models\Club;
use App\Models\FundraiserSale;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Finance\FinanceFundraiserService;
use App\Services\Mail\MailerService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class PaymentReceiptService
{
    public function __construct(private readonly MailerService $mailerService)
    {
    }

    public function syncForPayment(Payment $payment): PaymentReceipt
    {
        $payment->load([
            'club:id,club_name',
            'member:id,type,id_data,parent_id',
            'staff:id,type,id_data,user_id',
            'concept:id,concept,amount,reusable',
            'account:id,club_id,pay_to,label',
            'receivedBy:id,name,email',
        ]);

        $parentUserId = $payment->member?->parent_id ?: null;
        $staffUserId = $payment->staff?->user_id ?: null;

        $parentEmail = $parentUserId ? User::query()->whereKey($parentUserId)->value('email') : null;
        $staffEmail = $staffUserId ? User::query()->whereKey($staffUserId)->value('email') : null;

        $issuedToType = null;
        $issuedToEmail = null;

        if ($parentUserId) {
            $issuedToType = 'parent';
            $issuedToEmail = $parentEmail;
        } elseif ($payment->staff_id && $staffUserId) {
            $issuedToType = 'staff';
            $issuedToEmail = $staffEmail;
        } elseif ($payment->member_id) {
            $issuedToType = 'member_unlinked';
        } elseif ($payment->staff_id) {
            $issuedToType = 'staff_unlinked';
        } elseif ($payment->payer_name) {
            $issuedToType = 'external_payer';
            $issuedToEmail = $payment->payer_email;
        }

        $shouldQueueEmail = false;

        $receipt = DB::transaction(function () use ($payment, $parentUserId, $staffUserId, $issuedToType, $issuedToEmail, &$shouldQueueEmail) {
            Club::query()
                ->whereKey($payment->club_id)
                ->lockForUpdate()
                ->first(['id']);

            $fundraiserSaleId = $payment->source_type === FinanceFundraiserService::SOURCE_TYPE
                ? $payment->source_id
                : null;
            $receipt = PaymentReceipt::withTrashed()
                ->where(function ($query) use ($payment, $fundraiserSaleId) {
                    $query->where('payment_id', $payment->id);
                    if ($fundraiserSaleId) {
                        $query->orWhere('fundraiser_sale_id', $fundraiserSaleId);
                    }
                })
                ->lockForUpdate()
                ->first();

            $issuedAt = $receipt?->issued_at ?? $payment->created_at ?? now();
            $receiptYear = (int) $issuedAt->format('Y');
            $clubCode = $receipt?->club_code ?: $this->clubCodeForPayment($payment);
            $clubSequence = $receipt?->club_sequence ?: $this->nextClubSequence((int) $payment->club_id, $receiptYear);
            $deliveryStatus = $this->deliveryStatusForSync($receipt, $issuedToEmail);

            $payload = [
                'club_id' => $payment->club_id,
                'payment_id' => $payment->id,
                'fundraiser_sale_id' => $fundraiserSaleId ?: $receipt?->fundraiser_sale_id,
                'club_code' => $clubCode,
                'receipt_year' => $receiptYear,
                'club_sequence' => $clubSequence,
                'member_id' => $payment->member_id,
                'staff_id' => $payment->staff_id,
                'parent_user_id' => $parentUserId,
                'staff_user_id' => $staffUserId,
                'receipt_number' => $receipt?->receipt_number ?: $this->receiptNumber($issuedAt, $clubCode, $clubSequence),
                'issued_to_type' => $issuedToType,
                'issued_to_email' => $issuedToEmail,
                'issued_at' => $issuedAt,
                'delivery_status' => $deliveryStatus,
                'deleted_at' => null,
            ];

            $shouldQueueEmail = (bool) $issuedToEmail
                && config('mail.payment_receipts.auto_send', false)
                && (!$receipt || in_array($receipt->delivery_status, ['pending', 'manual_required'], true) || empty($receipt->issued_to_email));

            if ($receipt) {
                $receipt->fill($payload)->save();
                return $receipt;
            }

            return PaymentReceipt::query()->create([
                ...$payload,
            ]);
        });

        if ($shouldQueueEmail) {
            $receipt->forceFill(['delivery_status' => 'queued'])->save();
            $mailLog = $this->mailerService->queuePaymentReceipt($receipt);
            SendPaymentReceiptEmail::dispatch($receipt->id, $mailLog->id)->afterCommit();
        }

        return $receipt;
    }

    public function syncForFundraiserSale(FundraiserSale $sale): PaymentReceipt
    {
        $sale->loadMissing(['club:id,club_name', 'payment']);

        if ($sale->payment) {
            return $this->syncForPayment($sale->payment);
        }

        return DB::transaction(function () use ($sale) {
            Club::query()->whereKey($sale->club_id)->lockForUpdate()->first(['id']);

            $receipt = PaymentReceipt::withTrashed()
                ->where('fundraiser_sale_id', $sale->id)
                ->lockForUpdate()
                ->first();
            $issuedAt = $receipt?->issued_at ?? $sale->created_at ?? now();
            $receiptYear = (int) $issuedAt->format('Y');
            $clubCode = $receipt?->club_code ?: $this->clubCode($sale->club?->club_name, (int) $sale->club_id);
            $clubSequence = $receipt?->club_sequence ?: $this->nextClubSequence((int) $sale->club_id, $receiptYear);
            $payload = [
                'payment_id' => null,
                'fundraiser_sale_id' => $sale->id,
                'club_id' => $sale->club_id,
                'club_code' => $clubCode,
                'receipt_year' => $receiptYear,
                'club_sequence' => $clubSequence,
                'receipt_number' => $receipt?->receipt_number ?: $this->receiptNumber($issuedAt, $clubCode, $clubSequence),
                'issued_to_type' => $sale->customer_name ? 'external_payer' : null,
                'issued_to_email' => null,
                'issued_at' => $issuedAt,
                'delivery_status' => 'manual_required',
                'deleted_at' => null,
            ];

            if ($receipt) {
                $receipt->fill($payload)->save();
                return $receipt;
            }

            return PaymentReceipt::query()->create($payload);
        });
    }

    public function deleteForPayment(Payment $payment): void
    {
        PaymentReceipt::query()
            ->where('payment_id', $payment->id)
            ->delete();
    }

    public function queueEmail(PaymentReceipt $receipt, string $email): PaymentReceipt
    {
        $receipt->load('payment:id,member_id,staff_id,payer_email');

        DB::transaction(function () use ($receipt, $email): void {
            $receipt->forceFill([
                'issued_to_email' => $email,
                'delivery_status' => 'queued',
                'delivered_at' => null,
            ])->save();

            if ($receipt->payment && !$receipt->payment->member_id && !$receipt->payment->staff_id) {
                $receipt->payment->forceFill(['payer_email' => $email])->save();
            }
        });

        $mailLog = $this->mailerService->queuePaymentReceipt($receipt);
        SendPaymentReceiptEmail::dispatch($receipt->id, $mailLog->id)->afterCommit();

        return $receipt->refresh();
    }

    public function resyncPendingForClub(int $clubId): void
    {
        PaymentReceipt::query()
            ->where('club_id', $clubId)
            ->whereIn('delivery_status', ['pending', 'manual_required', 'failed'])
            ->with('payment')
            ->get()
            ->each(function (PaymentReceipt $receipt): void {
                if ($receipt->payment) {
                    $this->syncForPayment($receipt->payment);
                }
            });
    }

    public function publicDownloadUrl(PaymentReceipt $receipt): string
    {
        return URL::signedRoute('payment-receipts.public-download', ['receipt' => $receipt]);
    }

    public function publicQrUrl(PaymentReceipt $receipt): string
    {
        return URL::signedRoute('payment-receipts.public-qr', ['receipt' => $receipt]);
    }

    protected function receiptNumber($issuedAt, string $clubCode, int $clubSequence): string
    {
        return sprintf('RCPT-%s-%s-%06d', $issuedAt->format('Y'), $clubCode, $clubSequence);
    }

    protected function deliveryStatusForSync(?PaymentReceipt $receipt, ?string $issuedToEmail): string
    {
        if (!$issuedToEmail) {
            return 'manual_required';
        }

        if (!$receipt || $receipt->delivery_status === 'manual_required') {
            return 'pending';
        }

        return $receipt->delivery_status ?: 'pending';
    }

    protected function clubCodeForPayment(Payment $payment): string
    {
        return $this->clubCode($payment->club?->club_name, (int) $payment->club_id);
    }

    protected function clubCode(?string $clubName, int $clubId): string
    {
        $name = $clubName ?: 'CLUB';
        $letters = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $name));
        $prefix = substr($letters ?: 'CLUB', 0, 4);
        $suffix = str_pad((string) $clubId, 7, '0', STR_PAD_LEFT);

        return substr(str_pad($prefix, 4, 'X') . $suffix, 0, 12);
    }

    protected function nextClubSequence(int $clubId, int $year): int
    {
        $max = PaymentReceipt::withTrashed()
            ->where('club_id', $clubId)
            ->where('receipt_year', $year)
            ->max('club_sequence');

        return ((int) $max) + 1;
    }
}
