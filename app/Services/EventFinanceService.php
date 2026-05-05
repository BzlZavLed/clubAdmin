<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\EventFeeComponent;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Support\ClubHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventFinanceService
{
    public function syncFeeComponents(Event $event, array $components): Collection
    {
        $normalized = collect($components)
            ->map(function (array $component, int $index) {
                $label = trim((string) ($component['label'] ?? ''));
                $amount = (float) ($component['amount'] ?? 0);

                return [
                    'label' => $label,
                    'amount' => round($amount, 2),
                    'sort_order' => (int) ($component['sort_order'] ?? $index + 1),
                ];
            })
            ->filter(fn (array $component) => $component['label'] !== '' && $component['amount'] > 0)
            ->values();

        DB::transaction(function () use ($event, $normalized) {
            $keepIds = [];

            foreach ($normalized as $index => $component) {
                $current = $event->feeComponents()->orderBy('sort_order')->get()->get($index);

                if ($current) {
                    $current->update($component);
                    $keepIds[] = $current->id;
                    continue;
                }

                $created = $event->feeComponents()->create($component);
                $keepIds[] = $created->id;
            }

            $event->feeComponents()
                ->when(!empty($keepIds), fn ($query) => $query->whereNotIn('id', $keepIds))
                ->when(empty($keepIds), fn ($query) => $query)
                ->delete();
        });

        return $event->fresh()->feeComponents()->orderBy('sort_order')->get();
    }

    public function syncPaymentConcepts(Event $event, int $userId): Collection
    {
        $event->loadMissing([
            'feeComponents',
            'targetClubs:id,clubs.id,club_name,church_name,district_id',
            'participants.member:id,club_id',
        ]);

        $components = $event->feeComponents->sortBy('sort_order')->values();
        $participantClubMap = $event->participants
            ->filter(fn ($participant) => !empty($participant->member_id) && !empty($participant->member?->club_id))
            ->groupBy(fn ($participant) => (int) $participant->member->club_id)
            ->map(fn (Collection $participants) => $participants->pluck('member_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $targetClubIds = $event->targetClubs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (($event->scope_type ?: 'club') === 'club' && !in_array((int) $event->club_id, $targetClubIds, true)) {
            $targetClubIds[] = (int) $event->club_id;
        }

        $totalAmount = round((float) $components->sum(fn (EventFeeComponent $component) => (float) $component->amount), 2);
        $keepConceptIds = [];

        DB::transaction(function () use ($event, $components, $participantClubMap, $targetClubIds, $totalAmount, $userId, &$keepConceptIds) {
            foreach ($components as $component) {
                foreach ($targetClubIds as $clubId) {
                    $memberIds = collect($participantClubMap->get($clubId, []))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique()
                        ->values();

                    $concept = PaymentConcept::withTrashed()->firstOrNew([
                        'event_id' => $event->id,
                        'event_fee_component_id' => $component->id,
                        'club_id' => $clubId,
                    ]);

                    if ($concept->exists && $concept->trashed()) {
                        $concept->restore();
                    }

                    $concept->fill([
                        'concept' => $this->componentConceptLabel($event, $component),
                        'amount' => $component->amount,
                        'type' => 'mandatory',
                        'pay_to' => 'club_budget',
                        'created_by' => $concept->created_by ?: $userId,
                        'status' => $memberIds->isNotEmpty() ? 'active' : 'inactive',
                        'reusable' => false,
                    ]);
                    $concept->save();

                    $concept->scopes()->delete();

                    foreach ($memberIds as $memberId) {
                        PaymentConceptScope::create([
                            'payment_concept_id' => $concept->id,
                            'scope_type' => 'member',
                            'club_id' => $clubId,
                            'member_id' => $memberId,
                        ]);
                    }

                    if ($memberIds->isEmpty()) {
                        $concept->update(['status' => 'inactive']);
                    }

                    $keepConceptIds[] = $concept->id;
                }
            }

            $event->update([
                'is_payable' => $components->isNotEmpty(),
                'payment_amount' => $components->isNotEmpty() ? $totalAmount : null,
            ]);

            $activeConceptIds = collect($keepConceptIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

            $event->update([
                'payment_concept_id' => $activeConceptIds->first(),
            ]);

            PaymentConcept::query()
                ->where('event_id', $event->id)
                ->when($activeConceptIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $activeConceptIds->all()))
                ->get()
                ->each(function (PaymentConcept $concept) {
                    $concept->scopes()->delete();
                    $concept->update(['status' => 'inactive']);
                    $concept->delete();
                });

            if ($activeConceptIds->isEmpty()) {
                $event->update([
                    'payment_concept_id' => null,
                    'payment_amount' => null,
                    'is_payable' => false,
                ]);
            }
        });

        return PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('status', 'active')
            ->with(['club:id,club_name', 'eventFeeComponent:id,label,amount,sort_order'])
            ->orderBy('club_id')
            ->orderBy('concept')
            ->get();
    }

    public function paymentSummary(Event $event, array $visibleClubIds = []): array
    {
        $concepts = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->when(!empty($visibleClubIds), fn ($query) => $query->whereIn('club_id', $visibleClubIds))
            ->where('status', 'active')
            ->with(['club:id,club_name', 'eventFeeComponent:id,label,amount,sort_order'])
            ->get(['id', 'club_id', 'event_fee_component_id', 'concept', 'amount', 'event_id']);

        if ($concepts->isEmpty()) {
            return [
                'total_received' => 0.0,
                'by_member_id' => [],
                'by_staff_id' => [],
                'concepts' => [],
                'records' => [],
            ];
        }

        $conceptIds = $concepts->pluck('id');
        $payments = Payment::query()
            ->whereIn('payment_concept_id', $conceptIds)
            ->with(['member', 'staff.user:id,name', 'receivedBy:id,name', 'concept.eventFeeComponent:id,label,amount,sort_order'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $byMember = $payments
            ->whereNotNull('member_id')
            ->groupBy('member_id')
            ->map(fn (Collection $rows) => (float) $rows->sum('amount_paid'))
            ->all();

        $byStaff = $payments
            ->whereNotNull('staff_id')
            ->groupBy('staff_id')
            ->map(fn (Collection $rows) => (float) $rows->sum('amount_paid'))
            ->all();

        return [
            'total_received' => (float) $payments->sum('amount_paid'),
            'by_member_id' => $byMember,
            'by_staff_id' => $byStaff,
            'concepts' => $concepts->map(function (PaymentConcept $concept) {
                return [
                    'id' => $concept->id,
                    'club_id' => (int) $concept->club_id,
                    'club_name' => $concept->club?->club_name,
                    'label' => $concept->concept,
                    'amount' => (float) $concept->amount,
                    'component_label' => $concept->eventFeeComponent?->label,
                    'sort_order' => (int) ($concept->eventFeeComponent?->sort_order ?? 0),
                ];
            })->values()->all(),
            'records' => $payments->map(function (Payment $payment) {
                return [
                    'id' => $payment->id,
                    'payment_date' => optional($payment->payment_date)->format('Y-m-d'),
                    'amount_paid' => (float) $payment->amount_paid,
                    'payment_type' => $payment->payment_type,
                    'payer_type' => $payment->member_id ? 'member' : ($payment->staff_id ? 'staff' : 'other'),
                    'payer_name' => $payment->member_id
                        ? (ClubHelper::memberDetail($payment->member)['name'] ?? 'Unknown')
                        : (ClubHelper::staffDetail($payment->staff)['name'] ?? $payment->staff?->user?->name ?? 'Unknown'),
                    'notes' => $payment->notes,
                    'received_by' => $payment->receivedBy?->name,
                    'concept_label' => $payment->concept?->eventFeeComponent?->label ?: $payment->concept_text ?: $payment->concept?->concept,
                    'club_id' => (int) $payment->club_id,
                ];
            })->values()->all(),
        ];
    }

    public function clubSignupSummary(Event $event): array
    {
        $event->loadMissing([
            'targetClubs' => fn ($query) => $query->with('district:id,name,association_id'),
            'participants.member:id,club_id',
            'feeComponents',
        ]);

        $targetClubs = $event->targetClubs;
        $clubIds = $targetClubs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $components = $event->feeComponents->sortBy('sort_order')->values();
        $activeMemberCounts = collect();
        if (!empty($clubIds)) {
            $activeMemberCounts = Member::query()
                ->join('clubs', 'clubs.id', '=', 'members.club_id')
                ->whereIn('members.club_id', $clubIds)
                ->where('members.status', 'active')
                ->where(function ($query) {
                    $query
                        ->where(function ($inner) {
                            $inner
                                ->where('clubs.club_type', 'adventurers')
                                ->where('members.type', 'adventurers');
                        })
                        ->orWhere(function ($inner) {
                            $inner
                                ->whereIn('clubs.club_type', ['pathfinders', 'master_guide'])
                                ->whereIn('members.type', ['temp_pathfinder', 'pathfinders']);
                        })
                        ->orWhere(function ($inner) {
                            $inner
                                ->whereNotIn('clubs.club_type', ['adventurers', 'pathfinders', 'master_guide']);
                        });
                })
                ->selectRaw('members.club_id, COUNT(*) as total_members')
                ->groupBy('members.club_id')
                ->pluck('total_members', 'members.club_id')
                ->map(fn ($count) => (int) $count);
        }

        $concepts = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->whereIn('club_id', $clubIds)
            ->get(['id', 'club_id', 'event_fee_component_id']);

        $settlementsByClub = EventClubSettlement::query()
            ->where('event_id', $event->id)
            ->whereIn('club_id', $clubIds)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('club_id');

        $conceptIds = $concepts->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        $paidByClubComponent = collect();
        if ($conceptIds->isNotEmpty()) {
            $paidByClubComponent = Payment::query()
                ->join('payment_concepts', 'payment_concepts.id', '=', 'payments.payment_concept_id')
                ->whereIn('payments.payment_concept_id', $conceptIds->all())
                ->selectRaw('payments.club_id, payment_concepts.event_fee_component_id, COALESCE(SUM(payments.amount_paid), 0) as total_paid')
                ->groupBy('payments.club_id', 'payment_concepts.event_fee_component_id')
                ->get()
                ->groupBy(fn ($row) => (int) $row->club_id)
                ->map(function (Collection $rows) {
                    return $rows->mapWithKeys(function ($row) {
                        return [(int) $row->event_fee_component_id => round((float) $row->total_paid, 2)];
                    });
                });
        }

        $totalPerMember = (float) $components->sum(fn (EventFeeComponent $component) => (float) $component->amount);

        return $targetClubs
            ->map(function (Club $club) use ($event, $activeMemberCounts, $paidByClubComponent, $totalPerMember, $settlementsByClub, $components) {
                $memberCount = (int) ($activeMemberCounts[(int) $club->id] ?? 0);
                $expected = round($memberCount * $totalPerMember, 2);
                $settlements = collect($settlementsByClub->get((int) $club->id, collect()));

                $depositedByComponent = [];
                foreach ($settlements as $settlement) {
                    foreach (($settlement->breakdown_json ?? []) as $row) {
                        $labelKey = 'label:' . mb_strtolower(trim((string) ($row['label'] ?? '')));
                        $componentIdKey = !empty($row['component_id']) ? 'id:' . (int) $row['component_id'] : null;
                        $amount = round((float) ($row['amount'] ?? 0), 2);

                        if ($componentIdKey) {
                            $depositedByComponent[$componentIdKey] = round(($depositedByComponent[$componentIdKey] ?? 0) + $amount, 2);
                        }
                        if ($labelKey !== 'label:') {
                            $depositedByComponent[$labelKey] = round(($depositedByComponent[$labelKey] ?? 0) + $amount, 2);
                        }
                    }
                }

                $clubPaidByComponent = collect($paidByClubComponent->get((int) $club->id, collect()));
                $expectedBreakdown = $components
                    ->map(fn (EventFeeComponent $component) => [
                        'component_id' => (int) $component->id,
                        'label' => $component->label,
                        'amount' => round($memberCount * (float) $component->amount, 2),
                        'per_member_amount' => (float) $component->amount,
                        'participant_count' => $memberCount,
                    ])
                    ->filter(fn (array $row) => $row['amount'] > 0)
                    ->values();

                $pendingSettlementBreakdown = $expectedBreakdown
                    ->map(function (array $row) use ($clubPaidByComponent, $depositedByComponent) {
                        $componentIdKey = 'id:' . (int) $row['component_id'];
                        $labelKey = 'label:' . mb_strtolower(trim((string) $row['label']));
                        $collected = round((float) ($clubPaidByComponent->get((int) $row['component_id'], 0)), 2);
                        $eligibleForDeposit = min($collected, round((float) $row['amount'], 2));
                        $alreadyDeposited = round((float) ($depositedByComponent[$componentIdKey] ?? $depositedByComponent[$labelKey] ?? 0), 2);
                        $pending = round(max($eligibleForDeposit - $alreadyDeposited, 0), 2);

                        return [
                            ...$row,
                            'collected_amount' => $collected,
                            'deposited_amount' => $alreadyDeposited,
                            'amount' => $pending,
                        ];
                    })
                    ->filter(fn (array $row) => $row['amount'] > 0)
                    ->values()
                    ->all();

                $paid = round($clubPaidByComponent->sum(fn ($amount) => (float) $amount), 2);
                $depositedAmount = round($settlements->sum(fn (EventClubSettlement $settlement) => (float) $settlement->amount), 2);
                $pendingSettlementAmount = round(collect($pendingSettlementBreakdown)->sum(fn (array $row) => (float) $row['amount']), 2);
                $receiptHistory = $settlements
                    ->map(function (EventClubSettlement $settlement) {
                        return [
                            'id' => (int) $settlement->id,
                            'receipt_number' => $settlement->receipt_number,
                            'receipt_url' => route('event-club-settlements.download', $settlement),
                            'amount' => (float) $settlement->amount,
                            'deposited_at' => optional($settlement->deposited_at)->toDateTimeString(),
                        ];
                    })
                    ->values()
                    ->all();
                $latestReceipt = $receiptHistory[0] ?? null;

                return [
                    'club_id' => (int) $club->id,
                    'club_name' => $club->club_name,
                    'club_type' => $club->club_type,
                    'church_name' => $club->church_name,
                    'district_id' => (int) ($club->district_id ?? 0),
                    'district_name' => $club->district?->name ?: 'Sin distrito',
                    'signup_status' => (string) ($club->pivot?->signup_status ?: 'targeted'),
                    'signed_up_at' => optional($club->pivot?->signed_up_at)->toDateTimeString(),
                    'signup_notes' => $club->pivot?->signup_notes,
                    'participant_count' => $memberCount,
                    'member_count' => $memberCount,
                    'expected_amount' => $expected,
                    'expected_breakdown' => $expectedBreakdown->all(),
                    'paid_amount' => $paid,
                    'remaining_amount' => max($expected - $paid, 0),
                    'deposited_amount' => $depositedAmount,
                    'pending_settlement_amount' => $pendingSettlementAmount,
                    'is_mandatory' => (bool) $event->is_mandatory,
                    'settlement_breakdown' => $pendingSettlementBreakdown,
                    'pending_settlement_breakdown' => $pendingSettlementBreakdown,
                    'settlement_receipts' => $receiptHistory,
                    'settlement_id' => $latestReceipt['id'] ?? null,
                    'settlement_receipt_number' => $latestReceipt['receipt_number'] ?? null,
                    'settlement_receipt_url' => $latestReceipt['receipt_url'] ?? null,
                    'settlement_amount' => $latestReceipt['amount'] ?? null,
                    'settlement_deposited_at' => $latestReceipt['deposited_at'] ?? null,
                ];
            })
            ->sortBy([
                ['district_name', 'asc'],
                ['club_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    protected function componentConceptLabel(Event $event, EventFeeComponent $component): string
    {
        return trim($event->title . ' - ' . $component->label);
    }
}
