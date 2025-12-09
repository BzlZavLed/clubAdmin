<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
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
        $club = $this->resolveClubFromUser();
        $this->assertClubAccess($club);

        // --- your existing queries ---
        $members = MemberAdventurer::query()
            ->where('club_id', $club->id)
            ->with([
                'clubClasses' => function ($q) {
                    $q->wherePivot('active', true);
                },
            ])
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id'])
            ->map(function ($m) {
                $current = $m->clubClasses->first();
                $classId = $current?->pivot?->club_class_id ?? $current?->id;
                return [
                    'id' => $m->id,
                    'applicant_name' => $m->applicant_name,
                    'club_id' => $m->club_id,
                    'class_id' => $classId,
                    'current_class' => $current ? [
                        'id' => $classId,
                        'class_name' => $current->class_name,
                    ] : null,
                ];
            })
            ->values();

        $staff = StaffAdventurer::query()
            ->where('club_id', $club->id)
            ->whereRaw('LOWER(email) = ?', [Str::lower($user->email)])
            ->first();

        $assignedClassId = (int)$staff->assigned_class;

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
                'member:id,applicant_name',
                'staff:id,name',
                'concept:id,concept,amount',
                'receivedBy:id,name',
            ])
            ->get();

        // If you still want to serve JSON to API/XHR callers:
        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                    'members' => $members,
                    'concepts' => $concepts,
                    'payments' => $recent,
                    'payment_types' => ['zelle', 'cash', 'check'],
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
            'members' => $members,
            'concepts' => $concepts,
            'payments' => $recent,
            'payment_types' => ['zelle', 'cash', 'check'],
        ]);
    }

    /**
     * GET /club-director/payments
     * Directors can access all club members/staff and concepts.
     */
    public function directorIndex(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubFromUser();
        $this->assertClubAccess($club);

        $clubsForUser = Club::where('user_id', $user->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name']);
        $clubIds = $clubsForUser->pluck('id');

        $members = MemberAdventurer::query()
            ->whereIn('club_id', $clubIds)
            ->with([
                'clubClasses' => function ($q) {
                    $q->wherePivot('active', true);
                },
            ])
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id'])
            ->map(function ($m) {
                $current = $m->clubClasses->first();
                $classId = $current?->pivot?->club_class_id ?? $current?->id;
                return [
                    'id' => $m->id,
                    'applicant_name' => $m->applicant_name,
                    'club_id' => $m->club_id,
                    'class_id' => $classId,
                    'current_class' => $current ? [
                        'id' => $classId,
                        'class_name' => $current->class_name,
                    ] : null,
                ];
            })
            ->values();

        $staffColumns = ['id', 'name', 'email', 'club_id'];
        // Some deployments may still lack the old assigned_class column on staff_adventurers
        if (\Illuminate\Support\Facades\Schema::hasColumn('staff_adventurers', 'assigned_class')) {
            $staffColumns[] = 'assigned_class';
        }

        $staff = StaffAdventurer::query()
            ->whereIn('club_id', $clubIds)
            ->orderBy('name')
            ->get($staffColumns)
            ->map(function ($s) {
                // If no class assignment available, surface a hint to the UI
                if (!isset($s->assigned_class)) {
                    $s->assigned_class = null;
                    $s->class_warning = 'Staff class assignment missing';
                }
                return $s;
            });

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

        $recent = Payment::query()
            ->whereIn('club_id', $clubIds)
            ->latest()
            ->take(50)
            ->with([
                'member:id,applicant_name',
                'staff:id,name',
                'concept:id,concept,amount',
                'receivedBy:id,name',
            ])
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                    'clubs' => $clubsForUser,
                    'members' => $members,
                    'staff' => $staff,
                    'concepts' => $concepts,
                    'payments' => $recent,
                    'payment_types' => ['zelle', 'cash', 'check'],
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
            'payments' => $recent,
            'payment_types' => ['zelle', 'cash', 'check'],
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
            'payment_concept_id' => ['required', 'integer', 'exists:payment_concepts,id'],
            'member_adventurer_id' => ['nullable', 'integer', 'exists:members_adventurers,id'],
            'staff_adventurer_id' => ['nullable', 'integer', 'exists:staff_adventurers,id'],
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in(['zelle', 'cash', 'check'])],
            'zelle_phone' => ['nullable', 'string', 'max:32'],
            'check_image' => ['nullable', 'image', 'max:4096'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // exactly one payer
        $isMember = !empty($validated['member_adventurer_id']);
        $isStaff = !empty($validated['staff_adventurer_id']);
        if ($isMember === $isStaff) {
            return response()->json(['message' => 'Provide exactly one payer: member OR staff.'], 422);
        }

        // Load concept (must belong to a club this user owns)
        $concept = PaymentConcept::query()
            ->where('id', $validated['payment_concept_id'])
            ->where('status', 'active')
            ->firstOrFail();

        $allowedClubIds = Club::where('user_id', $user->id)->pluck('id');
        if (!$allowedClubIds->contains($concept->club_id)) {
            abort(403, 'You cannot record payments for this club.');
        }

        $clubId = $concept->club_id;

        $expected = $concept->amount !== null ? (float) $concept->amount : 0.0;
        $payTo = $concept->pay_to ?? 'unassigned';

        // Sum prior paid for this (concept, payer) pair (exclude soft-deleted)
        $priorPaidQuery = Payment::query()
            ->where('club_id', $clubId)
            ->where('payment_concept_id', $concept->id);

        if ($isMember) {
            $priorPaidQuery->where('member_adventurer_id', $validated['member_adventurer_id']);
        } else {
            $priorPaidQuery->where('staff_adventurer_id', $validated['staff_adventurer_id']);
        }

        $priorPaid = (float) ($priorPaidQuery->sum('amount_paid'));

        // Remaining before this payment
        $remainingBefore = max($expected - $priorPaid, 0.0);

        // Cap the current amount so it never goes over remaining (or enforce error)
        $amountPaid = (float) $validated['amount_paid'];
        if ($expected > 0 && $amountPaid > $remainingBefore) {
            // You can either clamp or return an error; here we clamp:
            $amountPaid = $remainingBefore;
        }

        $balanceAfter = max($expected - ($priorPaid + $amountPaid), 0.0);

        if ($validated['payment_type'] === 'zelle' && empty($validated['zelle_phone'])) {
            return response()->json(['message' => 'Zelle payments require a phone number.'], 422);
        }
        $zellePhone = $validated['payment_type'] === 'zelle' ? $validated['zelle_phone'] : null;

        $checkImagePath = null;
        if ($validated['payment_type'] === 'check' && $request->hasFile('check_image')) {
            $checkImagePath = $request->file('check_image')->store('payments/checks', 'public');
        }

        $payment = null;
        DB::transaction(function () use ($clubId, $concept, $validated, $expected, $amountPaid, $balanceAfter, $zellePhone, $checkImagePath, $payTo, &$payment) {
            $payment = Payment::create([
                'club_id' => $clubId,
                'payment_concept_id' => $concept->id,
                'member_adventurer_id' => $validated['member_adventurer_id'] ?? null,
                'staff_adventurer_id' => $validated['staff_adventurer_id'] ?? null,
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
            'member:id,applicant_name',
            'staff:id,name',
            'concept:id,concept,amount',
            'receivedBy:id,name',
        ]);

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
