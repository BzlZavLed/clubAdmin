<?php

namespace App\Http\Controllers;

use App\Jobs\SendParentPaymentSubmissionEmail;
use App\Models\Event;
use App\Models\Account;
use App\Models\BankInfo;
use App\Models\Club;
use App\Models\Member;
use App\Models\ParentPaymentSubmission;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentConcept;
use App\Models\PaymentReceipt;
use App\Support\BankInfoFormatter;
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
            'club_deposit_accounts' => $this->clubDepositAccountsForParent($user)->values()->all(),
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
                'amount' => $charge['transfer_blocked_reason'] ?? 'Ese cargo ya no admite nuevos comprobantes.',
            ]);
        }

        $remainingAmount = (float) ($charge['remaining_amount'] ?? 0);
        $availableAmount = (float) ($charge['available_amount'] ?? $remainingAmount);
        $amount = (float) $validated['amount'];

        if (!$charge['reusable'] && $availableAmount <= 0.0001) {
            return back()->withErrors([
                'amount' => 'Ya hay comprobantes en revision que cubren el saldo pendiente de este cargo.',
            ]);
        }

        if (!$charge['reusable'] && $availableAmount > 0 && $amount > $availableAmount) {
            return back()->withErrors([
                'amount' => 'El comprobante excede el saldo disponible considerando comprobantes en revision.',
            ]);
        }

        $receiptImagePath = $request->file('receipt_image')->store('payments/transfers', 'public');

        $club = Club::withoutGlobalScopes()->find($charge['club_id']);
        $clubReceiptEmail = $club?->club_email;

        $submission = ParentPaymentSubmission::query()->create([
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
            'club_receipt_email' => $clubReceiptEmail,
            'club_receipt_email_status' => $clubReceiptEmail ? 'queued' : 'manual_required',
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        if ($clubReceiptEmail) {
            SendParentPaymentSubmissionEmail::dispatch($submission->id)->afterCommit();
        }

        return redirect()
            ->route('parent.payments.index')
            ->with('success', $clubReceiptEmail
                ? 'Comprobante enviado para validación del club y remitido por correo.'
                : 'Comprobante enviado para validación del club. El club no tiene correo configurado.');
    }

    protected function clubDepositAccountsForParent($user): Collection
    {
        $members = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->with(['club:id,club_name,club_type,evaluation_system,club_email'])
            ->get(['id', 'type', 'id_data', 'club_id', 'parent_id', 'status']);

        if ($members->isEmpty()) {
            return collect();
        }

        $clubIds = $members->pluck('club_id')->filter()->unique()->values();
        $accounts = Account::query()
            ->whereIn('club_id', $clubIds)
            ->where('pay_to', 'club_budget')
            ->get(['club_id', 'pay_to', 'label'])
            ->keyBy(fn (Account $account) => (int) $account->club_id);

        $bankInfos = BankInfo::query()
            ->where('bankable_type', Club::class)
            ->whereIn('bankable_id', $clubIds)
            ->where('is_active', true)
            ->where('pay_to', 'club_budget')
            ->get()
            ->keyBy(fn (BankInfo $bankInfo) => (int) $bankInfo->bankable_id);

        return $members
            ->groupBy('club_id')
            ->map(function (Collection $clubMembers, $clubId) use ($accounts, $bankInfos) {
                $club = $clubMembers->first()?->club;
                $account = $accounts->get((int) $clubId);
                $bankInfo = $bankInfos->get((int) $clubId);
                $bankPayload = BankInfoFormatter::payload($bankInfo);

                if ($bankPayload) {
                    $bankPayload['label'] = $bankPayload['label'] ?: ($account?->label ?: 'Cuenta bancaria del club');
                }

                return [
                    'club_id' => (int) $clubId,
                    'club_name' => $club?->club_name,
                    'club_type' => $club?->club_type,
                    'club_email' => $club?->club_email,
                    'club_type_label' => $this->clubTypeLabel($club?->club_type),
                    'evaluation_system' => $club?->evaluation_system,
                    'account_label' => $bankPayload['label'] ?? $account?->label ?? 'Presupuesto del club',
                    'deposit_account' => $bankPayload,
                    'members' => $clubMembers
                        ->map(fn (Member $member) => ClubHelper::memberDetail($member)['name'] ?? null)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy([
                ['club_type_label', 'asc'],
                ['club_name', 'asc'],
            ])
            ->values();
    }

    protected function clubTypeLabel(?string $clubType): string
    {
        $normalized = strtolower((string) $clubType);

        return match ($normalized) {
            'adventurer', 'adventurers' => 'Aventureros',
            'pathfinder', 'pathfinders' => 'Conquistadores',
            'guide', 'guides' => 'Guias Mayores',
            default => $clubType ? ucwords(str_replace(['_', '-'], ' ', $clubType)) : 'Club',
        };
    }

    protected function expectedPaymentsForParent($user): Collection
    {
        $members = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->with([
                'club:id,club_name,club_email',
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
                'club:id,club_name,club_email',
                'scopes' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->with(['class:id,class_name']);
                },
                'event:id,title,start_at',
                'eventFeeComponent:id,label,is_required',
            ])
            ->orderBy('payment_expected_by')
            ->orderBy('concept')
            ->get(['id', 'club_id', 'concept', 'amount', 'payment_expected_by', 'type', 'pay_to', 'reusable', 'event_id', 'event_fee_component_id']);

        if ($concepts->isEmpty()) {
            return collect();
        }

        $depositBankInfos = BankInfo::query()
            ->where('bankable_type', Club::class)
            ->whereIn('bankable_id', $clubIds)
            ->where('is_active', true)
            ->where('pay_to', 'club_budget')
            ->get()
            ->keyBy(fn (BankInfo $bankInfo) => (int) $bankInfo->bankable_id);

        $eventsById = Event::query()
            ->whereIn('id', $concepts->pluck('event_id')->filter()->unique())
            ->with([
                'participants' => fn ($query) => $query
                    ->whereIn('member_id', $memberIds)
                    ->select('id', 'event_id', 'member_id', 'status'),
            ])
            ->get(['id', 'club_id', 'title', 'start_at'])
            ->keyBy('id');

        $directPaymentTotals = Payment::query()
            ->whereIn('member_id', $memberIds)
            ->whereIn('payment_concept_id', $concepts->pluck('id'))
            ->whereDoesntHave('allocations')
            ->selectRaw('payment_concept_id, member_id, COALESCE(SUM(amount_paid), 0) as total_paid')
            ->groupBy('payment_concept_id', 'member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [sprintf('%d|%d', $row->payment_concept_id, $row->member_id) => (float) $row->total_paid]);

        $allocatedPaymentTotals = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payments.deleted_at')
            ->whereIn('payments.member_id', $memberIds)
            ->whereIn('payment_allocations.payment_concept_id', $concepts->pluck('id'))
            ->selectRaw('payment_allocations.payment_concept_id, payments.member_id, COALESCE(SUM(payment_allocations.amount), 0) as total_paid')
            ->groupBy('payment_allocations.payment_concept_id', 'payments.member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [sprintf('%d|%d', $row->payment_concept_id, $row->member_id) => (float) $row->total_paid]);

        $paymentTotals = collect();
        foreach ($directPaymentTotals as $key => $amount) {
            $paymentTotals[$key] = round((float) ($paymentTotals[$key] ?? 0) + (float) $amount, 2);
        }
        foreach ($allocatedPaymentTotals as $key => $amount) {
            $paymentTotals[$key] = round((float) ($paymentTotals[$key] ?? 0) + (float) $amount, 2);
        }
        $receiptLinksByCharge = $this->receiptLinksByConceptAndMember($memberIds, $concepts->pluck('id'));

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
                $availableAmount = $concept->reusable
                    ? $expectedAmount
                    : max($remainingAmount - $pendingAmount, 0.0);
                $receiptLinks = $receiptLinksByCharge->get($key, []);

                $isRequired = (bool) ($concept->eventFeeComponent?->is_required ?? true);
                $status = $isRequired ? 'due' : 'optional';
                if (!$concept->reusable && $remainingAmount <= 0.0001) {
                    $status = 'paid';
                } elseif ($pendingAmount > 0.0001) {
                    $status = 'pending_review';
                }

                $depositAccount = BankInfoFormatter::payload($depositBankInfos->get((int) $concept->club_id));
                if ($depositAccount) {
                    $depositAccount['label'] = $depositAccount['label'] ?: 'Cuenta bancaria del club';
                }

                $rows->push([
                    'row_key' => $key,
                    'club_id' => (int) $concept->club_id,
                    'club_name' => $member->club?->club_name ?: $concept->club?->club_name,
                    'club_email' => $member->club?->club_email ?: $concept->club?->club_email,
                    'member_id' => (int) $member->id,
                    'member_name' => $memberDetail['name'] ?? '—',
                    'member_type' => $member->type,
                    'class_id' => $member->class_id,
                    'class_name' => $member->class?->class_name,
                    'concept_id' => (int) $concept->id,
                    'concept_name' => $concept->concept,
                    'concept_type' => $concept->type,
                    'is_required' => $isRequired,
                    'pay_to' => $concept->pay_to,
                    'deposit_account_label' => $depositAccount['label'] ?? 'Cuenta bancaria del club',
                    'deposit_account' => $depositAccount,
                    'expected_amount' => $expectedAmount,
                    'paid_amount' => $paidAmount,
                    'pending_amount' => $pendingAmount,
                    'remaining_amount' => $remainingAmount,
                    'available_amount' => $availableAmount,
                    'receipt_links' => $receiptLinks,
                    'primary_receipt_number' => $receiptLinks[0]['receipt_number'] ?? null,
                    'primary_receipt_url' => $receiptLinks[0]['download_url'] ?? null,
                    'reusable' => (bool) $concept->reusable,
                    'due_date' => optional($concept->payment_expected_by)->toDateString(),
                    'scope_label' => $scopeLabels[$member->id] ?? 'Cargo aplicable',
                    'status' => $status,
                    'event_id' => $event?->id,
                    'event_title' => $event?->title,
                    'event_component_label' => $concept->eventFeeComponent?->label,
                    'event_start_at' => $event?->start_at?->toDateTimeString(),
                    'can_submit_transfer' => (bool) $depositAccount && ($concept->reusable || $availableAmount > 0.0001),
                    'transfer_blocked_reason' => !$depositAccount
                        ? 'El club todavia no ha publicado datos bancarios para recibir transferencias.'
                        : ((!$concept->reusable && $remainingAmount <= 0.0001)
                            ? 'Este cargo ya esta cubierto.'
                            : ((!$concept->reusable && $availableAmount <= 0.0001)
                                ? 'Ya hay comprobantes en revision que cubren el saldo pendiente.'
                                : null)),
                ]);
            }
        }

        $requiredStatusByEventMember = $rows
            ->filter(fn (array $row) => $row['event_id'] && $row['is_required'])
            ->groupBy(fn (array $row) => sprintf('%d|%d', $row['event_id'], $row['member_id']))
            ->map(fn (Collection $rowsForEventMember) => $rowsForEventMember->contains(
                fn (array $row) => (float) ($row['remaining_amount'] ?? 0) > 0.0001
            ));

        return $rows
            ->map(function (array $row) use ($requiredStatusByEventMember) {
                if ($row['event_id'] && !$row['is_required']) {
                    $requiredKey = sprintf('%d|%d', $row['event_id'], $row['member_id']);
                    if ((bool) ($requiredStatusByEventMember[$requiredKey] ?? false)) {
                        $row['can_submit_transfer'] = false;
                        $row['transfer_blocked_reason'] = 'Primero debe pagarse y aprobarse el concepto obligatorio del evento.';
                    }
                }

                return $row;
            })
            ->sortBy([
                ['status', 'asc'],
                ['due_date', 'asc'],
                ['club_name', 'asc'],
                ['member_name', 'asc'],
                ['concept_name', 'asc'],
            ])
            ->values();
    }

    protected function receiptLinksByConceptAndMember(Collection $memberIds, Collection $conceptIds): Collection
    {
        $memberIdValues = $memberIds
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $conceptIdValues = $conceptIds
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($memberIdValues->isEmpty() || $conceptIdValues->isEmpty()) {
            return collect();
        }

        $conceptIdLookup = array_flip($conceptIdValues->all());
        $linksByCharge = [];

        Payment::query()
            ->whereIn('member_id', $memberIdValues)
            ->where(function ($query) use ($conceptIdValues) {
                $query->whereIn('payment_concept_id', $conceptIdValues)
                    ->orWhereHas('allocations', fn ($allocationQuery) => $allocationQuery->whereIn('payment_concept_id', $conceptIdValues));
            })
            ->whereHas('receipt')
            ->with([
                'receipt:id,payment_id,receipt_number,issued_at',
                'allocations' => fn ($query) => $query
                    ->whereIn('payment_concept_id', $conceptIdValues)
                    ->select('id', 'payment_id', 'payment_concept_id', 'amount'),
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get(['id', 'member_id', 'payment_concept_id', 'amount_paid', 'payment_date'])
            ->each(function (Payment $payment) use (&$linksByCharge, $conceptIdLookup) {
                if (!$payment->receipt || !$payment->member_id) {
                    return;
                }

                $receiptPayload = [
                    'id' => (int) $payment->receipt->id,
                    'payment_id' => (int) $payment->id,
                    'receipt_number' => $payment->receipt->receipt_number,
                    'issued_at' => optional($payment->receipt->issued_at)->toDateString(),
                    'payment_date' => optional($payment->payment_date)->toDateString(),
                    'download_url' => route('payment-receipts.download', $payment->receipt),
                ];

                if ($payment->allocations->isNotEmpty()) {
                    foreach ($payment->allocations as $allocation) {
                        $key = sprintf('%d|%d', (int) $allocation->payment_concept_id, (int) $payment->member_id);
                        $linksByCharge[$key][] = [
                            ...$receiptPayload,
                            'amount' => (float) $allocation->amount,
                        ];
                    }

                    return;
                }

                if (!isset($conceptIdLookup[(int) $payment->payment_concept_id])) {
                    return;
                }

                $key = sprintf('%d|%d', (int) $payment->payment_concept_id, (int) $payment->member_id);
                $linksByCharge[$key][] = [
                    ...$receiptPayload,
                    'amount' => (float) $payment->amount_paid,
                ];
            });

        return collect($linksByCharge)
            ->map(fn (array $links) => collect($links)->unique('id')->values()->all());
    }

    protected function transferSubmissionsForParent($user): Collection
    {
        return ParentPaymentSubmission::query()
            ->where('parent_user_id', $user->id)
            ->with([
                'club:id,club_name,club_email',
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
                    'club_email' => $submission->club_receipt_email ?: $submission->club?->club_email,
                    'club_receipt_email_status' => $submission->club_receipt_email_status,
                    'club_receipt_emailed_at' => optional($submission->club_receipt_emailed_at)->toDateTimeString(),
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
        $memberIds = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->pluck('id');

        return PaymentReceipt::query()
            ->where(function ($query) use ($user, $memberIds) {
                $query->where('parent_user_id', $user->id)
                    ->orWhereIn('member_id', $memberIds);
            })
            ->with([
                'club:id,club_name',
                'payment:id,club_id,member_id,amount_paid,payment_date,payment_type,payment_concept_id,concept_text',
                'payment.member:id,type,id_data,parent_id',
                'payment.concept:id,concept',
                'payment.allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
                'payment.allocations.concept:id,concept,event_id,event_fee_component_id',
                'payment.allocations.concept.event:id,title,start_at',
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
                    'concept_name' => $payment?->allocations?->first()?->concept?->event?->title ?? $payment?->concept?->concept ?? $payment?->concept_text,
                    'amount_paid' => (float) ($payment?->amount_paid ?? 0),
                    'payment_date' => optional($payment?->payment_date)->toDateString(),
                    'payment_type' => $payment?->payment_type,
                    'download_url' => route('payment-receipts.download', $receipt),
                ];
            });
    }
}
