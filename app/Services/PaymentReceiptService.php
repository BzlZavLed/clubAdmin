<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;

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

        return PaymentReceipt::withTrashed()->updateOrCreate(
            ['payment_id' => $payment->id],
            [
                'club_id' => $payment->club_id,
                'member_id' => $payment->member_id,
                'staff_id' => $payment->staff_id,
                'parent_user_id' => $parentUserId,
                'staff_user_id' => $staffUserId,
                'receipt_number' => $this->receiptNumberForPayment($payment),
                'issued_to_type' => $issuedToType,
                'issued_to_email' => $issuedToEmail,
                'issued_at' => $payment->created_at ?? now(),
                'delivery_status' => 'pending',
                'deleted_at' => null,
            ]
        );
    }

    public function deleteForPayment(Payment $payment): void
    {
        PaymentReceipt::query()
            ->where('payment_id', $payment->id)
            ->delete();
    }

    protected function receiptNumberForPayment(Payment $payment): string
    {
        return sprintf('RCPT-%s-%06d', now()->format('Y'), $payment->id);
    }
}
