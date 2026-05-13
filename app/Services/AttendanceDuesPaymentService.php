<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Payment;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use App\Models\User;
use Illuminate\Support\Collection;

class AttendanceDuesPaymentService
{
    public const SOURCE_TYPE = 'attendance_report';
    public const CUSTODY_CLUB_RECEIVED = 'club_received';
    public const CUSTODY_HELD_BY_STAFF = 'held_by_staff';
    public const CUSTODY_REMITTED_PENDING = 'remitted_pending_validation';

    public function __construct(private readonly PaymentReceiptService $receiptService)
    {
    }

    public function syncForReport(RepAssistanceAdv $report, Collection $merits, User $actor): void
    {
        $isStaffCollector = $actor->profile_type === 'club_personal';
        $account = $this->clubBudgetAccount((int) $report->club_id);

        foreach ($merits as $merit) {
            if (!$this->shouldCreatePayment($merit)) {
                continue;
            }

            $amount = round((float) $merit->cuota_amount, 2);
            $custodyStatus = $isStaffCollector
                ? self::CUSTODY_HELD_BY_STAFF
                : self::CUSTODY_CLUB_RECEIVED;

            $payment = Payment::query()->create([
                'club_id' => $report->club_id,
                'payment_concept_id' => null,
                'concept_text' => $this->conceptText($report),
                'pay_to' => 'club_budget',
                'account_id' => $account->id,
                'member_id' => $merit->member_id,
                'staff_id' => null,
                'amount_paid' => $amount,
                'expected_amount' => $amount,
                'balance_due_after' => 0,
                'payment_date' => $report->date,
                'payment_type' => 'cash',
                'received_by_user_id' => $actor->id,
                'notes' => 'Ingreso generado automaticamente desde toma de asistencia.',
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $report->id,
                'source_line_id' => $merit->id,
                'custody_status' => $custodyStatus,
                'held_by_user_id' => $isStaffCollector ? $actor->id : null,
            ]);

            if ($custodyStatus === self::CUSTODY_CLUB_RECEIVED) {
                $account->increment('balance', $amount);
            }

            $receipt = $this->receiptService->syncForPayment($payment);
            $merit->forceFill(['payment_id' => $payment->id])->save();
        }
    }

    public function clearReportPaymentsForEditableReport(RepAssistanceAdv $report): void
    {
        $locked = Payment::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $report->id)
            ->where(function ($query) {
                $query->where('custody_status', self::CUSTODY_REMITTED_PENDING)
                    ->orWhere(function ($received) {
                        $received->where('custody_status', self::CUSTODY_CLUB_RECEIVED)
                            ->whereNotNull('custody_validated_at');
                    });
            })
            ->exists();

        if ($locked) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'report' => 'Este reporte ya tiene cuotas entregadas o validadas. Reversa el movimiento desde correcciones antes de editarlo.',
            ]);
        }

        $payments = Payment::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $report->id)
            ->with('receipt')
            ->get();

        foreach ($payments as $payment) {
            if (($payment->custody_status ?: self::CUSTODY_CLUB_RECEIVED) === self::CUSTODY_CLUB_RECEIVED) {
                $this->clubBudgetAccount((int) $payment->club_id)->decrement('balance', (float) $payment->amount_paid);
            }

            $this->receiptService->deleteForPayment($payment);
            $payment->delete();
        }
    }

    private function shouldCreatePayment(RepAssistanceAdvMerit $merit): bool
    {
        return (bool) $merit->cuota
            && (float) $merit->cuota_amount > 0
            && !empty($merit->member_id);
    }

    private function clubBudgetAccount(int $clubId): Account
    {
        return Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => 'club_budget'],
            ['label' => 'Presupuesto del club', 'balance' => 0]
        );
    }

    private function conceptText(RepAssistanceAdv $report): string
    {
        $date = \Illuminate\Support\Carbon::parse($report->date)->toDateString();
        return "Cuota de asistencia - {$date}";
    }
}
