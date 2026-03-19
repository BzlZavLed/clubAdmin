<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Account;
use App\Models\ClubClass;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\Staff;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class ClubPaymentController extends Controller
{
    // Utility: ensure user can access club
    protected function assertClubAccess(Club $club)
    {
        // Replace with your actual authorization logic
        // e.g., Gate::authorize('manage-club', $club);
        if (!auth()->check())
            abort(403);
    }

    /**
     * GET /club-personal/payments
     * Load page data: members of club + allowed concepts + (optional) recent payments
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $club = ClubHelper::clubForUser($user, $request->input('club_id'));
        $this->assertClubAccess($club);

        $staff = Staff::query()
            ->where('club_id', $club->id)
            ->whereHas('user', function ($q) use ($user) {
                $q->whereRaw('LOWER(email) = ?', [Str::lower($user->email)]);
            })
            ->with(['classes'])
            ->first();

        $assignedClassId = $staff?->assigned_class ?: (int)optional($staff?->classes?->first())->id;
        $assignedClass = $assignedClassId ? ClubClass::find($assignedClassId) : null;

        // Members dropdown source of truth: members table filtered by club+class
        $assignedMembers = ClubHelper::getMembersByClassAndClub((int)$club->id, (int)$assignedClassId)
            ->map(function ($m) {
                return [
                    // Use members.id as the value for payments
                    'id' => $m['member_id'],
                    'applicant_name' => $m['applicant_name'],
                    'member_type' => $m['member_type'],
                    'id_data' => $m['id_data'],
                    'class_id' => $m['class_id'],
                ];
            })
            ->values();
        $assignedMemberIds = $assignedMembers->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();

        $concepts = PaymentConcept::query()
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->whereHas('scopes', function ($q) use ($assignedClassId) {
                $q->whereNull('deleted_at')
                    ->where(function ($q) use ($assignedClassId) {
                        $q->where('scope_type', 'club_wide');
                        if ($assignedClassId) {
                            $q->orWhere(function ($qq) use ($assignedClassId) {
                                $qq->where('scope_type', 'class')
                                    ->whereNotNull('class_id')
                                    ->where('class_id', $assignedClassId);
                            });
                        }
                    });
            })
            ->with([
                'scopes' => function ($q) use ($assignedClassId) {
                    $q->whereNull('deleted_at')
                        ->where(function ($q) use ($assignedClassId) {
                            $q->where('scope_type', 'club_wide');

                            if ($assignedClassId) {
                                $q->orWhere(function ($qq) use ($assignedClassId) {
                                    $qq->where('scope_type', 'class')
                                        ->whereNotNull('class_id')
                                        ->where('class_id', $assignedClassId);
                                });
                            }
                        })
                        ->with(['club:id,club_name', 'class:id,class_name']);
                },
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id']);

        $recent = Payment::query()
            ->where('club_id', $club->id)
            ->whereNotNull('member_id')
            ->when(
                !empty($assignedMemberIds),
                fn ($q) => $q->whereIn('member_id', $assignedMemberIds),
                fn ($q) => $q->whereRaw('1 = 0')
            )
            ->latest()
            ->take(25)
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept,amount',
                'account:id,club_id,pay_to,label',
                'receivedBy:id,name',
            ])
            ->get()
            ->map(function ($p) {
                $member = ClubHelper::memberDetail($p->member);
                $staff = ClubHelper::staffDetail($p->staff);
                return [
                    'id' => $p->id,
                    'club_id' => $p->club_id,
                    'payment_concept_id' => $p->payment_concept_id,
                    'concept_text' => $p->concept_text,
                    'pay_to' => $p->pay_to,
                    'account_label' => $p->account?->label,
                    'member_id' => $p->member_id,
                    'staff_id' => $p->staff_id,
                    'amount_paid' => $p->amount_paid,
                    'expected_amount' => $p->expected_amount,
                    'balance_due_after' => $p->balance_due_after,
                    'payment_date' => $p->payment_date,
                    'payment_type' => $p->payment_type,
                    'zelle_phone' => $p->zelle_phone,
                    'check_image_path' => $p->check_image_path,
                    'received_by_user_id' => $p->received_by_user_id,
                    'notes' => $p->notes,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                    'member_display_name' => $member['name'] ?? null,
                    'staff_display_name' => $staff['name'] ?? null,
                    'concept' => $p->concept ? [
                        'id' => $p->concept->id,
                        'concept' => $p->concept->concept,
                        'amount' => $p->concept->amount,
                    ] : null,
                    'received_by' => $p->receivedBy ? [
                        'id' => $p->receivedBy->id,
                        'name' => $p->receivedBy->name,
                    ] : null,
                ];
            });

        $completedPaymentTargets = $this->completedPaymentTargets($concepts);
        $paymentTotals = $this->paymentTotalsByConceptTarget($concepts);

        // If you still want to serve JSON to API/XHR callers:
        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                    'members' => $assignedMembers,
                    'assigned_class' => $assignedClass ? ['id' => $assignedClass->id, 'name' => $assignedClass->class_name] : null,
                    'concepts' => $concepts,
                    'payments' => $recent,
                    'completed_payment_targets' => $completedPaymentTargets,
                    'payment_totals' => $paymentTotals,
                    'payment_types' => ['zelle', 'cash', 'check', 'initial'],
                ]
            ]);
        }


        // Inertia response (point to your page component)
        return Inertia::render('ClubPersonal/Payments', [
            'auth_user' => ['id' => $user->id, 'name' => $user->name, 'profile_type' => $user->profile_type],
            'user' => $user,
            'clubs' => Club::all(['id', 'club_name']),
            'staff' => $staff ?? [],
            'club' => ['id' => $club->id, 'club_name' => $club->club_name],
            'members' => $assignedMembers,
            'assigned_class' => $assignedClass ? ['id' => $assignedClass->id, 'name' => $assignedClass->class_name] : null,
            'assigned_members' => $assignedMembers,
            'concepts' => $concepts,
            'payments' => $recent,
            'completed_payment_targets' => $completedPaymentTargets,
            'payment_totals' => $paymentTotals,
            'payment_types' => ['zelle', 'cash', 'check', 'initial'],
            'prefill' => $request->only(['club_id', 'concept_id', 'member_id', 'staff_id', 'amount']),
        ]);
    }

    /**
     * GET /club-director/payments
     * Directors can access all club members/staff and concepts.
     */
    public function directorIndex(Request $request)
    {
        $user = $request->user();
        $clubIds = ClubHelper::clubIdsForUser($user);
        $club = ClubHelper::clubForUser($user, $request->input('club_id'));
        $this->assertClubAccess($club);

        $clubsForUser = Club::whereIn('id', $clubIds)->orderBy('club_name')->get(['id', 'club_name']);

        $members = ClubHelper::membersOfClub((int)$club->id)
            ->map(function ($m) {
                return [
                    'id' => $m['member_id'],
                    'applicant_name' => $m['applicant_name'],
                    'club_id' => $m['club_id'],
                    'class_id' => $m['class_id'],
                    'member_type' => $m['member_type'],
                    'id_data' => $m['id_data'],
                ];
            })
            ->values();

        $staff = ClubHelper::staffOfClub((int)$club->id)
            ->loadMissing('user:id,name,email')
            ->map(function ($s) {
                $detail = ClubHelper::staffDetail($s);
                return [
                    'id' => $s->id,
                    'name' => $detail['name'] ?? $s->user?->name,
                    'email' => $s->user?->email,
                    'club_id' => $s->club_id,
                    'status' => $s->status,
                ];
            })
            ->values();

        $concepts = PaymentConcept::query()
            ->whereIn('club_id', $clubIds)
            ->where('status', 'active')
            ->with([
                'scopes' => function ($q) {
                    $q->whereNull('deleted_at')
                        ->with(['club:id,club_name', 'class:id,class_name', 'member:id,applicant_name', 'staff:id,name']);
                },
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id']);

        $accounts = Account::query()
            ->whereIn('club_id', $clubIds)
            ->orderBy('label')
            ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);

        $recent = Payment::query()
            ->whereIn('club_id', $clubIds)
            ->latest()
            ->take(50)
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
                'concept:id,concept,amount',
                'account:id,club_id,pay_to,label',
                'receivedBy:id,name',
            ])
            ->get()
            ->map(function ($p) {
                $member = ClubHelper::memberDetail($p->member);
                $staff = ClubHelper::staffDetail($p->staff);
                return [
                    'id' => $p->id,
                    'club_id' => $p->club_id,
                    'payment_concept_id' => $p->payment_concept_id,
                    'concept_text' => $p->concept_text,
                    'pay_to' => $p->pay_to,
                    'account_label' => $p->account?->label,
                    'member_id' => $p->member_id,
                    'staff_id' => $p->staff_id,
                    'amount_paid' => $p->amount_paid,
                    'expected_amount' => $p->expected_amount,
                    'balance_due_after' => $p->balance_due_after,
                    'payment_date' => $p->payment_date,
                    'payment_type' => $p->payment_type,
                    'zelle_phone' => $p->zelle_phone,
                    'check_image_path' => $p->check_image_path,
                    'received_by_user_id' => $p->received_by_user_id,
                    'notes' => $p->notes,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                    'member_display_name' => $member['name'] ?? null,
                    'staff_display_name' => $staff['name'] ?? null,
                    'concept' => $p->concept ? [
                        'id' => $p->concept->id,
                        'concept' => $p->concept->concept,
                        'amount' => $p->concept->amount,
                    ] : null,
                    'received_by' => $p->receivedBy ? [
                        'id' => $p->receivedBy->id,
                        'name' => $p->receivedBy->name,
                    ] : null,
                ];
            });

        $completedPaymentTargets = $this->completedPaymentTargets($concepts);
        $paymentTotals = $this->paymentTotalsByConceptTarget($concepts);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                    'clubs' => $clubsForUser,
                    'members' => $members,
                    'staff' => $staff,
                    'concepts' => $concepts,
                    'accounts' => $accounts,
                    'payments' => $recent,
                    'completed_payment_targets' => $completedPaymentTargets,
                    'payment_totals' => $paymentTotals,
                    'payment_types' => ['zelle', 'cash', 'check', 'initial'],
                ]
            ]);
        }

        return Inertia::render('ClubDirector/Payments', [
            'auth_user' => ['id' => $user->id, 'name' => $user->name, 'profile_type' => $user->profile_type],
            'user' => $user,
            'club' => ['id' => $club->id, 'club_name' => $club->club_name],
            'clubs' => $clubsForUser,
            'members' => $members,
            'staff' => $staff,
            'concepts' => $concepts,
            'accounts' => $accounts,
            'payments' => $recent,
            'completed_payment_targets' => $completedPaymentTargets,
            'payment_totals' => $paymentTotals,
            'payment_types' => ['zelle', 'cash', 'check', 'initial'],
            'prefill' => $request->only(['club_id', 'concept_id', 'member_id', 'staff_id', 'amount']),
        ]);
    }


    /**
     * POST /club-personal/payments
     * Create a payment by club-personal (for now: member payments only)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'payment_concept_id' => ['nullable', 'integer', 'exists:payment_concepts,id'],
            'concept_text' => ['nullable', 'string', 'max:255', 'required_without:payment_concept_id'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in(['zelle', 'cash', 'check', 'initial'])],
            'zelle_phone' => ['nullable', 'string', 'max:32'],
            'check_image' => ['nullable', 'image', 'max:4096'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $isInitial = $validated['payment_type'] === 'initial';
        if ($isInitial && !in_array($user?->profile_type, ['club_director', 'superadmin'], true)) {
            return response()->json(['message' => 'Saldo inicial solo puede ser registrado por director o superadmin.'], 403);
        }

        // exactly one payer (unless initial balance)
        $isMember = !empty($validated['member_id']);
        $isStaff = !empty($validated['staff_id']);
        if (!$isInitial && $isMember === $isStaff) {
            return response()->json(['message' => 'Provide exactly one payer: member OR staff.'], 422);
        }

        $allowedClubIds = ClubHelper::clubIdsForUser($user);

        $concept = null;
        $clubId = null;
        $expected = null;
        $payTo = null;
        $conceptText = $validated['concept_text'] ?? null;

        if (!empty($validated['payment_concept_id'])) {
            $concept = PaymentConcept::query()
                ->where('id', $validated['payment_concept_id'])
                ->where('status', 'active')
                ->firstOrFail();

            if (!$allowedClubIds->contains((int) $concept->club_id)) {
                abort(403, 'You cannot record payments for this club.');
            }

            $clubId = $concept->club_id;
            $expected = $concept->amount !== null ? (float) $concept->amount : 0.0;
            $payTo = $concept->pay_to ?? 'club_budget';
            $conceptText = null;
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

        $account = Account::query()
            ->where('club_id', $clubId)
            ->where('pay_to', $payTo)
            ->first();
        if (!$account) {
            $account = Account::create([
                'club_id' => $clubId,
                'pay_to' => $payTo,
                'label' => \Illuminate\Support\Str::title(str_replace('_', ' ', $payTo)),
                'balance' => 0,
            ]);
        }

        // Sum prior paid for this (concept, payer) pair (exclude soft-deleted)
        $priorPaidQuery = Payment::query()
            ->where('club_id', $clubId)
            ->when($concept, fn($q) => $q->where('payment_concept_id', $concept->id));

        if ($isMember) {
            $priorPaidQuery->where('member_id', $validated['member_id'] ?? null);
        } else {
            $priorPaidQuery->where('staff_id', $validated['staff_id'] ?? null);
        }

        if ($isInitial) {
            $priorPaid = 0.0;
        } else {
            $priorPaid = (float) ($priorPaidQuery->sum('amount_paid'));
        }

        $remainingBefore = $expected !== null ? max($expected - $priorPaid, 0.0) : null;

        if ($expected !== null && $expected > 0 && $remainingBefore !== null && $remainingBefore <= 0) {
            return response()->json([
                'errors' => [
                    'payment_concept_id' => ['Este concepto ya fue pagado completamente para este pagador.'],
                ],
            ], 422);
        }

        $amountPaid = (float) $validated['amount_paid'];
        if ($expected !== null && $expected > 0 && $remainingBefore !== null && $amountPaid > $remainingBefore) {
            $amountPaid = $remainingBefore;
        }

        $balanceAfter = $expected !== null ? max($expected - ($priorPaid + $amountPaid), 0.0) : null;

        if ($validated['payment_type'] === 'zelle' && empty($validated['zelle_phone'])) {
            return response()->json(['message' => 'Zelle payments require a phone number.'], 422);
        }
        $zellePhone = $validated['payment_type'] === 'zelle' ? $validated['zelle_phone'] : null;

        $checkImagePath = null;
        if ($validated['payment_type'] === 'check' && $request->hasFile('check_image')) {
            $checkImagePath = $request->file('check_image')->store('payments/checks', 'public');
        }

        $payment = null;
        DB::transaction(function () use ($clubId, $concept, $validated, $expected, $amountPaid, $balanceAfter, $zellePhone, $checkImagePath, $payTo, $conceptText, $account, &$payment) {
            $payment = Payment::create([
                'club_id' => $clubId,
                'payment_concept_id' => $concept?->id,
                'concept_text' => $conceptText,
                'pay_to' => $payTo,
                'account_id' => $account?->id,
                'member_id' => $validated['member_id'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
                'amount_paid' => $amountPaid,
                'expected_amount' => $expected,
                'balance_due_after' => $balanceAfter,
                'payment_date' => $validated['payment_date'],
                'payment_type' => $validated['payment_type'],
                'zelle_phone' => $zellePhone,
                'check_image_path' => $checkImagePath,
                'received_by_user_id' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update account balance
            $account = Account::firstOrCreate(
                ['club_id' => $clubId, 'pay_to' => $payTo],
                ['label' => $payTo, 'balance' => 0]
            );
            $account->increment('balance', $amountPaid);
        });

        $payment->load([
            'member:id,type,id_data',
            'staff:id,type,id_data,user_id',
            'staff.user:id,name',
            'concept:id,concept,amount',
            'receivedBy:id,name',
        ]);

        $detailMember = ClubHelper::memberDetail($payment->member);
        $detailStaff = ClubHelper::staffDetail($payment->staff);
        $payment->setAttribute('member_display_name', $detailMember['name'] ?? null);
        $payment->setAttribute('staff_display_name', $detailStaff['name'] ?? null);

        return response()->json(['message' => 'Payment recorded', 'data' => $payment], 201);
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user?->profile_type, ['club_director', 'superadmin'], true)) {
            return response()->json(['message' => 'Solo directores o superadmin pueden editar pagos registrados.'], 403);
        }

        $allowedClubIds = ClubHelper::clubIdsForUser($user);
        if (!$allowedClubIds->contains((int) $payment->club_id)) {
            abort(403, 'You cannot edit payments for this club.');
        }

        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in(['zelle', 'cash', 'check', 'initial'])],
            'zelle_phone' => ['nullable', 'string', 'max:32'],
            'check_image' => ['nullable', 'image', 'max:4096'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['payment_type'] === 'zelle' && empty($validated['zelle_phone'])) {
            return response()->json(['message' => 'Zelle payments require a phone number.'], 422);
        }

        $concept = $payment->payment_concept_id
            ? PaymentConcept::query()->find($payment->payment_concept_id)
            : null;

        $expected = $payment->expected_amount !== null ? (float) $payment->expected_amount : ($concept?->amount !== null ? (float) $concept->amount : null);
        $newAmount = (float) $validated['amount_paid'];

        if ($expected !== null && $expected > 0) {
            $otherPaid = Payment::query()
                ->where('club_id', $payment->club_id)
                ->where('payment_concept_id', $payment->payment_concept_id)
                ->where('id', '!=', $payment->id)
                ->when($payment->member_id, fn ($q) => $q->where('member_id', $payment->member_id))
                ->when($payment->staff_id, fn ($q) => $q->where('staff_id', $payment->staff_id))
                ->sum('amount_paid');

            $maxAllowed = max($expected - (float) $otherPaid, 0.0);
            if ($newAmount > $maxAllowed) {
                return response()->json([
                    'errors' => [
                        'amount_paid' => ['El monto excede el saldo pendiente para este concepto.'],
                    ],
                ], 422);
            }
        }

        $oldAmount = (float) $payment->amount_paid;
        $oldCheckImagePath = $payment->check_image_path;
        $zellePhone = $validated['payment_type'] === 'zelle' ? $validated['zelle_phone'] : null;
        $nextCheckImagePath = $payment->check_image_path;
        $deleteOldCheckImage = false;

        if ($validated['payment_type'] !== 'check') {
            $nextCheckImagePath = null;
            $deleteOldCheckImage = !empty($payment->check_image_path);
        } elseif ($request->hasFile('check_image')) {
            $nextCheckImagePath = $request->file('check_image')->store('payments/checks', 'public');
            $deleteOldCheckImage = !empty($payment->check_image_path) && $payment->check_image_path !== $nextCheckImagePath;
        }

        $account = $payment->account;
        if (!$account) {
            $account = Account::firstOrCreate(
                ['club_id' => $payment->club_id, 'pay_to' => $payment->pay_to ?: 'club_budget'],
                ['label' => Str::title(str_replace('_', ' ', $payment->pay_to ?: 'club_budget')), 'balance' => 0]
            );
        }

        DB::transaction(function () use ($payment, $validated, $newAmount, $zellePhone, $nextCheckImagePath, $oldAmount, $account, $expected) {
            $payment->fill([
                'amount_paid' => $newAmount,
                'payment_date' => $validated['payment_date'],
                'payment_type' => $validated['payment_type'],
                'zelle_phone' => $zellePhone,
                'check_image_path' => $nextCheckImagePath,
                'notes' => $validated['notes'] ?? null,
                'expected_amount' => $expected,
            ]);
            $payment->save();

            $delta = round($newAmount - $oldAmount, 2);
            if ($delta > 0) {
                $account->increment('balance', $delta);
            } elseif ($delta < 0) {
                $account->decrement('balance', abs($delta));
            }
        });

        if ($deleteOldCheckImage && $oldCheckImagePath) {
            try {
                Storage::disk('public')->delete($oldCheckImagePath);
            } catch (\Throwable $e) {
            }
        }

        $this->recalculatePaymentBalances($payment->fresh());

        $payment = $payment->fresh();
        $payment->load([
            'member:id,type,id_data',
            'staff:id,type,id_data,user_id',
            'staff.user:id,name',
            'concept:id,concept,amount',
            'account:id,club_id,pay_to,label',
            'receivedBy:id,name',
        ]);

        $detailMember = ClubHelper::memberDetail($payment->member);
        $detailStaff = ClubHelper::staffDetail($payment->staff);
        $payment->setAttribute('member_display_name', $detailMember['name'] ?? null);
        $payment->setAttribute('staff_display_name', $detailStaff['name'] ?? null);

        return response()->json(['message' => 'Payment updated', 'data' => $payment]);
    }

    public function destroy(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();
        if ($user?->profile_type !== 'club_director') {
            return response()->json(['message' => 'Solo el director puede eliminar pagos registrados.'], 403);
        }

        $allowedClubIds = ClubHelper::clubIdsForUser($user);
        if (!$allowedClubIds->contains((int) $payment->club_id)) {
            abort(403, 'You cannot delete payments for this club.');
        }

        $receiptPath = $payment->check_image_path;
        $account = $payment->account;
        $deletedPaymentSnapshot = clone $payment;

        DB::transaction(function () use ($payment, $account) {
            if ($account) {
                $account->decrement('balance', (float) $payment->amount_paid);
            }
            $payment->delete();
        });

        if ($receiptPath) {
            try {
                Storage::disk('public')->delete($receiptPath);
            } catch (\Throwable $e) {
            }
        }

        $this->recalculatePaymentBalances($deletedPaymentSnapshot);

        return response()->json(['message' => 'Payment deleted']);
    }


    /**
     * Helper to pull the active club from the session/user.
     * Replace with your actual logic (you mentioned session stores church/club).
     */
    protected function resolveClubFromUser(): Club
    {
        // Example:
        // $clubId = session('club_id') ?? auth()->user()->club_id;
        // return Club::findOrFail($clubId);

        // Placeholder: adjust to your app
        return Club::where('id', auth()->user()->club_id)->firstOrFail();
    }

    protected function recalculatePaymentBalances(Payment $payment): void
    {
        if (!$payment->payment_concept_id || $payment->expected_amount === null) {
            return;
        }

        $expected = (float) $payment->expected_amount;
        $runningPaid = 0.0;

        Payment::query()
            ->where('club_id', $payment->club_id)
            ->where('payment_concept_id', $payment->payment_concept_id)
            ->when($payment->member_id, fn ($q) => $q->where('member_id', $payment->member_id))
            ->when($payment->staff_id, fn ($q) => $q->where('staff_id', $payment->staff_id))
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get()
            ->each(function (Payment $row) use (&$runningPaid, $expected) {
                $runningPaid += (float) $row->amount_paid;
                $row->update([
                    'expected_amount' => $expected,
                    'balance_due_after' => max($expected - $runningPaid, 0.0),
                ]);
            });
    }

    protected function completedPaymentTargets(Collection $concepts): array
    {
        $expectedByConcept = $concepts
            ->filter(fn ($concept) => $concept->amount !== null && (float) $concept->amount > 0)
            ->mapWithKeys(fn ($concept) => [(int) $concept->id => (float) $concept->amount]);

        if ($expectedByConcept->isEmpty()) {
            return [];
        }

        return Payment::query()
            ->whereIn('payment_concept_id', $expectedByConcept->keys())
            ->selectRaw('payment_concept_id, member_id, staff_id, COALESCE(SUM(amount_paid), 0) as total_paid')
            ->groupBy('payment_concept_id', 'member_id', 'staff_id')
            ->get()
            ->filter(function ($row) use ($expectedByConcept) {
                $expected = (float) ($expectedByConcept[(int) $row->payment_concept_id] ?? 0);
                return $expected > 0 && (float) $row->total_paid >= $expected;
            })
            ->map(function ($row) {
                if ($row->member_id) {
                    return sprintf('%d|member|%d', $row->payment_concept_id, $row->member_id);
                }
                if ($row->staff_id) {
                    return sprintf('%d|staff|%d', $row->payment_concept_id, $row->staff_id);
                }
                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function paymentTotalsByConceptTarget(Collection $concepts): array
    {
        $conceptIds = $concepts->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        if ($conceptIds->isEmpty()) {
            return [];
        }

        return Payment::query()
            ->whereIn('payment_concept_id', $conceptIds)
            ->selectRaw('payment_concept_id, member_id, staff_id, COALESCE(SUM(amount_paid), 0) as total_paid')
            ->groupBy('payment_concept_id', 'member_id', 'staff_id')
            ->get()
            ->mapWithKeys(function ($row) {
                if ($row->member_id) {
                    return [sprintf('%d|member|%d', $row->payment_concept_id, $row->member_id) => (float) $row->total_paid];
                }
                if ($row->staff_id) {
                    return [sprintf('%d|staff|%d', $row->payment_concept_id, $row->staff_id) => (float) $row->total_paid];
                }
                return [];
            })
            ->all();
    }
}
