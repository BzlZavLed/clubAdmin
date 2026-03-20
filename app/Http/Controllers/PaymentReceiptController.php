<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Support\ClubHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PaymentReceiptController extends Controller
{
    public function download(Request $request, PaymentReceipt $receipt)
    {
        $receipt->loadMissing([
            'club:id,club_name,church_name',
            'payment.club:id,club_name,church_name',
            'payment.member:id,type,id_data,parent_id',
            'payment.staff:id,type,id_data,user_id',
            'payment.concept:id,concept,amount,reusable',
            'payment.account:id,club_id,pay_to,label',
            'payment.receivedBy:id,name,email',
            'parentUser:id,name,email',
            'staffUser:id,name,email',
        ]);

        $this->authorizeReceipt($request->user(), $receipt);

        $payment = $receipt->payment;
        $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;
        $staffDetail = $payment ? ClubHelper::staffDetail($payment->staff) : null;

        $pdf = Pdf::loadView('pdf.payment_receipt', [
            'receipt' => $receipt,
            'payment' => $payment,
            'club' => $receipt->club ?? $payment?->club,
            'member_name' => $memberDetail['name'] ?? null,
            'staff_name' => $staffDetail['name'] ?? null,
            'recipient_name' => $receipt->parentUser?->name ?? $receipt->staffUser?->name ?? $memberDetail['name'] ?? $staffDetail['name'] ?? '—',
            'recipient_email' => $receipt->issued_to_email,
        ])->setPaper('a4');

        return $pdf->download("{$receipt->receipt_number}.pdf");
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
}
