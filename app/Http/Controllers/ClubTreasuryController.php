<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Account;
use App\Models\Payment;
use App\Models\TreasuryMovement;
use App\Services\AttendanceDuesPaymentService;
use App\Services\ClubTreasuryService;
use App\Support\BankInfoFormatter;
use App\Support\ClubHelper;
use Illuminate\Http\Request;

class ClubTreasuryController extends Controller
{
    public function __construct(private readonly ClubTreasuryService $treasuryService)
    {
    }

    public function data(Request $request)
    {
        $club = $this->resolveAllowedClub($request);
        $summary = $this->treasuryService->summary($club);

        return response()->json([
            'club' => [
                'id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'bank_info' => BankInfoFormatter::payload($this->treasuryService->clubBankInfo($club)),
            'accounts' => Account::query()
                ->where('club_id', $club->id)
                ->orderBy('label')
                ->get(['pay_to', 'label'])
                ->map(fn (Account $account) => [
                    'value' => $account->pay_to,
                    'label' => $account->label ?: $account->pay_to,
                ])
                ->values(),
            'summary' => $summary,
            'income_rows' => $this->treasuryService->incomeRows($club)->values(),
            'pending_staff_remittances' => $this->pendingStaffRemittanceRows($club),
            'movements' => $this->movementRows($club),
        ]);
    }

    protected function resolveAllowedClub(Request $request): Club
    {
        return ClubHelper::clubForUser($request->user(), $request->input('club_id'));
    }

    protected function movementRows(Club $club): array
    {
        $accountLabels = Account::query()
            ->where('club_id', $club->id)
            ->pluck('label', 'pay_to');

        return TreasuryMovement::query()
            ->where('club_id', $club->id)
            ->with(['creator:id,name', 'event:id,title', 'eventClubSettlement:id,receipt_number'])
            ->latest('movement_date')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TreasuryMovement $movement) => [
                'id' => (int) $movement->id,
                'pay_to' => $movement->pay_to,
                'from_pay_to' => $movement->from_pay_to ?: $movement->pay_to,
                'to_pay_to' => $movement->to_pay_to ?: $movement->pay_to,
                'account_label' => $accountLabels[$movement->pay_to] ?? $movement->pay_to,
                'from_account_label' => $accountLabels[$movement->from_pay_to ?: $movement->pay_to] ?? ($movement->from_pay_to ?: $movement->pay_to),
                'to_account_label' => $accountLabels[$movement->to_pay_to ?: $movement->pay_to] ?? ($movement->to_pay_to ?: $movement->pay_to),
                'movement_type' => $movement->movement_type,
                'from_location' => $movement->from_location,
                'to_location' => $movement->to_location,
                'amount' => (float) $movement->amount,
                'movement_date' => optional($movement->movement_date)->toDateString(),
                'reference' => $movement->reference,
                'notes' => $movement->notes,
                'proof_url' => $movement->proof_path ? asset('storage/' . $movement->proof_path) : null,
                'event_title' => $movement->event?->title,
                'receipt_number' => $movement->eventClubSettlement?->receipt_number,
                'created_by' => $movement->creator?->name,
            ])
            ->values()
            ->all();
    }

    protected function pendingStaffRemittanceRows(Club $club): array
    {
        return Payment::query()
            ->where('club_id', $club->id)
            ->where('custody_status', AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING)
            ->with([
                'heldBy:id,name,email',
                'member:id,type,id_data,parent_id',
                'receipt:id,payment_id,receipt_number',
            ])
            ->latest('remitted_at')
            ->latest('id')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->remittance_batch_id ?: "single-{$payment->id}")
            ->map(function ($payments, $batchId) {
                $first = $payments->first();

                return [
                    'batch_id' => $batchId,
                    'held_by_user_id' => $first?->held_by_user_id,
                    'staff_name' => $first?->heldBy?->name ?: '—',
                    'staff_email' => $first?->heldBy?->email,
                    'amount' => round((float) $payments->sum('amount_paid'), 2),
                    'count' => $payments->count(),
                    'remittance_method' => $first?->remittance_method,
                    'remittance_reference' => $first?->remittance_reference,
                    'remittance_notes' => $first?->remittance_notes,
                    'remitted_at' => optional($first?->remitted_at)->toDateTimeString(),
                    'payments' => $payments->map(function (Payment $payment) {
                        $member = ClubHelper::memberDetail($payment->member);

                        return [
                            'id' => (int) $payment->id,
                            'payer_name' => $member['name'] ?? '—',
                            'amount_paid' => (float) $payment->amount_paid,
                            'payment_date' => optional($payment->payment_date)->toDateString(),
                            'receipt_number' => $payment->receipt?->receipt_number,
                            'receipt_url' => $payment->receipt ? route('payment-receipts.download', $payment->receipt) : null,
                        ];
                    })->values(),
                ];
            })
            ->values()
            ->all();
    }

}
