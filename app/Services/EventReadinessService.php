<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Models\Member;
use App\Models\Staff;
use App\Models\User;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;
use Illuminate\Support\Collection;

class EventReadinessService
{
    public function report(Event $event, User $user): array
    {
        $event->loadMissing([
            'targetClubs:id,club_name,church_name,district_id,club_type',
            'targetClubs.district:id,name,association_id',
            'targetClubs.district.association:id,name,union_id',
            'participants.member:id,club_id,type,id_data',
            'participants.staff:id,club_id,user_id',
            'participants.staff.user:id,name',
            'documents:id,event_id,type,doc_type,title,path,status,member_id,staff_id,uploaded_by_user_id,created_at',
            'documents.member:id,club_id',
            'documents.staff:id,club_id,user_id',
            'documents.uploader:id,club_id',
            'tasks.formResponse',
            'tasks.assignments.formResponse',
            'tasks.assignments.completedBy:id,name',
            'feeComponents',
        ]);

        $visibleClubIds = $this->visibleClubIds($event, $user);
        $clubs = $this->visibleClubs($event, $visibleClubIds);
        $clubIds = $clubs->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $finance = app(EventFinanceService::class);
        $financeSummary = $finance->paymentSummary($event, $clubIds);
        $signupSummary = collect($finance->clubSignupSummary($event))
            ->when(!empty($clubIds), fn (Collection $rows) => $rows->whereIn('club_id', $clubIds))
            ->keyBy('club_id');

        $participantMetrics = $this->participantMetrics($event, $clubs, $financeSummary);
        $taskMetrics = $this->taskMetrics($event, $clubs);
        $documentMetrics = $this->documentMetrics($event, $clubs);

        $clubRows = $clubs
            ->map(function (Club $club) use ($participantMetrics, $taskMetrics, $documentMetrics, $signupSummary) {
                $clubId = (int) $club->id;
                $participants = $participantMetrics[$clubId] ?? $this->emptyParticipantMetrics();
                $tasks = $taskMetrics[$clubId] ?? $this->emptyTaskMetrics();
                $documents = $documentMetrics[$clubId] ?? $this->emptyDocumentMetrics();
                $finance = $signupSummary->get($clubId, []);
                $blockers = $this->clubBlockers($club, $participants, $tasks, $documents, $finance);
                $status = $this->statusFromBlockers($blockers);

                return [
                    'club_id' => $clubId,
                    'club_name' => $club->club_name,
                    'church_name' => $club->church_name,
                    'district_id' => (int) ($club->district_id ?? 0),
                    'district_name' => $club->district?->name,
                    'association_id' => (int) ($club->district?->association_id ?? 0),
                    'association_name' => $club->district?->association?->name,
                    'club_type' => $club->club_type,
                    'signup_status' => $finance['signup_status'] ?? $club->pivot?->signup_status ?? 'targeted',
                    'signed_up_at' => $finance['signed_up_at'] ?? optional($club->pivot?->signed_up_at)->toDateTimeString(),
                    'status' => $status,
                    'status_label' => $this->statusLabel($status),
                    'participants' => $participants,
                    'tasks' => $tasks,
                    'documents' => $documents,
                    'finance' => [
                        'expected_amount' => (float) ($finance['expected_amount'] ?? 0),
                        'paid_amount' => (float) ($finance['paid_amount'] ?? 0),
                        'required_paid_amount' => (float) ($finance['required_paid_amount'] ?? 0),
                        'optional_paid_amount' => (float) ($finance['optional_paid_amount'] ?? 0),
                        'remaining_amount' => (float) ($finance['remaining_amount'] ?? 0),
                        'deposited_amount' => (float) ($finance['deposited_amount'] ?? 0),
                        'pending_settlement_amount' => (float) ($finance['pending_settlement_amount'] ?? 0),
                        'settlement_receipts_count' => count($finance['settlement_receipts'] ?? []),
                    ],
                    'blockers' => $blockers,
                ];
            })
            ->values()
            ->all();

        $eventBlockers = $this->eventBlockers($event);
        $reminders = $this->reminders($event, $clubRows, $eventBlockers);
        $totals = $this->totals($clubRows, $eventBlockers, $reminders);
        $financialReport = $this->financialReport($event, $clubs, $financeSummary, $signupSummary, collect($clubRows)->keyBy('club_id'));

        return [
            'totals' => $totals,
            'clubs' => $clubRows,
            'financial_report' => $financialReport,
            'event_blockers' => $eventBlockers,
            'reminders' => $reminders,
            'closeout' => $this->closeout($event, $totals),
            'reminder_processor' => [
                'configured' => false,
                'status' => 'placeholder',
                'message' => 'No hay procesador de correo configurado. Estos recordatorios son una bandeja de trabajo y pueden conectarse a Mailables/colas cuando el servicio SMTP o transaccional este listo.',
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    protected function visibleClubIds(Event $event, User $user): array
    {
        $targetClubIds = $this->targetClubIds($event);
        if (($event->scope_type ?: 'club') === 'club') {
            return [(int) $event->club_id];
        }

        $role = $this->effectiveRole($user);
        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return $targetClubIds;
        }

        if ($user->can('update', $event)) {
            return $targetClubIds;
        }

        $context = $this->effectiveContext($user);
        $userClubIds = ($user->profile_type ?? null) === 'superadmin'
            ? $this->superadminClubIds($role, $context)
            : ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->all();

        return collect($targetClubIds)
            ->intersect($userClubIds)
            ->values()
            ->all();
    }

    protected function visibleClubs(Event $event, array $visibleClubIds): Collection
    {
        if (($event->scope_type ?: 'club') === 'club') {
            $club = Club::query()
                ->with('district.association:id,name,union_id')
                ->find((int) $event->club_id);

            return $club ? collect([$club]) : collect();
        }

        return $event->targetClubs
            ->filter(fn (Club $club) => empty($visibleClubIds) || in_array((int) $club->id, $visibleClubIds, true))
            ->values();
    }

    protected function targetClubIds(Event $event): array
    {
        $ids = $event->targetClubs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (($event->scope_type ?: 'club') === 'club' && !in_array((int) $event->club_id, $ids, true)) {
            $ids[] = (int) $event->club_id;
        }

        return $ids;
    }

    protected function financialReport(Event $event, Collection $clubs, array $financeSummary, Collection $signupSummary, Collection $clubRowsById): array
    {
        $components = $event->feeComponents
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($component) => [
                'id' => (int) $component->id,
                'label' => $component->label,
                'amount' => (float) $component->amount,
                'is_required' => (bool) ($component->is_required ?? true),
                'sort_order' => (int) ($component->sort_order ?? 0),
            ])
            ->all();

        $clubsById = $clubs->keyBy('id');
        $paidByClubComponent = [];
        $participants = [];

        foreach (($financeSummary['records'] ?? []) as $record) {
            $clubId = (int) ($record['club_id'] ?? 0);
            if (!$clubId || !$clubsById->has($clubId)) {
                continue;
            }

            $componentAmounts = $this->componentAmountsFromPaymentRecord($record, $components);
            foreach ($componentAmounts as $componentId => $amount) {
                $paidByClubComponent[$clubId][$componentId] = round((float) ($paidByClubComponent[$clubId][$componentId] ?? 0) + (float) $amount, 2);
            }

            $payerType = (string) ($record['payer_type'] ?? '');
            $payerId = (int) ($record['payer_id'] ?? 0);
            if (!in_array($payerType, ['member', 'staff'], true) || $payerId <= 0) {
                continue;
            }

            $participantKey = $payerType . ':' . $payerId;
            $participants[$participantKey] ??= $this->emptyFinancialParticipant($participantKey, $payerType, $payerId, $clubId, $record['payer_name'] ?? '—', $clubsById->get($clubId), $components);

            foreach ($componentAmounts as $componentId => $amount) {
                $participants[$participantKey]['paid_by_component'][$componentId] = round((float) ($participants[$participantKey]['paid_by_component'][$componentId] ?? 0) + (float) $amount, 2);
            }
        }

        foreach ($event->participants as $participant) {
            $payerType = $participant->staff_id && strtolower((string) $participant->role) === 'staff' ? 'staff' : 'member';
            $payerId = (int) ($payerType === 'staff' ? $participant->staff_id : $participant->member_id);
            if ($payerId <= 0) {
                continue;
            }

            $clubId = (int) ($payerType === 'staff' ? $participant->staff?->club_id : $participant->member?->club_id);
            if (!$clubId || !$clubsById->has($clubId)) {
                continue;
            }

            $participantKey = $payerType . ':' . $payerId;
            $name = $participant->participant_name ?: $this->participantName($participant, $payerType);
            $participants[$participantKey] ??= $this->emptyFinancialParticipant($participantKey, $payerType, $payerId, $clubId, $name, $clubsById->get($clubId), $components);
            $participants[$participantKey]['name'] = $name ?: $participants[$participantKey]['name'];
            $participants[$participantKey]['is_confirmed'] = strtolower((string) $participant->status) === 'confirmed';
            $participants[$participantKey]['participant_status'] = (string) $participant->status;
        }

        $clubRows = $clubs
            ->map(function (Club $club) use ($components, $signupSummary, $paidByClubComponent, $clubRowsById) {
                $clubId = (int) $club->id;
                $summary = $signupSummary->get($clubId, []);
                $expectedByComponent = collect($summary['expected_breakdown'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) ($row['component_id'] ?? 0) => (float) ($row['amount'] ?? 0)])
                    ->filter(fn ($amount, $componentId) => (int) $componentId > 0)
                    ->all();
                $componentAmounts = $this->componentAmountPayload($components, $paidByClubComponent[$clubId] ?? [], $expectedByComponent);
                $statusRow = $clubRowsById->get($clubId, []);
                $paidAmount = round((float) collect($componentAmounts)->sum(fn (array $row) => (float) $row['paid_amount']), 2);
                $requiredPaid = round((float) collect($componentAmounts)->filter(fn (array $row) => (bool) $row['is_required'])->sum(fn (array $row) => (float) $row['paid_amount']), 2);

                return [
                    'club_id' => $clubId,
                    'club_name' => $club->club_name,
                    'district_name' => $club->district?->name,
                    'association_name' => $club->district?->association?->name,
                    'signup_status' => $summary['signup_status'] ?? $club->pivot?->signup_status ?? 'targeted',
                    'status' => $statusRow['status'] ?? 'pending',
                    'status_label' => $statusRow['status_label'] ?? $this->statusLabel('pending'),
                    'participant_count' => (int) ($statusRow['participants']['confirmed_members'] ?? 0) + (int) ($statusRow['participants']['confirmed_staff'] ?? 0),
                    'expected_amount' => (float) ($summary['expected_amount'] ?? collect($componentAmounts)->sum(fn (array $row) => (float) $row['expected_amount'])),
                    'paid_amount' => (float) ($summary['paid_amount'] ?? $paidAmount),
                    'required_paid_amount' => (float) ($summary['required_paid_amount'] ?? $requiredPaid),
                    'optional_paid_amount' => (float) ($summary['optional_paid_amount'] ?? max($paidAmount - $requiredPaid, 0)),
                    'remaining_amount' => (float) ($summary['remaining_amount'] ?? 0),
                    'deposited_amount' => (float) ($summary['deposited_amount'] ?? 0),
                    'pending_settlement_amount' => (float) ($summary['pending_settlement_amount'] ?? 0),
                    'component_amounts' => $componentAmounts,
                ];
            })
            ->values()
            ->all();

        $participantRows = collect($participants)
            ->map(function (array $participant) use ($components) {
                $componentAmounts = $this->componentAmountPayload($components, $participant['paid_by_component'] ?? [], $this->participantExpectedByComponent($components, $participant['paid_by_component'] ?? []));
                $requiredExpected = round((float) collect($componentAmounts)->filter(fn (array $row) => (bool) $row['is_required'])->sum(fn (array $row) => (float) $row['expected_amount']), 2);
                $requiredPaid = round((float) collect($componentAmounts)->filter(fn (array $row) => (bool) $row['is_required'])->sum(fn (array $row) => (float) $row['paid_amount']), 2);
                $paidAmount = round((float) collect($componentAmounts)->sum(fn (array $row) => (float) $row['paid_amount']), 2);

                return array_merge($participant, [
                    'paid_amount' => $paidAmount,
                    'required_expected_amount' => $requiredExpected,
                    'required_paid_amount' => $requiredPaid,
                    'optional_paid_amount' => max(round($paidAmount - $requiredPaid, 2), 0),
                    'is_enrolled' => $requiredExpected > 0 ? $requiredPaid >= $requiredExpected : ((bool) ($participant['is_confirmed'] ?? false) || $paidAmount > 0),
                    'component_amounts' => $componentAmounts,
                ]);
            })
            ->sortBy([
                ['club_name', 'asc'],
                ['name', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'components' => $components,
            'totals' => [
                'clubs' => count($clubRows),
                'participants' => count($participantRows),
                'expected_amount' => round((float) collect($clubRows)->sum('expected_amount'), 2),
                'paid_amount' => round((float) collect($clubRows)->sum('paid_amount'), 2),
                'required_paid_amount' => round((float) collect($clubRows)->sum('required_paid_amount'), 2),
                'optional_paid_amount' => round((float) collect($clubRows)->sum('optional_paid_amount'), 2),
                'pending_settlement_amount' => round((float) collect($clubRows)->sum('pending_settlement_amount'), 2),
            ],
            'clubs' => $clubRows,
            'participants' => $participantRows,
        ];
    }

    protected function componentAmountsFromPaymentRecord(array $record, array $components): array
    {
        $componentIdByLabel = collect($components)
            ->mapWithKeys(fn (array $component) => [mb_strtolower(trim((string) $component['label'])) => (int) $component['id']]);
        $amounts = [];

        foreach (($record['breakdown'] ?? []) as $row) {
            $componentId = (int) ($row['component_id'] ?? 0);
            if (!$componentId) {
                $componentId = (int) ($componentIdByLabel->get(mb_strtolower(trim((string) ($row['component_label'] ?? '')))) ?? 0);
            }
            if (!$componentId) {
                continue;
            }

            $amounts[$componentId] = round((float) ($amounts[$componentId] ?? 0) + (float) ($row['amount'] ?? 0), 2);
        }

        return $amounts;
    }

    protected function componentAmountPayload(array $components, array $paidByComponent, array $expectedByComponent = []): array
    {
        $payload = [];
        foreach ($components as $component) {
            $componentId = (int) $component['id'];
            $paid = round((float) ($paidByComponent[$componentId] ?? 0), 2);
            $expected = round((float) ($expectedByComponent[$componentId] ?? 0), 2);

            $payload[(string) $componentId] = [
                'component_id' => $componentId,
                'label' => $component['label'],
                'is_required' => (bool) $component['is_required'],
                'expected_amount' => $expected,
                'paid_amount' => $paid,
                'remaining_amount' => max(round($expected - $paid, 2), 0),
            ];
        }

        return $payload;
    }

    protected function participantExpectedByComponent(array $components, array $paidByComponent): array
    {
        return collect($components)
            ->mapWithKeys(function (array $component) use ($paidByComponent) {
                $componentId = (int) $component['id'];
                $isRequired = (bool) $component['is_required'];
                $paid = (float) ($paidByComponent[$componentId] ?? 0);

                return [$componentId => ($isRequired || $paid > 0) ? (float) $component['amount'] : 0.0];
            })
            ->all();
    }

    protected function emptyFinancialParticipant(string $participantKey, string $payerType, int $payerId, int $clubId, string $name, ?Club $club, array $components): array
    {
        return [
            'participant_key' => $participantKey,
            'participant_type' => $payerType,
            'participant_type_label' => $payerType === 'staff' ? 'Staff' : 'Miembro',
            'member_id' => $payerType === 'member' ? $payerId : null,
            'staff_id' => $payerType === 'staff' ? $payerId : null,
            'name' => $name,
            'club_id' => $clubId,
            'club_name' => $club?->club_name,
            'district_name' => $club?->district?->name,
            'association_name' => $club?->district?->association?->name,
            'participant_status' => null,
            'is_confirmed' => false,
            'paid_by_component' => collect($components)->mapWithKeys(fn (array $component) => [(int) $component['id'] => 0.0])->all(),
        ];
    }

    protected function participantName($participant, string $payerType): string
    {
        if ($payerType === 'staff') {
            return ClubHelper::staffDetail($participant->staff)['name'] ?? $participant->staff?->user?->name ?? '—';
        }

        return ClubHelper::memberDetail($participant->member)['name'] ?? '—';
    }

    protected function participantMetrics(Event $event, Collection $clubs, array $financeSummary): array
    {
        $clubIds = $clubs->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $expectedByClub = collect($financeSummary['concepts'] ?? [])
            ->filter(fn ($row) => (bool) ($row['is_required'] ?? true))
            ->groupBy('club_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum(fn ($row) => (float) ($row['amount'] ?? 0)), 2));
        $memberPayments = collect($financeSummary['by_member_required_id'] ?? $financeSummary['by_member_id'] ?? [])
            ->mapWithKeys(fn ($amount, $memberId) => [(int) $memberId => (float) $amount]);
        $staffPayments = collect($financeSummary['by_staff_required_id'] ?? $financeSummary['by_staff_id'] ?? [])
            ->mapWithKeys(fn ($amount, $staffId) => [(int) $staffId => (float) $amount]);

        $memberClubById = $memberPayments->isNotEmpty()
            ? Member::query()->whereIn('id', $memberPayments->keys()->all())->whereIn('club_id', $clubIds)->pluck('club_id', 'id')->map(fn ($id) => (int) $id)
            : collect();
        $staffClubById = $staffPayments->isNotEmpty()
            ? Staff::query()->whereIn('id', $staffPayments->keys()->all())->whereIn('club_id', $clubIds)->pluck('club_id', 'id')->map(fn ($id) => (int) $id)
            : collect();

        $metrics = [];
        foreach ($clubs as $club) {
            $metrics[(int) $club->id] = $this->emptyParticipantMetrics();
            $metrics[(int) $club->id]['has_required_payment'] = (float) ($expectedByClub[(int) $club->id] ?? 0) > 0;
            $metrics[(int) $club->id]['required_expected_per_participant'] = (float) ($expectedByClub[(int) $club->id] ?? 0);
        }

        foreach ($event->participants as $participant) {
            $status = strtolower((string) $participant->status);
            $isConfirmed = $status === 'confirmed';
            $memberClubId = (int) ($participant->member?->club_id ?? 0);
            if ($participant->member_id && isset($metrics[$memberClubId])) {
                $metrics[$memberClubId]['member_participants']++;
                if ($isConfirmed) {
                    $metrics[$memberClubId]['confirmed_members']++;
                }
                continue;
            }

            $staffClubId = (int) ($participant->staff?->club_id ?? 0);
            if ($participant->staff_id && strtolower((string) $participant->role) === 'staff' && isset($metrics[$staffClubId])) {
                $metrics[$staffClubId]['staff_participants']++;
                if ($isConfirmed) {
                    $metrics[$staffClubId]['confirmed_staff']++;
                }
            }
        }

        foreach ($memberPayments as $memberId => $amount) {
            $clubId = (int) ($memberClubById[(int) $memberId] ?? 0);
            if (!$clubId || !isset($metrics[$clubId])) {
                continue;
            }

            $expected = (float) ($expectedByClub[$clubId] ?? 0);
            if ($expected > 0 ? $amount >= $expected : $amount > 0) {
                $metrics[$clubId]['enrolled_members']++;
            }
        }

        foreach ($staffPayments as $staffId => $amount) {
            $clubId = (int) ($staffClubById[(int) $staffId] ?? 0);
            if (!$clubId || !isset($metrics[$clubId])) {
                continue;
            }

            $expected = (float) ($expectedByClub[$clubId] ?? 0);
            if ($expected > 0 ? $amount >= $expected : $amount > 0) {
                $metrics[$clubId]['enrolled_staff']++;
            }
        }

        foreach ($metrics as $clubId => $row) {
            $metrics[$clubId]['confirmed_unpaid_members'] = max((int) $row['confirmed_members'] - (int) $row['enrolled_members'], 0);
            $metrics[$clubId]['confirmed_unpaid_staff'] = max((int) $row['confirmed_staff'] - (int) $row['enrolled_staff'], 0);
        }

        return $metrics;
    }

    protected function taskMetrics(Event $event, Collection $clubs): array
    {
        $metrics = [];
        foreach ($clubs as $club) {
            $metrics[(int) $club->id] = $this->emptyTaskMetrics();
        }

        foreach ($event->tasks as $task) {
            foreach ($clubs as $club) {
                if (!$this->taskAppliesToClub($task, $club)) {
                    continue;
                }

                $clubId = (int) $club->id;
                $isDone = $this->taskDoneForClub($task, $club);
                $metrics[$clubId]['total']++;
                $metrics[$clubId][$isDone ? 'done' : 'pending']++;
                if (!$isDone) {
                    $metrics[$clubId]['pending_items'][] = [
                        'id' => (int) $task->id,
                        'title' => $task->title,
                        'responsibility_level' => (string) ($task->responsibility_level ?: 'organizer'),
                        'due_at' => optional($task->due_at)->toDateTimeString(),
                        'is_overdue' => $task->due_at ? $task->due_at->isPast() : false,
                    ];
                }
            }
        }

        return $metrics;
    }

    protected function documentMetrics(Event $event, Collection $clubs): array
    {
        $metrics = [];
        foreach ($clubs as $club) {
            $metrics[(int) $club->id] = $this->emptyDocumentMetrics();
        }

        foreach ($event->documents as $document) {
            $clubId = (int) ($document->member?->club_id ?? $document->staff?->club_id ?? 0);
            if (!$clubId && $document->uploaded_by_user_id) {
                $clubId = (int) (ClubHelper::clubIdsForUser($document->uploader)->first() ?? 0);
            }
            if (!$clubId || !isset($metrics[$clubId])) {
                continue;
            }

            $metrics[$clubId]['uploaded']++;
            if (in_array(strtolower((string) $document->status), ['approved', 'accepted', 'valid'], true)) {
                $metrics[$clubId]['approved']++;
            }
        }

        return $metrics;
    }

    protected function eventBlockers(Event $event): array
    {
        $blockers = [];
        $organizerTasks = $event->tasks
            ->filter(fn (EventTask $task) => (string) ($task->responsibility_level ?: 'organizer') === 'organizer');
        $pendingOrganizerTasks = $organizerTasks
            ->filter(fn (EventTask $task) => strtolower((string) $task->status) !== 'done')
            ->values();

        foreach ($pendingOrganizerTasks as $task) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'organizer_task',
                'label' => 'Tarea del organizador pendiente',
                'message' => $task->title,
                'due_at' => optional($task->due_at)->toDateTimeString(),
            ];
        }

        return $blockers;
    }

    protected function clubBlockers(Club $club, array $participants, array $tasks, array $documents, array $finance): array
    {
        $blockers = [];
        $signupStatus = (string) ($finance['signup_status'] ?? $club->pivot?->signup_status ?? 'targeted');
        $hasClubProgress = (int) $participants['member_participants'] > 0
            || (int) $participants['staff_participants'] > 0
            || (int) $participants['confirmed_members'] > 0
            || (int) $participants['confirmed_staff'] > 0
            || (int) $participants['enrolled_members'] > 0
            || (int) $participants['enrolled_staff'] > 0
            || (float) ($finance['paid_amount'] ?? 0) > 0
            || (float) ($finance['deposited_amount'] ?? 0) > 0
            || (int) ($tasks['done'] ?? 0) > 0
            || (int) ($documents['uploaded'] ?? 0) > 0;

        if ($signupStatus === 'declined') {
            $blockers[] = [
                'severity' => 'blocking',
                'type' => 'signup_declined',
                'label' => 'Club declino el evento',
                'message' => 'El club aparece como declinado para este evento.',
            ];
        } elseif ($signupStatus !== 'signed_up') {
            $blockers[] = [
                'severity' => $hasClubProgress ? 'pending' : 'blocking',
                'type' => 'signup_pending',
                'label' => $hasClubProgress ? 'Inscripcion del club pendiente' : 'Club sin avance',
                'message' => $hasClubProgress
                    ? 'El club todavia no ha confirmado su participacion.'
                    : 'El club sigue marcado como dirigido y no tiene participantes, pagos, tareas completadas ni documentos registrados.',
            ];
        }

        if ((int) $participants['confirmed_unpaid_members'] > 0) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'member_payment_missing',
                'label' => 'Pago obligatorio de miembros pendiente',
                'message' => $participants['confirmed_unpaid_members'] . ' miembro(s) confirmados todavia no estan inscritos por pago obligatorio.',
            ];
        }

        if ((int) $participants['confirmed_unpaid_staff'] > 0) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'staff_payment_missing',
                'label' => 'Pago obligatorio de staff pendiente',
                'message' => $participants['confirmed_unpaid_staff'] . ' staff confirmado todavia no esta inscrito por pago obligatorio.',
            ];
        }

        if (
            (int) $participants['confirmed_members'] === 0
            && (int) $participants['confirmed_staff'] === 0
            && (int) $participants['enrolled_members'] === 0
            && (int) $participants['enrolled_staff'] === 0
        ) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'participants_missing',
                'label' => 'Sin participantes listos',
                'message' => 'El club todavia no tiene miembros o staff confirmados/inscritos para este evento.',
            ];
        }

        if ((int) $tasks['pending'] > 0) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'tasks_pending',
                'label' => 'Tareas pendientes',
                'message' => $tasks['pending'] . ' tarea(s) aplicables al club siguen pendientes.',
                'items' => $tasks['pending_items'],
            ];
        }

        if ((float) ($finance['pending_settlement_amount'] ?? 0) > 0) {
            $blockers[] = [
                'severity' => 'pending',
                'type' => 'settlement_pending',
                'label' => 'Deposito pendiente',
                'message' => 'Hay dinero de evento cobrado y pendiente de depositar hacia el organizador.',
                'amount' => (float) $finance['pending_settlement_amount'],
            ];
        }

        return $blockers;
    }

    protected function reminders(Event $event, array $clubRows, array $eventBlockers): array
    {
        $rows = [];

        foreach ($clubRows as $club) {
            foreach ($club['blockers'] as $blocker) {
                $rows[] = [
                    'scope_type' => 'club',
                    'scope_id' => $club['club_id'],
                    'recipient_label' => $club['club_name'],
                    'severity' => $blocker['severity'],
                    'reason' => $blocker['label'],
                    'message' => $this->reminderMessage($event, $club['club_name'], $blocker),
                    'processor_status' => 'placeholder',
                ];
            }
        }

        foreach ($eventBlockers as $blocker) {
            $rows[] = [
                'scope_type' => 'organizer',
                'scope_id' => (int) ($event->scope_id ?: $event->club_id),
                'recipient_label' => 'Organizador',
                'severity' => $blocker['severity'],
                'reason' => $blocker['label'],
                'message' => $this->reminderMessage($event, 'Organizador', $blocker),
                'processor_status' => 'placeholder',
            ];
        }

        return $rows;
    }

    protected function reminderMessage(Event $event, string $recipient, array $blocker): string
    {
        return "Recordatorio para {$recipient}: {$blocker['message']} Evento: {$event->title}.";
    }

    protected function closeout(Event $event, array $totals): array
    {
        $checks = [
            [
                'key' => 'no_blocking_items',
                'label' => 'Sin alertas criticas',
                'complete' => (int) $totals['blocked_clubs'] === 0 && (int) $totals['blocking_event_items'] === 0,
            ],
            [
                'key' => 'all_clubs_ready',
                'label' => 'Todos los clubes listos',
                'complete' => (int) $totals['ready_clubs'] === (int) $totals['clubs'] && (int) $totals['clubs'] > 0,
            ],
            [
                'key' => 'no_pending_settlements',
                'label' => 'Sin depositos pendientes',
                'complete' => (float) $totals['pending_settlement_amount'] <= 0,
            ],
            [
                'key' => 'event_started_or_past',
                'label' => 'Evento en curso o finalizado',
                'complete' => in_array($event->effective_status, [Event::STATUS_ONGOING, Event::STATUS_PAST], true),
            ],
        ];

        return [
            'status' => collect($checks)->every(fn (array $check) => $check['complete']) ? 'ready_to_close' : 'not_ready',
            'checks' => $checks,
            'instructions' => 'Cuando todos los checks esten completos, el cierre puede guardar un snapshot final del roster, pagos, tareas, documentos y comprobantes.',
        ];
    }

    protected function totals(array $clubRows, array $eventBlockers, array $reminders): array
    {
        $rows = collect($clubRows);

        return [
            'clubs' => $rows->count(),
            'ready_clubs' => $rows->where('status', 'ready')->count(),
            'pending_clubs' => $rows->where('status', 'pending')->count(),
            'blocked_clubs' => $rows->where('status', 'blocked')->count(),
            'confirmed_members' => (int) $rows->sum(fn (array $row) => (int) $row['participants']['confirmed_members']),
            'enrolled_members' => (int) $rows->sum(fn (array $row) => (int) $row['participants']['enrolled_members']),
            'confirmed_staff' => (int) $rows->sum(fn (array $row) => (int) $row['participants']['confirmed_staff']),
            'enrolled_staff' => (int) $rows->sum(fn (array $row) => (int) $row['participants']['enrolled_staff']),
            'pending_tasks' => (int) $rows->sum(fn (array $row) => (int) $row['tasks']['pending']),
            'pending_settlement_amount' => round((float) $rows->sum(fn (array $row) => (float) $row['finance']['pending_settlement_amount']), 2),
            'blocking_event_items' => collect($eventBlockers)->where('severity', 'blocking')->count(),
            'pending_event_items' => collect($eventBlockers)->where('severity', 'pending')->count(),
            'reminders' => count($reminders),
        ];
    }

    protected function taskAppliesToClub(EventTask $task, Club $club): bool
    {
        $level = (string) ($task->responsibility_level ?: 'organizer');

        if ($level === 'organizer') {
            return false;
        }

        return $task->assignments->contains(function (EventTaskAssignment $assignment) use ($club) {
            return match ($assignment->scope_type) {
                'club' => (int) $assignment->scope_id === (int) $club->id,
                'district' => (int) $assignment->scope_id === (int) $club->district_id,
                'association' => (int) $assignment->scope_id === (int) ($club->district?->association_id ?? 0),
                default => false,
            };
        });
    }

    protected function taskDoneForClub(EventTask $task, Club $club): bool
    {
        $assignment = $task->assignments->first(function (EventTaskAssignment $assignment) use ($club) {
            return match ($assignment->scope_type) {
                'club' => (int) $assignment->scope_id === (int) $club->id,
                'district' => (int) $assignment->scope_id === (int) $club->district_id,
                'association' => (int) $assignment->scope_id === (int) ($club->district?->association_id ?? 0),
                default => false,
            };
        });

        return strtolower((string) ($assignment?->status ?? 'todo')) === 'done';
    }

    protected function statusFromBlockers(array $blockers): string
    {
        $severities = collect($blockers)->pluck('severity');
        if ($severities->contains('blocking')) {
            return 'blocked';
        }

        return $severities->isNotEmpty() ? 'pending' : 'ready';
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Preparacion completa',
            'blocked' => 'Atencion critica requerida',
            default => 'Pendientes por completar',
        };
    }

    protected function emptyParticipantMetrics(): array
    {
        return [
            'member_participants' => 0,
            'staff_participants' => 0,
            'confirmed_members' => 0,
            'confirmed_staff' => 0,
            'enrolled_members' => 0,
            'enrolled_staff' => 0,
            'confirmed_unpaid_members' => 0,
            'confirmed_unpaid_staff' => 0,
            'has_required_payment' => false,
            'required_expected_per_participant' => 0.0,
        ];
    }

    protected function emptyTaskMetrics(): array
    {
        return [
            'total' => 0,
            'done' => 0,
            'pending' => 0,
            'pending_items' => [],
        ];
    }

    protected function emptyDocumentMetrics(): array
    {
        return [
            'uploaded' => 0,
            'approved' => 0,
        ];
    }

    protected function effectiveRole(User $user): string
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return ClubHelper::roleKey($user);
        }

        return (string) (SuperadminContext::fromSession()['role'] ?? 'superadmin');
    }

    protected function effectiveContext(User $user): array
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return [];
        }

        return SuperadminContext::fromSession();
    }

    protected function superadminClubIds(string $role, array $context): array
    {
        if ($role === 'club_director') {
            return collect([(int) ($context['club_id'] ?? 0)])->filter()->values()->all();
        }

        if ($role === 'district_pastor') {
            return Club::query()->where('district_id', (int) ($context['district_id'] ?? 0))->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($role === 'association_youth_director') {
            return Club::query()
                ->whereHas('district', fn ($query) => $query->where('association_id', (int) ($context['association_id'] ?? 0)))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($role === 'union_youth_director') {
            return Club::query()
                ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) ($context['union_id'] ?? 0)))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return Club::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
