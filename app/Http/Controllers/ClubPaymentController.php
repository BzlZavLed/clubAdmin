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

        // If you still want to serve JSON to API/XHR callers:
        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                    'members' => $assignedMembers,
                    'assigned_class' => $assignedClass ? ['id' => $assignedClass->id, 'name' => $assignedClass->class_name] : null,
                    'concepts' => $concepts,
                    'payments' => $recent,
                    'payment_types' => ['zelle', 'cash', 'check', 'initial'],
                ]
            ]);
        }


        // Inertia response (point to your page component)
        return Inertia::render('ClubPersonal/Payments', [
            'auth_user' => ['id' => $user->id, 'name' => $user->name],
            'user' => $user,
            'clubs' => Club::all(['id', 'club_name']),
            'staff' => $staff ?? [],
            'club' => ['id' => $club->id, 'club_name' => $club->club_name],
            'members' => $assignedMembers,
            'assigned_class' => $assignedClass ? ['id' => $assignedClass->id, 'name' => $assignedClass->class_name] : null,
            'assigned_members' => $assignedMembers,
            'concepts' => $concepts,
            'payments' => $recent,
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
                    'payment_types' => ['zelle', 'cash', 'check', 'initial'],
                ]
            ]);
        }

        return Inertia::render('ClubDirector/Payments', [
            'auth_user' => ['id' => $user->id, 'name' => $user->name],
            'user' => $user,
            'club' => ['id' => $club->id, 'club_name' => $club->club_name],
            'clubs' => $clubsForUser,
            'members' => $members,
            'staff' => $staff,
            'concepts' => $concepts,
            'accounts' => $accounts,
            'payments' => $recent,
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
        if ($isInitial && $user?->profile_type !== 'club_director') {
            return response()->json(['message' => 'Saldo inicial solo puede ser registrado por el director.'], 403);
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
}
