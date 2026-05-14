<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\BankInfo;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Models\TreasuryMovement;
use App\Services\AttendanceDuesPaymentService;
use App\Services\ClubTreasuryService;
use App\Services\EventClubSettlementService;
use App\Services\EventFinanceService;
use App\Support\BankInfoFormatter;
use App\Support\ClubHelper;

class FinanceBootstrapper
{
    public function __construct(
        private readonly ClubTreasuryService $treasuryService,
        private readonly EventFinanceService $eventFinanceService,
        private readonly EventClubSettlementService $settlementService,
        private readonly FinanceMovementReader $movementReader,
    ) {
    }

    public function cashboxData($user, Club $club, array $filters = []): array
    {
        $clubIds = ClubHelper::clubIdsForUser($user);
        $clubs = ClubHelper::clubsForUser($user)
            ->map(fn (Club $allowedClub) => [
                'id' => (int) $allowedClub->id,
                'club_name' => $allowedClub->club_name,
            ])
            ->values();

        $members = ClubHelper::membersOfClub((int) $club->id)
            ->map(fn (array $member) => [
                'id' => $member['member_id'],
                'applicant_name' => $member['applicant_name'],
                'club_id' => $member['club_id'],
                'class_id' => $member['class_id'],
                'member_type' => $member['member_type'],
                'id_data' => $member['id_data'],
            ])
            ->values();

        $staff = ClubHelper::staffOfClub((int) $club->id)
            ->loadMissing('user:id,name,email')
            ->map(function (Staff $staff) {
                $detail = ClubHelper::staffDetail($staff);

                return [
                    'id' => (int) $staff->id,
                    'name' => $detail['name'] ?? $staff->user?->name,
                    'email' => $staff->user?->email,
                    'club_id' => (int) $staff->club_id,
                    'status' => $staff->status,
                ];
            })
            ->values();

        $classes = ClubClass::query()
            ->where('club_id', $club->id)
            ->orderBy('class_order')
            ->orderBy('class_name')
            ->get(['id', 'club_id', 'class_name', 'class_order']);

        $concepts = PaymentConcept::query()
            ->whereIn('club_id', $clubIds)
            ->where('status', 'active')
            ->with([
                'event:id,title,start_at',
                'eventFeeComponent:id,label,amount,is_required,sort_order',
                'scopes' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->with(['club:id,club_name', 'class:id,class_name', 'member:id,applicant_name', 'staff:id,name']);
                },
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'pay_to', 'club_id', 'reusable', 'event_id', 'event_fee_component_id']);

        $accounts = $this->accountsForClubs($clubIds);
        if ($accounts->isEmpty()) {
            $accounts = collect([
                Account::query()->firstOrCreate(
                    ['club_id' => $club->id, 'pay_to' => 'club_budget'],
                    ['label' => 'Club budget', 'balance' => 0]
                ),
            ]);
        }

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->whereNull('settles_expense_id')
            ->with(['settlementExpense:id,pay_to,settles_expense_id,amount,expense_date'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'club_id',
                'pay_to',
                'funds_location',
                'payment_concept_id',
                'payee_id',
                'amount',
                'expense_date',
                'description',
                'reimbursed_to',
                'created_by_user_id',
                'status',
                'receipt_path',
                'reimbursement_receipt_path',
                'settles_expense_id',
                'created_at',
            ]);

        return [
            'engine_version' => 'finance_engine_v1_bootstrap',
            'club' => ['id' => (int) $club->id, 'club_name' => $club->club_name],
            'clubs' => $clubs,
            'classes' => $classes,
            'members' => $members,
            'staff' => $staff,
            'concepts' => $concepts,
            'accounts' => $accounts->values(),
            'expenses' => $expenses,
            'payment_types' => ['zelle', 'cash', 'check', 'transfer', 'initial'],
            'engine_report' => $this->movementReport($club, [
                'limit' => $filters['limit'] ?? 80,
            ]),
        ];
    }

    public function accountingData($user, Club $club, array $filters = []): array
    {
        $summary = $this->treasuryService->summary($club);
        $clubs = ClubHelper::clubsForUser($user)
            ->map(fn (Club $allowedClub) => [
                'id' => (int) $allowedClub->id,
                'club_name' => $allowedClub->club_name,
            ])
            ->values();

        $treasury = [
            'club' => ['id' => (int) $club->id, 'club_name' => $club->club_name],
            'bank_info' => BankInfoFormatter::payload($this->treasuryService->clubBankInfo($club)),
            'accounts' => $this->accountsForClubs(collect([$club->id]))
                ->map(fn (Account $account) => [
                    'value' => $account->pay_to,
                    'label' => $account->label ?: $account->pay_to,
                ])
                ->values(),
            'summary' => $summary,
            'income_rows' => $this->treasuryService->incomeRows($club)->values(),
            'pending_staff_remittances' => $this->pendingStaffRemittanceRows($club),
            'movements' => $this->treasuryMovementRows($club),
        ];

        return [
            'engine_version' => 'finance_engine_v1_bootstrap',
            'club' => $treasury['club'],
            'clubs' => $clubs,
            'treasury' => $treasury,
            'account_report' => [
                'club_id' => (int) $club->id,
                'clubs' => $clubs,
                'accounts' => collect($summary['accounts'] ?? [])
                    ->map(fn (array $row) => [
                        ...$row,
                        'entries' => (float) ($row['income'] ?? $row['total_income'] ?? 0),
                        'expenses' => (float) ($row['expenses'] ?? $row['total_expenses'] ?? 0),
                        'balance' => (float) ($row['total_available'] ?? ((float) ($row['cash_balance'] ?? 0) + (float) ($row['bank_balance'] ?? 0))),
                    ])
                    ->values(),
            ],
            'engine_report' => $this->movementReport($club, [
                'limit' => $filters['limit'] ?? 200,
            ]),
            'event_settlements' => $this->eventSettlementRows($user, $club),
        ];
    }

    private function movementReport(Club $club, array $filters): array
    {
        $movements = $this->movementReader->movementsForClub($club, $filters);

        return [
            'engine_version' => 'finance_engine_v1_read_model',
            'scope' => [
                'club_id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'filters' => $filters,
            'summary' => $this->movementReader->summaryForClub($club),
            'movements' => $movements->values()->all(),
        ];
    }

    private function accountsForClubs($clubIds)
    {
        return Account::query()
            ->whereIn('club_id', collect($clubIds)->map(fn ($id) => (int) $id)->values())
            ->orderBy('label')
            ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);
    }

    private function treasuryMovementRows(Club $club): array
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

    private function pendingStaffRemittanceRows(Club $club): array
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
                            'payer_name' => $member['name'] ?? $payment->payer_name ?? '—',
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

    private function eventSettlementRows($user, Club $club): array
    {
        if (!$this->canManageSettlementForClub($user, $club)) {
            return [];
        }

        return Event::query()
            ->where('is_payable', true)
            ->whereHas('targetClubs', fn ($query) => $query->where('clubs.id', $club->id))
            ->with([
                'feeComponents',
                'targetClubs' => fn ($query) => $query
                    ->where('clubs.id', $club->id)
                    ->with('district:id,name,association_id'),
            ])
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (Event $event) use ($club) {
                $summary = collect($this->eventFinanceService->clubSignupSummary($event))
                    ->firstWhere('club_id', $club->id);

                if (!$summary) {
                    return null;
                }

                $hasPending = (float) ($summary['pending_settlement_amount'] ?? 0) > 0;
                $hasReceipts = !empty($summary['settlement_receipts'] ?? []);

                if (!$hasPending && !$hasReceipts) {
                    return null;
                }

                $paidMembers = $this->eventFinanceService->paidMemberSummary($event, (int) $club->id);

                return [
                    'event_id' => (int) $event->id,
                    'event_title' => $event->title,
                    'event_start_at' => optional($event->start_at)->toDateTimeString(),
                    'organizer_label' => $this->organizerLabelForEvent($event),
                    'organizer_bank_info' => $this->organizerBankInfoForEvent($event),
                    'club_id' => (int) $club->id,
                    'club_name' => $club->club_name,
                    'pending_settlement_amount' => (float) ($summary['pending_settlement_amount'] ?? 0),
                    'pending_settlement_breakdown' => $summary['pending_settlement_breakdown'] ?? [],
                    'deposited_amount' => (float) ($summary['deposited_amount'] ?? 0),
                    'settlement_receipts' => $summary['settlement_receipts'] ?? [],
                    'paid_members_count' => count($paidMembers),
                    'paid_members_total' => (float) collect($paidMembers)->sum('total_paid'),
                    'paid_members' => $paidMembers,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function organizerLabelForEvent(Event $event): string
    {
        return $this->settlementService->organizerLabel(new EventClubSettlement([
            'organizer_scope_type' => (string) ($event->scope_type ?: 'club'),
            'organizer_scope_id' => (int) ($event->scope_id ?: $event->club_id),
        ]));
    }

    private function organizerBankInfoForEvent(Event $event): ?array
    {
        $scopeType = (string) ($event->scope_type ?: 'club');
        $scopeId = (int) ($event->scope_id ?: $event->club_id);

        [$bankableType, $payTo] = match ($scopeType) {
            'union' => [\App\Models\Union::class, 'union_budget'],
            'association' => [\App\Models\Association::class, 'association_budget'],
            'district' => [\App\Models\District::class, 'district_budget'],
            'church' => [\App\Models\Church::class, 'church_budget'],
            default => [Club::class, 'club_budget'],
        };

        $bankInfo = BankInfo::query()
            ->where('bankable_type', $bankableType)
            ->where('bankable_id', $scopeId)
            ->where('pay_to', $payTo)
            ->where('is_active', true)
            ->first();

        return BankInfoFormatter::payload($bankInfo);
    }

    private function canManageSettlementForClub($user, Club $club): bool
    {
        $role = ClubHelper::roleKey($user);
        $allowedClubIds = ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id);

        if ($role === 'superadmin') {
            return $allowedClubIds->contains((int) $club->id);
        }

        return in_array($role, ['club_director', 'club_personal'], true)
            && $allowedClubIds->contains((int) $club->id);
    }
}
