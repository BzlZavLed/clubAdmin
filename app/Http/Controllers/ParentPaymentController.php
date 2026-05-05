<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Member;
use App\Models\ParentPaymentSubmission;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\PaymentReceipt;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ParentPaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Parent/Payments', [
            'auth_user' => $user,
            'expected_payments' => $this->expectedPaymentsForParent($user)->values()->all(),
            'transfer_submissions' => $this->transferSubmissionsForParent($user)->values()->all(),
            'receipts' => $this->receiptsForParent($user)->values()->all(),
        ]);
    }

    public function storeTransfer(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'payment_concept_id' => ['required', 'integer', 'exists:payment_concepts,id'],
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'receipt_image' => ['required', 'image', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $charge = $this->expectedPaymentsForParent($user)
            ->first(fn (array $row) => (int) $row['concept_id'] === (int) $validated['payment_concept_id'] && (int) $row['member_id'] === (int) $validated['member_id']);

        abort_if(!$charge, 403, 'Ese concepto no aplica al menor seleccionado.');

        if (!$charge['can_submit_transfer']) {
            return back()->withErrors([
                'amount' => 'Ese cargo ya no admite nuevos comprobantes.',
            ]);
        }

        $remainingAmount = (float) ($charge['remaining_amount'] ?? 0);
        $amount = (float) $validated['amount'];

        if (!$charge['reusable'] && $remainingAmount > 0 && $amount > $remainingAmount) {
            return back()->withErrors([
                'amount' => 'El comprobante excede el saldo pendiente de este cargo.',
            ]);
        }

        $receiptImagePath = $request->file('receipt_image')->store('payments/transfers', 'public');

        ParentPaymentSubmission::query()->create([
            'club_id' => $charge['club_id'],
            'payment_concept_id' => $charge['concept_id'],
            'member_id' => $charge['member_id'],
            'parent_user_id' => $user->id,
            'event_id' => $charge['event_id'],
            'concept_text' => $charge['concept_name'],
            'pay_to' => $charge['pay_to'],
            'expected_amount' => $charge['expected_amount'],
            'amount' => $amount,
            'payment_date' => $validated['payment_date'],
            'payment_type' => 'transfer',
            'reference' => $validated['reference'] ?? null,
            'receipt_image_path' => $receiptImagePath,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()
            ->route('parent.payments.index')
            ->with('success', 'Comprobante enviado para validación del club.');
    }

    protected function expectedPaymentsForParent($user): Collection
    {
        $members = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->with([
                'club:id,club_name',
                'class:id,class_name',
            ])
            ->get(['id', 'type', 'id_data', 'club_id', 'class_id', 'parent_id', 'status']);

        if ($members->isEmpty()) {
            return collect();
        }

        $clubIds = $members->pluck('club_id')->filter()->unique()->values();
        $memberIds = $members->pluck('id')->filter()->unique()->values();
        $classIds = $members->pluck('class_id')->filter()->unique()->values();

        $concepts = PaymentConcept::query()
            ->whereIn('club_id', $clubIds)
            ->where('status', 'active')
            ->with([
                'club:id,club_name',
                'scopes' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->with(['class:id,class_name']);
                },
                'event:id,title,start_at',
                'eventFeeComponent:id,label',
            ])
            ->orderBy('payment_expected_by')
            ->orderBy('concept')
            ->get(['id', 'club_id', 'concept', 'amount', 'payment_expected_by', 'type', 'pay_to', 'reusable', 'event_id', 'event_fee_component_id']);

        if ($concepts->isEmpty()) {
            return collect();
        }

        $eventsById = Event::query()
            ->whereIn('id', $concepts->pluck('event_id')->filter()->unique())
            ->with([
                'participants' => fn ($query) => $query
                    ->whereIn('member_id', $memberIds)
                    ->select('id', 'event_id', 'member_id', 'status'),
            ])
            ->get(['id', 'club_id', 'title', 'start_at'])
            ->keyBy('id');

        $paymentTotals = Payment::query()
            ->whereIn('member_id', $memberIds)
            ->whereIn('payment_concept_id', $concepts->pluck('id'))
            ->selectRaw('payment_concept_id, member_id, COALESCE(SUM(amount_paid), 0) as total_paid')
            ->groupBy('payment_concept_id', 'member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [sprintf('%d|%d', $row->payment_concept_id, $row->member_id) => (float) $row->total_paid]);

        $pendingTotals = ParentPaymentSubmission::query()
            ->where('parent_user_id', $user->id)
            ->where('status', 'pending')
            ->whereIn('member_id', $memberIds)
            ->whereIn('payment_concept_id', $concepts->pluck('id'))
            ->selectRaw('payment_concept_id, member_id, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('payment_concept_id', 'member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [sprintf('%d|%d', $row->payment_concept_id, $row->member_id) => (float) $row->total_amount]);

        $rows = collect();

        foreach ($concepts as $concept) {
            $event = $concept->event_id ? $eventsById->get((int) $concept->event_id) : null;
            $matchedMembers = collect();
            $scopeLabels = [];

            if ($event) {
                $participantMemberIds = $event->participants
                    ->pluck('member_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                $matchedMembers = $members->whereIn('id', $participantMemberIds);
                foreach ($matchedMembers as $member) {
                    $scopeLabels[$member->id] = 'Participante en evento';
                }
            } else {
                foreach ($concept->scopes as $scope) {
                    if ($scope->scope_type === 'staff_wide' || $scope->scope_type === 'staff') {
                        continue;
                    }

                    $scopeMatched = match ($scope->scope_type) {
                        'club_wide' => $members->where('club_id', (int) ($scope->club_id ?: $concept->club_id)),
                        'class' => $members->where('class_id', (int) $scope->class_id),
                        'member' => $members->where('id', (int) $scope->member_id),
                        default => collect(),
                    };

                    foreach ($scopeMatched as $member) {
                        $matchedMembers->push($member);
                        $scopeLabels[$member->id] = match ($scope->scope_type) {
                            'club_wide' => 'Todo el club',
                            'class' => 'Clase: ' . ($scope->class?->class_name ?: $member->class?->class_name ?: '—'),
                            'member' => 'Cargo individual',
                            default => 'Alcance',
                        };
                    }
                }
            }

            $matchedMembers = $matchedMembers->unique('id')->values();
            if ($matchedMembers->isEmpty()) {
                continue;
            }

            foreach ($matchedMembers as $member) {
                $memberDetail = ClubHelper::memberDetail($member);
                $key = sprintf('%d|%d', $concept->id, $member->id);
                $paidAmount = (float) ($paymentTotals[$key] ?? 0.0);
                $pendingAmount = (float) ($pendingTotals[$key] ?? 0.0);
                $expectedAmount = (float) ($concept->amount ?? 0.0);
                $remainingAmount = $concept->reusable
                    ? 0.0
                    : max($expectedAmount - $paidAmount, 0.0);

                $status = 'due';
                if (!$concept->reusable && $remainingAmount <= 0.0001) {
                    $status = 'paid';
                } elseif ($pendingAmount > 0.0001) {
                    $status = 'pending_review';
                }

                $rows->push([
                    'row_key' => $key,
                    'club_id' => (int) $concept->club_id,
                    'club_name' => $member->club?->club_name ?: $concept->club?->club_name,
                    'member_id' => (int) $member->id,
                    'member_name' => $memberDetail['name'] ?? '—',
                    'member_type' => $member->type,
                    'class_id' => $member->class_id,
                    'class_name' => $member->class?->class_name,
                    'concept_id' => (int) $concept->id,
                    'concept_name' => $concept->concept,
                    'concept_type' => $concept->type,
                    'pay_to' => $concept->pay_to,
                    'expected_amount' => $expectedAmount,
                    'paid_amount' => $paidAmount,
                    'pending_amount' => $pendingAmount,
                    'remaining_amount' => $remainingAmount,
                    'reusable' => (bool) $concept->reusable,
                    'due_date' => optional($concept->payment_expected_by)->toDateString(),
                    'scope_label' => $scopeLabels[$member->id] ?? 'Cargo aplicable',
                    'status' => $status,
                    'event_id' => $event?->id,
                    'event_title' => $event?->title,
                    'event_component_label' => $concept->eventFeeComponent?->label,
                    'event_start_at' => $event?->start_at?->toDateTimeString(),
                    'can_submit_transfer' => $concept->reusable || $remainingAmount > 0.0001,
                ]);
            }
        }

        return $rows
            ->sortBy([
                ['status', 'asc'],
                ['due_date', 'asc'],
                ['club_name', 'asc'],
                ['member_name', 'asc'],
                ['concept_name', 'asc'],
            ])
            ->values();
    }

    protected function transferSubmissionsForParent($user): Collection
    {
        return ParentPaymentSubmission::query()
            ->where('parent_user_id', $user->id)
            ->with([
                'club:id,club_name',
                'member:id,type,id_data',
                'event:id,title,start_at',
                'reviewedBy:id,name',
                'approvedPayment.receipt:id,payment_id,receipt_number',
            ])
            ->latest()
            ->get()
            ->map(function (ParentPaymentSubmission $submission) {
                $memberDetail = ClubHelper::memberDetail($submission->member);

                return [
                    'id' => $submission->id,
                    'club_name' => $submission->club?->club_name,
                    'member_name' => $memberDetail['name'] ?? '—',
                    'concept_name' => $submission->concept_text,
                    'event_title' => $submission->event?->title,
                    'amount' => (float) $submission->amount,
                    'expected_amount' => $submission->expected_amount !== null ? (float) $submission->expected_amount : null,
                    'payment_date' => optional($submission->payment_date)->toDateString(),
                    'status' => $submission->status,
                    'reference' => $submission->reference,
                    'notes' => $submission->notes,
                    'review_notes' => $submission->review_notes,
                    'reviewed_at' => optional($submission->reviewed_at)->toDateTimeString(),
                    'reviewed_by' => $submission->reviewedBy?->name,
                    'receipt_image_url' => $submission->receipt_image_path ? asset('storage/' . $submission->receipt_image_path) : null,
                    'approved_receipt_number' => $submission->approvedPayment?->receipt?->receipt_number,
                    'approved_receipt_url' => $submission->approvedPayment?->receipt
                        ? route('payment-receipts.download', $submission->approvedPayment->receipt)
                        : null,
                ];
            });
    }

    protected function receiptsForParent($user): Collection
    {
        return PaymentReceipt::query()
            ->where('parent_user_id', $user->id)
            ->with([
                'club:id,club_name',
                'payment:id,club_id,member_id,amount_paid,payment_date,payment_type,payment_concept_id,concept_text',
                'payment.member:id,type,id_data,parent_id',
                'payment.concept:id,concept',
            ])
            ->latest('issued_at')
            ->get()
            ->map(function (PaymentReceipt $receipt) {
                $payment = $receipt->payment;
                $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;

                return [
                    'id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'issued_at' => optional($receipt->issued_at)->toDateString(),
                    'club_name' => $receipt->club?->club_name,
                    'member_name' => $memberDetail['name'] ?? '—',
                    'concept_name' => $payment?->concept?->concept ?? $payment?->concept_text,
                    'amount_paid' => (float) ($payment?->amount_paid ?? 0),
                    'payment_date' => optional($payment?->payment_date)->toDateString(),
                    'payment_type' => $payment?->payment_type,
                    'download_url' => route('payment-receipts.download', $receipt),
                ];
            });
    }
}
