<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\AttendanceDuesPaymentService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StaffPaymentCustodyController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('ClubPersonal/MoneyCustody', [
            'auth_user' => $request->user(),
        ]);
    }

    public function data(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));
        $held = $this->heldPaymentsQuery($request, (int) $club->id)
            ->with($this->paymentRelations())
            ->latest('payment_date')
            ->latest('id')
            ->get();

        $pending = Payment::query()
            ->where('club_id', $club->id)
            ->where('held_by_user_id', $request->user()->id)
            ->where('custody_status', AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING)
            ->with($this->paymentRelations())
            ->latest('remitted_at')
            ->latest('id')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->remittance_batch_id ?: "single-{$payment->id}")
            ->map(fn ($payments, $batchId) => $this->remittanceGroupRow($batchId, $payments))
            ->values();

        return response()->json([
            'club' => [
                'id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'held_total' => round((float) $held->sum('amount_paid'), 2),
            'held_payments' => $held->map(fn (Payment $payment) => $this->paymentRow($payment))->values(),
            'pending_remittances' => $pending,
        ]);
    }

    public function remit(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'payment_ids' => ['nullable', 'array'],
            'payment_ids.*' => ['integer', 'exists:payments,id'],
            'remittance_method' => ['required', Rule::in(['cash', 'zelle', 'transfer'])],
            'remittance_date' => ['required', 'date'],
            'remittance_reference' => ['nullable', 'string', 'max:160'],
            'remittance_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);
        $batchId = (string) Str::uuid();

        $payments = DB::transaction(function () use ($request, $club, $validated, $batchId) {
            $query = $this->heldPaymentsQuery($request, (int) $club->id)->lockForUpdate();

            if (!empty($validated['payment_ids'])) {
                $query->whereIn('id', collect($validated['payment_ids'])->map(fn ($id) => (int) $id)->all());
            }

            $payments = $query->get();
            if ($payments->isEmpty()) {
                abort(422, 'No hay cuotas en custodia para entregar.');
            }

            $remittedAt = Carbon::parse($validated['remittance_date']);
            foreach ($payments as $payment) {
                $payment->forceFill([
                    'custody_status' => AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING,
                    'remittance_batch_id' => $batchId,
                    'remittance_method' => $validated['remittance_method'],
                    'remittance_reference' => $validated['remittance_reference'] ?? null,
                    'remittance_notes' => $validated['remittance_notes'] ?? null,
                    'remitted_at' => $remittedAt,
                ])->save();
            }

            return $payments;
        });

        return response()->json([
            'message' => 'Entrega marcada como pendiente de validación.',
            'remittance_batch_id' => $batchId,
            'count' => $payments->count(),
            'amount' => round((float) $payments->sum('amount_paid'), 2),
        ]);
    }

    private function heldPaymentsQuery(Request $request, int $clubId)
    {
        return Payment::query()
            ->where('club_id', $clubId)
            ->where('held_by_user_id', $request->user()->id)
            ->where('custody_status', AttendanceDuesPaymentService::CUSTODY_HELD_BY_STAFF);
    }

    private function paymentRelations(): array
    {
        return [
            'member:id,type,id_data,parent_id',
            'receipt:id,payment_id,receipt_number',
            'account:id,club_id,pay_to,label',
        ];
    }

    private function remittanceGroupRow(string $batchId, $payments): array
    {
        $first = $payments->first();

        return [
            'batch_id' => $batchId,
            'amount' => round((float) $payments->sum('amount_paid'), 2),
            'count' => $payments->count(),
            'remittance_method' => $first?->remittance_method,
            'remittance_reference' => $first?->remittance_reference,
            'remittance_notes' => $first?->remittance_notes,
            'remitted_at' => optional($first?->remitted_at)->toDateTimeString(),
            'payments' => $payments->map(fn (Payment $payment) => $this->paymentRow($payment))->values(),
        ];
    }

    private function paymentRow(Payment $payment): array
    {
        $member = ClubHelper::memberDetail($payment->member);

        return [
            'id' => (int) $payment->id,
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'amount_paid' => (float) $payment->amount_paid,
            'concept_name' => $payment->concept_text,
            'payer_name' => $member['name'] ?? '—',
            'account_label' => $payment->account?->label ?: $payment->pay_to,
            'custody_status' => $payment->custody_status,
            'receipt_number' => $payment->receipt?->receipt_number,
            'receipt_url' => $payment->receipt ? route('payment-receipts.download', $payment->receipt) : null,
        ];
    }
}
