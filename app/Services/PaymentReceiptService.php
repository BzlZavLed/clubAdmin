<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PaymentReceiptService
{
    public function syncForPayment(Payment $payment): PaymentReceipt
    {
        $payment->loadMissing([
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
        }

        return DB::transaction(function () use ($payment, $parentUserId, $staffUserId, $issuedToType, $issuedToEmail) {
            $receipt = PaymentReceipt::withTrashed()
                ->where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();

            $issuedAt = $payment->created_at ?? now();
            $receiptYear = (int) $issuedAt->format('Y');
            $clubCode = $receipt?->club_code ?: $this->clubCodeForPayment($payment);
            $clubSequence = $receipt?->club_sequence ?: $this->nextClubSequence((int) $payment->club_id, $receiptYear);

            $payload = [
                'club_id' => $payment->club_id,
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
                'delivery_status' => 'pending',
                'deleted_at' => null,
            ];

            if ($receipt) {
                $receipt->fill($payload)->save();
                return $receipt;
            }

            return PaymentReceipt::query()->create([
                'payment_id' => $payment->id,
                ...$payload,
            ]);
        });
    }

    public function deleteForPayment(Payment $payment): void
    {
        PaymentReceipt::query()
            ->where('payment_id', $payment->id)
            ->delete();
    }

    protected function receiptNumber($issuedAt, string $clubCode, int $clubSequence): string
    {
        return sprintf('RCPT-%s-%s-%06d', $issuedAt->format('Y'), $clubCode, $clubSequence);
    }

    protected function clubCodeForPayment(Payment $payment): string
    {
        $name = $payment->club?->club_name ?: 'CLUB';
        $letters = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $name));
        $prefix = substr($letters ?: 'CLUB', 0, 4);
        $suffix = str_pad((string) $payment->club_id, 7, '0', STR_PAD_LEFT);

        return substr(str_pad($prefix, 4, 'X') . $suffix, 0, 12);
    }

    protected function nextClubSequence(int $clubId, int $year): int
    {
        $max = PaymentReceipt::withTrashed()
            ->where('club_id', $clubId)
            ->whereYear('issued_at', $year)
            ->lockForUpdate()
            ->max('club_sequence');

        return ((int) $max) + 1;
    }
}
