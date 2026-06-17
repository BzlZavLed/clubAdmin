<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Services\ClubTreasuryService;
use App\Services\PaymentReceiptService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceIncomeWriter
{
    public function __construct(
        private readonly PaymentReceiptService $paymentReceiptService,
        private readonly ClubTreasuryService $treasuryService,
    ) {
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'payment_concept_id' => ['nullable', 'integer', 'exists:payment_concepts,id'],
            'event_concept_ids' => ['nullable', 'array'],
            'event_concept_ids.*' => ['integer', 'exists:payment_concepts,id'],
            'concept_text' => ['nullable', 'string', 'max:255', 'required_without_all:payment_concept_id,event_concept_ids'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['nullable', 'email', 'max:255'],
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in(['zelle', 'cash', 'check', 'transfer', 'initial'])],
            'zelle_phone' => ['nullable', 'string', 'max:32'],
            'check_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'check_image.image' => 'La imagen del cheque debe ser JPG, PNG o WEBP.',
            'check_image.mimes' => 'La imagen del cheque debe ser JPG, PNG o WEBP.',
            'check_image.max' => 'La imagen del cheque no puede pesar mas de 4 MB.',
        ]);

        $isInitial = $validated['payment_type'] === 'initial';
        if ($isInitial && !in_array($user?->profile_type, ['club_director', 'treasurer', 'superadmin'], true)) {
            return response()->json(['message' => 'Saldo inicial solo puede ser registrado por director, tesorero o superadmin.'], 403);
        }

        $isMember = !empty($validated['member_id']);
        $isStaff = !empty($validated['staff_id']);
        $hasCustomPayer = !empty($validated['payer_name']);
        if (!$isInitial && collect([$isMember, $isStaff, $hasCustomPayer])->filter()->count() !== 1) {
            return response()->json(['message' => 'Provide exactly one payer: member, staff, or external payer name.'], 422);
        }

        $allowedClubIds = ClubHelper::clubIdsForUser($user);

        $concept = null;
        $clubId = null;
        $expected = null;
        $payTo = null;
        $conceptText = $validated['concept_text'] ?? null;
        $eventAllocationRows = [];
        $isEventBundle = !empty($validated['event_concept_ids']);

        if ($isEventBundle) {
            $eventBundleConcepts = PaymentConcept::query()
                ->whereIn('id', collect($validated['event_concept_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all())
                ->where('status', 'active')
                ->with(['event:id,title', 'eventFeeComponent:id,label,amount,is_required,sort_order', 'scopes'])
                ->get()
                ->sortBy([
                    fn (PaymentConcept $item) => (int) ($item->eventFeeComponent?->sort_order ?? 0),
                    fn (PaymentConcept $item) => (int) $item->id,
                ])
                ->values();

            if ($eventBundleConcepts->count() !== collect($validated['event_concept_ids'])->unique()->count()) {
                return response()->json(['errors' => ['event_concept_ids' => ['Uno de los componentes del evento ya no está disponible.']]], 422);
            }

            $eventIds = $eventBundleConcepts->pluck('event_id')->filter()->unique()->values();
            $clubIds = $eventBundleConcepts->pluck('club_id')->map(fn ($id) => (int) $id)->unique()->values();
            $payToValues = $eventBundleConcepts->pluck('pay_to')->filter()->unique()->values();

            if ($eventIds->count() !== 1 || $clubIds->count() !== 1 || $eventBundleConcepts->contains(fn (PaymentConcept $item) => empty($item->event_fee_component_id))) {
                return response()->json(['errors' => ['event_concept_ids' => ['Selecciona componentes de un mismo evento y club.']]], 422);
            }

            $clubId = (int) $clubIds->first();
            if (!$allowedClubIds->contains($clubId)) {
                abort(403, 'You cannot record payments for this club.');
            }

            $payTo = $payToValues->first() ?: 'club_budget';
            $conceptText = $eventBundleConcepts->first()?->event?->title ?: 'Pago de evento';
            $this->assertRequiredEventComponentsSelected(
                $eventBundleConcepts,
                $isMember ? (int) $validated['member_id'] : null,
                $isStaff ? (int) $validated['staff_id'] : null
            );
            $bundlePlan = $this->eventBundlePaymentPlan(
                $eventBundleConcepts,
                $isMember ? (int) $validated['member_id'] : null,
                $isStaff ? (int) $validated['staff_id'] : null,
                (float) $validated['amount_paid']
            );
            $expected = $bundlePlan['expected'];
            $amountPaid = $bundlePlan['amount_paid'];
            $balanceAfter = $bundlePlan['balance_after'];
            $eventAllocationRows = $bundlePlan['allocations'];
        } elseif (!empty($validated['payment_concept_id'])) {
            $concept = PaymentConcept::query()
                ->where('id', $validated['payment_concept_id'])
                ->where('status', 'active')
                ->with(['eventFeeComponent:id,label,amount,is_required,sort_order', 'scopes'])
                ->firstOrFail();

            if (!$allowedClubIds->contains((int) $concept->club_id)) {
                abort(403, 'You cannot record payments for this club.');
            }

            $clubId = (int) $concept->club_id;
            $expected = $concept->amount !== null ? (float) $concept->amount : 0.0;
            $payTo = $concept->pay_to ?? 'club_budget';
            $conceptText = null;

            if ($concept->event_id && $concept->event_fee_component_id) {
                $this->assertRequiredEventComponentsSelected(
                    collect([$concept]),
                    $isMember ? (int) $validated['member_id'] : null,
                    $isStaff ? (int) $validated['staff_id'] : null
                );
            }
        } else {
            $clubId = (int) ($validated['club_id'] ?? 0);
            if (!$clubId || !$allowedClubIds->contains($clubId)) {
                abort(403, 'You cannot record payments for this club.');
            }
            $payTo = $validated['pay_to'] ?? 'club_budget';
        }

        if ($isInitial) {
            $conceptText = $conceptText ?: 'Saldo inicial';
            $expected = null;
        }

        if ($payTo === 'reimbursement_to') {
            return response()->json([
                'message' => 'Los ingresos deben registrarse en una cuenta de fondos, no en reembolsos pendientes.',
                'errors' => ['pay_to' => ['Los ingresos deben registrarse en una cuenta de fondos, no en reembolsos pendientes.']],
            ], 422);
        }

        $isReusableConcept = (bool) ($concept?->reusable);
        if (!$isEventBundle) {
            $priorPaidQuery = Payment::query()
                ->where('club_id', $clubId)
                ->when($concept, fn ($query) => $query->where('payment_concept_id', $concept->id));

            if ($isMember) {
                $priorPaidQuery->where('member_id', $validated['member_id'] ?? null);
            } elseif ($isStaff) {
                $priorPaidQuery->where('staff_id', $validated['staff_id'] ?? null);
            } else {
                $priorPaidQuery
                    ->whereNull('member_id')
                    ->whereNull('staff_id')
                    ->where('payer_name', $validated['payer_name'] ?? null);
            }

            $priorPaid = $isInitial ? 0.0 : (float) $priorPaidQuery->sum('amount_paid');
            $remainingBefore = $expected !== null ? max($expected - $priorPaid, 0.0) : null;

            if (!$isReusableConcept && $expected !== null && $expected > 0 && $remainingBefore !== null && $remainingBefore <= 0) {
                return response()->json(['errors' => ['payment_concept_id' => ['Este concepto ya fue pagado completamente para este pagador.']]], 422);
            }

            $amountPaid = (float) $validated['amount_paid'];
            if ($isReusableConcept && $expected !== null && $expected > 0 && abs($amountPaid - $expected) > 0.0001) {
                return response()->json(['errors' => ['amount_paid' => ['Los conceptos reutilizables deben cobrarse por el importe completo del concepto.']]], 422);
            }

            if (!$isReusableConcept && $expected !== null && $expected > 0 && $remainingBefore !== null && $amountPaid > $remainingBefore) {
                $amountPaid = $remainingBefore;
            }

            $balanceAfter = ($isReusableConcept || $expected === null) ? null : max($expected - ($priorPaid + $amountPaid), 0.0);
        }

        $account = Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => Str::title(str_replace('_', ' ', $payTo)), 'balance' => 0]
        );

        $club = Club::withoutGlobalScopes()->findOrFail($clubId);
        $clubBankInfo = $this->treasuryService->clubBankInfo($club);
        if (in_array($validated['payment_type'], $this->treasuryService->electronicPaymentTypes(), true) && !$clubBankInfo) {
            return response()->json(['message' => 'Registra la cuenta bancaria del club antes de recibir pagos electrónicos.'], 422);
        }

        if ($validated['payment_type'] === 'zelle' && empty($clubBankInfo?->zelle_phone)) {
            return response()->json(['message' => 'La cuenta bancaria del club necesita un teléfono Zelle registrado.'], 422);
        }

        if ($validated['payment_type'] === 'zelle' && empty($validated['zelle_phone'])) {
            return response()->json(['message' => 'Ingresa el teléfono Zelle desde donde se envió el dinero.'], 422);
        }

        $zellePhone = $validated['payment_type'] === 'zelle' ? $validated['zelle_phone'] : null;

        if ($user?->profile_type === 'club_personal') {
            $staffValidation = $this->validateStaffPaymentScope($user, $clubId, $validated, $concept, $isInitial, $isEventBundle, $isMember, $isStaff);
            if ($staffValidation) {
                return $staffValidation;
            }
        }

        $checkImagePath = $validated['payment_type'] === 'check' && $request->hasFile('check_image')
            ? $request->file('check_image')->store('payments/checks', 'public')
            : null;

        $payment = null;
        DB::transaction(function () use ($request, $clubId, $concept, $validated, $expected, $amountPaid, $balanceAfter, $zellePhone, $checkImagePath, $payTo, $conceptText, $account, $eventAllocationRows, $isEventBundle, &$payment) {
            $payment = Payment::query()->create([
                'club_id' => $clubId,
                'payment_concept_id' => $isEventBundle ? null : $concept?->id,
                'concept_text' => $conceptText,
                'pay_to' => $payTo,
                'account_id' => $account?->id,
                'member_id' => $validated['member_id'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
                'payer_name' => $validated['payer_name'] ?? null,
                'payer_email' => $validated['payer_email'] ?? null,
                'amount_paid' => $amountPaid,
                'expected_amount' => $expected,
                'balance_due_after' => $balanceAfter,
                'payment_date' => $validated['payment_date'],
                'payment_type' => $validated['payment_type'],
                'zelle_phone' => $zellePhone,
                'check_image_path' => $checkImagePath,
                'received_by_user_id' => $request->user()?->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($eventAllocationRows as $allocationRow) {
                $payment->allocations()->create($allocationRow);
            }

            $account->increment('balance', $amountPaid);
        });

        $payment->load([
            'member:id,type,id_data',
            'staff:id,type,id_data,user_id',
            'staff.user:id,name',
            'concept:id,concept,amount,reusable,event_id,event_fee_component_id',
            'concept.event:id,title,start_at',
            'concept.eventFeeComponent:id,label,amount,is_required,sort_order',
            'allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
            'allocations.concept:id,concept,event_id,event_fee_component_id',
            'allocations.concept.event:id,title,start_at',
            'allocations.concept.eventFeeComponent:id,label,amount,is_required,sort_order',
            'receivedBy:id,name',
        ]);

        $member = ClubHelper::memberDetail($payment->member);
        $staff = ClubHelper::staffDetail($payment->staff);
        $payment->setAttribute('member_display_name', $member['name'] ?? null);
        $payment->setAttribute('staff_display_name', $staff['name'] ?? null);
        $payment->setAttribute('payer_display_name', $member['name'] ?? $staff['name'] ?? $payment->payer_name);

        $receipt = $this->paymentReceiptService->syncForPayment($payment);
        $payment->setAttribute('receipt', [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'delivery_status' => $receipt->delivery_status,
            'issued_to_email' => $receipt->issued_to_email,
        ]);

        return response()->json(['message' => 'Payment recorded', 'data' => $payment], 201);
    }

    private function validateStaffPaymentScope($user, int $clubId, array $validated, ?PaymentConcept $concept, bool $isInitial, bool $isEventBundle, bool $isMember, bool $isStaff)
    {
        if ($isInitial) {
            return response()->json(['message' => 'El personal no puede registrar saldo inicial.'], 403);
        }

        if (empty($validated['payment_concept_id']) && !$isEventBundle) {
            return response()->json(['errors' => ['payment_concept_id' => ['El personal solo puede registrar pagos sobre conceptos existentes.']]], 422);
        }

        if (!$isMember || $isStaff) {
            return response()->json(['errors' => ['member_id' => ['El personal solo puede recibir pagos de miembros asignados.']]], 422);
        }

        $staffRecord = Staff::query()
            ->where('club_id', $clubId)
            ->where('user_id', $user->id)
            ->with('classes:id')
            ->first();

        if (!$staffRecord) {
            return response()->json(['message' => 'No se encontró un perfil de staff válido para registrar pagos.'], 403);
        }

        $assignedClassIds = collect([$staffRecord->assigned_class])
            ->merge($staffRecord->classes->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $allowedMemberIds = collect();
        foreach ($assignedClassIds as $assignedClassId) {
            $allowedMemberIds = $allowedMemberIds->merge(
                ClubHelper::membersByClubAndClass($clubId, (int) $assignedClassId)->pluck('member_id')
            );
        }

        $allowedMemberIds = $allowedMemberIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if (!$allowedMemberIds->contains((int) $validated['member_id'])) {
            return response()->json(['errors' => ['member_id' => ['Solo puedes registrar pagos de miembros de tu clase asignada.']]], 422);
        }

        if ($concept) {
            $allowedScope = $concept->scopes()
                ->whereNull('deleted_at')
                ->where(function ($query) use ($clubId, $assignedClassIds) {
                    $query->where(function ($clubWide) use ($clubId) {
                        $clubWide->where('scope_type', 'club_wide')->where('club_id', $clubId);
                    });

                    if ($assignedClassIds->isNotEmpty()) {
                        $query->orWhere(function ($classScope) use ($assignedClassIds) {
                            $classScope->where('scope_type', 'class')->whereIn('class_id', $assignedClassIds);
                        });
                    }
                })
                ->exists();

            if (!$allowedScope) {
                return response()->json(['errors' => ['payment_concept_id' => ['Ese concepto no está disponible para tu clase o alcance de club.']]], 422);
            }
        }

        return null;
    }

    private function assertRequiredEventComponentsSelected(Collection $selectedConcepts, ?int $memberId, ?int $staffId, ?Payment $excludingPayment = null): void
    {
        $first = $selectedConcepts->first();
        if (!$first?->event_id || !$first?->club_id) {
            return;
        }

        $selectedIds = $selectedConcepts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requiredConcepts = PaymentConcept::query()
            ->where('event_id', (int) $first->event_id)
            ->where('club_id', (int) $first->club_id)
            ->where('status', 'active')
            ->whereHas('eventFeeComponent', fn ($query) => $query->where('is_required', true))
            ->with(['eventFeeComponent:id,label,amount,is_required,sort_order', 'scopes'])
            ->get()
            ->filter(fn (PaymentConcept $concept) => $this->conceptAppliesToPayer($concept, $memberId, $staffId))
            ->values();

        if ($requiredConcepts->isEmpty()) {
            return;
        }

        $paidTotals = $this->paidTotalsForConceptsAndPayer(
            $requiredConcepts->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $memberId,
            $staffId,
            $excludingPayment?->id
        );

        $missingRequired = $requiredConcepts->contains(function (PaymentConcept $concept) use ($selectedIds, $paidTotals) {
            $remaining = max(round((float) $concept->amount - (float) ($paidTotals[(int) $concept->id] ?? 0), 2), 0);

            return $remaining > 0.0001 && !in_array((int) $concept->id, $selectedIds, true);
        });

        if ($missingRequired) {
            throw ValidationException::withMessages([
                'event_concept_ids' => ['Incluye los componentes obligatorios del evento antes de cobrar opcionales.'],
            ]);
        }
    }

    private function eventBundlePaymentPlan(Collection $concepts, ?int $memberId, ?int $staffId, float $requestedAmount, ?Payment $excludingPayment = null): array
    {
        if ($concepts->isEmpty()) {
            throw ValidationException::withMessages(['event_concept_ids' => ['Selecciona al menos un componente del evento.']]);
        }

        foreach ($concepts as $concept) {
            if (!$this->conceptAppliesToPayer($concept, $memberId, $staffId)) {
                throw ValidationException::withMessages(['event_concept_ids' => ['Uno de los componentes seleccionados no aplica al pagador.']]);
            }
        }

        $paidTotals = $this->paidTotalsForConceptsAndPayer(
            $concepts->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $memberId,
            $staffId,
            $excludingPayment?->id
        );

        $remainingRows = $concepts
            ->map(function (PaymentConcept $concept) use ($paidTotals) {
                $expected = round((float) ($concept->amount ?? 0), 2);
                $priorPaid = round((float) ($paidTotals[(int) $concept->id] ?? 0), 2);

                return [
                    'concept' => $concept,
                    'expected' => $expected,
                    'is_required' => (bool) ($concept->eventFeeComponent?->is_required ?? true),
                    'sort_order' => (int) ($concept->eventFeeComponent?->sort_order ?? 0),
                    'prior_paid' => $priorPaid,
                    'remaining' => max($expected - $priorPaid, 0),
                ];
            })
            ->filter(fn (array $row) => $row['expected'] > 0)
            ->sortBy([
                fn (array $row) => $row['is_required'] ? 0 : 1,
                fn (array $row) => $row['sort_order'],
                fn (array $row) => (int) $row['concept']->id,
            ])
            ->values();

        $remainingBefore = round($remainingRows->sum(fn (array $row) => (float) $row['remaining']), 2);
        if ($remainingBefore <= 0.0001) {
            throw ValidationException::withMessages(['event_concept_ids' => ['Los componentes seleccionados ya están pagados para este pagador.']]);
        }

        $amountPaid = round(min($requestedAmount, $remainingBefore), 2);
        if ($amountPaid <= 0.0001) {
            throw ValidationException::withMessages(['amount_paid' => ['El monto debe ser mayor que 0.']]);
        }

        $toAllocate = $amountPaid;
        $allocationRows = [];
        foreach ($remainingRows as $row) {
            if ($toAllocate <= 0.0001) {
                break;
            }

            $allocated = round(min((float) $row['remaining'], $toAllocate), 2);
            if ($allocated <= 0.0001) {
                continue;
            }

            $concept = $row['concept'];
            $allocationRows[] = [
                'payment_concept_id' => (int) $concept->id,
                'event_fee_component_id' => $concept->event_fee_component_id ? (int) $concept->event_fee_component_id : null,
                'amount' => $allocated,
            ];
            $toAllocate = round($toAllocate - $allocated, 2);
        }

        $expected = round($remainingRows->sum(fn (array $row) => (float) $row['expected']), 2);

        return [
            'expected' => $expected,
            'amount_paid' => $amountPaid,
            'balance_after' => max(round($remainingBefore - $amountPaid, 2), 0),
            'allocations' => $allocationRows,
        ];
    }

    private function conceptAppliesToPayer(PaymentConcept $concept, ?int $memberId, ?int $staffId): bool
    {
        if (!$memberId && !$staffId) {
            return false;
        }

        $concept->loadMissing('scopes');

        if ($memberId) {
            $member = Member::query()->find($memberId);
            if (!$member) {
                return false;
            }

            return $concept->scopes->contains(function ($scope) use ($concept, $member, $memberId) {
                return match ($scope->scope_type) {
                    'member' => (int) $scope->member_id === (int) $memberId,
                    'class' => (int) $scope->class_id === (int) $member->class_id,
                    'club_wide' => (int) ($scope->club_id ?: $concept->club_id) === (int) $member->club_id,
                    default => false,
                };
            });
        }

        $staff = Staff::query()->find($staffId);
        if (!$staff) {
            return false;
        }

        return $concept->scopes->contains(function ($scope) use ($concept, $staff, $staffId) {
            return match ($scope->scope_type) {
                'staff' => (int) $scope->staff_id === (int) $staffId,
                'staff_wide' => (int) ($scope->club_id ?: $concept->club_id) === (int) $staff->club_id,
                default => false,
            };
        });
    }

    private function paidTotalsForConceptsAndPayer(array $conceptIds, ?int $memberId, ?int $staffId, ?int $excludingPaymentId = null): array
    {
        $conceptIds = collect($conceptIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($conceptIds->isEmpty()) {
            return [];
        }

        $directRows = Payment::query()
            ->whereIn('payment_concept_id', $conceptIds->all())
            ->whereDoesntHave('allocations')
            ->when($excludingPaymentId, fn ($query) => $query->where('id', '!=', $excludingPaymentId))
            ->when($memberId, fn ($query) => $query->where('member_id', $memberId))
            ->when($staffId, fn ($query) => $query->where('staff_id', $staffId))
            ->selectRaw('payment_concept_id, COALESCE(SUM(amount_paid), 0) as total_paid')
            ->groupBy('payment_concept_id')
            ->pluck('total_paid', 'payment_concept_id')
            ->map(fn ($amount) => (float) $amount);

        $allocationRows = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payments.deleted_at')
            ->whereIn('payment_allocations.payment_concept_id', $conceptIds->all())
            ->when($excludingPaymentId, fn ($query) => $query->where('payments.id', '!=', $excludingPaymentId))
            ->when($memberId, fn ($query) => $query->where('payments.member_id', $memberId))
            ->when($staffId, fn ($query) => $query->where('payments.staff_id', $staffId))
            ->selectRaw('payment_allocations.payment_concept_id, COALESCE(SUM(payment_allocations.amount), 0) as total_paid')
            ->groupBy('payment_allocations.payment_concept_id')
            ->pluck('total_paid', 'payment_concept_id')
            ->map(fn ($amount) => (float) $amount);

        $totals = [];
        foreach ($directRows as $conceptId => $amount) {
            $totals[(int) $conceptId] = round(($totals[(int) $conceptId] ?? 0) + (float) $amount, 2);
        }
        foreach ($allocationRows as $conceptId => $amount) {
            $totals[(int) $conceptId] = round(($totals[(int) $conceptId] ?? 0) + (float) $amount, 2);
        }

        return $totals;
    }
}
