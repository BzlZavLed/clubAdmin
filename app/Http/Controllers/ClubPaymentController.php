<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id']);

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
     * POST /club-personal/payments
     * Create a payment by club-personal (for now: member payments only)
     */
    public function store(Request $request)
    {
        $club = $this->resolveClubFromUser();
        $this->assertClubAccess($club);

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

        // Load concept (must belong to this club and be allowed)
        $concept = PaymentConcept::query()
            ->where('id', $validated['payment_concept_id'])
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->firstOrFail();

        $expected = $concept->amount !== null ? (float) $concept->amount : 0.0;

        // Sum prior paid for this (concept, payer) pair (exclude soft-deleted)
        $priorPaidQuery = Payment::query()
            ->where('club_id', $club->id)
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
        DB::transaction(function () use ($club, $concept, $validated, $expected, $amountPaid, $balanceAfter, $zellePhone, $checkImagePath, &$payment) {
            $payment = Payment::create([
                'club_id' => $club->id,
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

