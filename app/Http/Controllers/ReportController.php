<?php

namespace App\Http\Controllers;

use App\Models\RepAssistanceAdv;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use App\Models\Club;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\MemberAdventurer;
use App\Models\StaffAdventurer;
use App\Models\ClubClass;
use App\Models\ScopeType;
use App\Models\PayToOption;
use App\Models\Payment;
use Log;
use Illuminate\Validation\Rule;
class ReportController extends Controller
{
    public function generateAssistancePDF($id, $date)
    {
        try {
            $parsedDate = Carbon::parse($date)->toDateString();

            $report = RepAssistanceAdv::with(['merits', 'staff', 'club'])
                ->where('id', $id)
                ->whereDate('date', $parsedDate)
                ->firstOrFail();

            return response()->json($report); // ✅ return raw JSON
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Report not found or failed.',
                'error_details' => $e->getMessage(),
            ], 404);
        }
    }

    public function assistanceReportsDirector(Request $request)
    {
        $request->validate([
            'report_type' => 'required|string',
            'club_id' => 'required|integer|exists:clubs,id',
        ]);

        $query = RepAssistanceAdv::query()
            ->where('club_id', $request->club_id);

        $with = ['staff', 'club'];

        switch ($request->report_type) {
            case 'date':
                $request->validate(['date' => 'required|date']);
                $query->whereDate('date', $request->date);
                $with[] = 'merits';
                break;

            case 'range':
                $request->validate([
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after_or_equal:start_date',
                ]);
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
                $with[] = 'merits';
                break;

            case 'class':
                $request->validate(['class_id' => 'required|integer']);
                $query->where('class_id', $request->class_id);
                $with[] = 'merits';
                break;

            case 'member':
                $request->validate(['member_id' => 'required|integer']);

                $query->whereHas('merits', function ($q) use ($request) {
                    $q->where('mem_adv_id', $request->member_id);
                });

                $with['merits'] = function ($q) use ($request) {
                    $q->where('mem_adv_id', $request->member_id);
                };
                break;

            default:
                return response()->json(['message' => 'Invalid report type'], 400);
        }

        $reports = $query->with($with)->get();


        return response()->json(['reports' => $reports], 200);
    }

    public function financialReportPreload(Request $request)
    {
        $user = $request->user();

        // Resolve the active club for this user (adapt to your app’s logic)
        $club = $this->resolveClubFromUser($user);


        // --- Catalogs: Scope Types ---
        $clubScopeTypes = ScopeType::active()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $globalScopeTypes = ScopeType::active()
            ->whereNull('club_id')
            ->whereNotIn('value', $clubScopeTypes->pluck('value'))
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $scopeTypes = $clubScopeTypes->concat($globalScopeTypes)->values();

        // --- Catalogs: Pay-To Options ---
        $clubPayTo = PayToOption::active()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $globalPayTo = PayToOption::active()
            ->whereNull('club_id')
            ->whereNotIn('value', $clubPayTo->pluck('value'))
            ->orderBy('label')
            ->get(['id', 'value', 'label', 'club_id', 'status']);

        $payToOptions = $clubPayTo->concat($globalPayTo)->values();





        $concepts = PaymentConcept::query()
            ->where('club_id', $club->id)
            //->where('status', 'active')
            ->with([
                'scopes' => function ($q) {
                    $q->whereNull('deleted_at')
                        ->with(['club:id,club_name', 'class:id,class_name']);
                }
            ])
            ->orderBy('concept')
            ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id']);

        $scopes = PaymentConceptScope::query()
            ->whereNull('deleted_at')
            ->whereHas('concept', fn($q) => $q->where('club_id', $club->id))
            ->with([
                'club:id,club_name',
                'class:id,class_name',
                'concept:id,concept,club_id'
            ])
            ->orderBy('scope_type')
            ->get(['id', 'payment_concept_id', 'scope_type', 'club_id', 'class_id', 'member_id', 'staff_id']);

        // Members for this club
        $members = MemberAdventurer::query()
            ->where('club_id', $club->id)
            ->with([
                'clubClasses' => function ($q) {
                    $q->wherePivot('active', true)
                        ->select('club_classes.id', 'club_classes.class_name'); // columns from related table
                },
            ])
            ->orderBy('applicant_name')
            ->get(['id', 'applicant_name', 'club_id'])
            ->map(function ($m) {
                $current = $m->clubClasses->first(); // the active one (if any)
                return [
                    'id' => $m->id,
                    'applicant_name' => $m->applicant_name,
                    'club_id' => $m->club_id,
                    'current_class' => $current ? [
                        'id' => $current->id,
                        'class_name' => $current->class_name,
                    ] : null,
                ];
            })
            ->values();

        // Classes for this club
        $classes = ClubClass::query()
            ->where('club_id', $club->id)
            ->orderBy('class_name')
            ->get(['id', 'class_name', 'club_id']);

        // Staff for this club
        $staff = StaffAdventurer::query()
            ->where('club_id', $club->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'assigned_class', 'club_id']);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                'concepts' => $concepts,
                'scopes' => $scopes,
                'members' => $members,
                'classes' => $classes,
                'staff' => $staff,
                'scope_types' => $scopeTypes,
                'pay_to' => $payToOptions,
            ]
        ]);
    }

    /**
     * Resolve the active club from session or user. Adjust to your app’s logic.
     */
    protected function resolveClubFromUser($user): Club
    {
        return Club::where('id', $user->club_id)->firstOrFail();
    }

    public function financialReport(Request $request)
    {
        $user = $request->user();
        $clubId = $user->club_id;
        $club = Club::findOrFail($clubId);

        // Base validation + shared date rules
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['concept', 'scope', 'date', 'member'])],
            'concept_id' => ['nullable', 'integer', 'exists:payment_concepts,id'],
            'scope_type' => ['nullable', 'string'],
            'scope_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],                 // single date
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $mode = $validated['mode'];

        switch ($mode) {
            case 'concept':
                // Require a concept that belongs to this club & is active
                $concept = PaymentConcept::query()
                    ->where('id', $validated['concept_id'] ?? 0)
                    ->where('club_id', $club->id)
                    ->firstOrFail();

                $q = Payment::query()
                    ->where('club_id', $club->id)
                    ->where('payment_concept_id', $concept->id)
                    ->with([
                        'member:id,applicant_name',
                        'staff:id,name',
                        'concept:id,concept,amount',
                        'receivedBy:id,name',
                    ]);

                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $q->whereBetween('payment_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $q->whereDate('payment_date', $validated['date']);
                }

                // Chronological
                $q->orderBy('payment_date')->orderBy('id');

                // If you expect many rows, paginate (uncomment):
                $results = $q->paginate(100);

                $rows = $q->get();

                // Basic totals
                $totalPaid = (float) $rows->sum('amount_paid');

                // Group by "charge" (unique payer × concept)
                $groups = $rows->groupBy(function ($p) {
                    $payerKey = $p->member_adventurer_id
                        ? ('m:' . $p->member_adventurer_id)
                        : ('s:' . $p->staff_adventurer_id);
                    return $payerKey . '|c:' . $p->payment_concept_id;
                });

                $chargeSummaries = $groups->map(function ($paymentsForCharge) {
                    $expected = (float) $paymentsForCharge->max('expected_amount');
                    $paid = (float) $paymentsForCharge->sum('amount_paid');
                    $remaining = max($expected - $paid, 0.0);

                    return [
                        'expected' => $expected,
                        'paid' => $paid,
                        'remaining' => $remaining,
                    ];
                });

                $totalExpected = (float) $chargeSummaries->sum('expected');
                $totalRemaining = (float) $chargeSummaries->sum('remaining');
                $paymentsCount = $rows->count();
                $chargesCount = $chargeSummaries->count();

                // --- 🧾 Payment type classification ---
                $paymentTypeTotals = $rows
                    ->groupBy('payment_type')
                    ->mapWithKeys(function ($group, $type) {
                        return [$type => (float) $group->sum('amount_paid')];
                    });

                // Ensure all known types exist in summary even if 0
                $knownTypes = ['cash', 'zelle', 'check'];
                foreach ($knownTypes as $type) {
                    if (!isset($paymentTypeTotals[$type])) {
                        $paymentTypeTotals[$type] = 0.0;
                    }
                }

                // --- ✅ Final summary ---
                $summary = [
                    'payments_count' => $paymentsCount,
                    'charges_count' => $chargesCount,
                    'amount_paid_sum' => $totalPaid,
                    'expected_sum' => $totalExpected,
                    'balance_remaining' => $totalRemaining,
                    'by_payment_type' => $paymentTypeTotals,
                ];

                return response()->json([
                    'data' => [
                        'mode' => 'concept',
                        'concept' => ['id' => $concept->id, 'concept' => $concept->concept],
                        'payments' => $rows,
                        'summary' => $summary,
                    ]
                ]);

            // You’ll implement these next:
            case 'scope': {
                // Validate scope params
                $request->validate([
                    'scope_type' => ['required', Rule::in(['club_wide', 'class', 'member', 'staff_wide', 'staff'])],
                    'scope_id' => ['nullable', 'integer'], // required for some types below
                    'date_from' => ['nullable', 'date'],
                    'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                ]);

                $scopeType = $request->input('scope_type');
                $scopeId = $request->input('scope_id');

                // For these types, scope_id is required
                if (in_array($scopeType, ['class', 'member', 'staff']) && empty($scopeId)) {
                    return response()->json(['message' => 'scope_id is required for the selected scope_type.'], 422);
                }

                // Build base scope filter
                $scopeQ = PaymentConceptScope::query()
                    ->whereHas('concept', fn($q) => $q->where('club_id', $club->id)->where('status', 'active'))
                    ->where('scope_type', $scopeType);

                switch ($scopeType) {
                    case 'club_wide':
                        // club_wide: use current club
                        $scopeQ->where('club_id', $club->id);
                        break;
                    case 'class':
                        $scopeQ->where('class_id', $scopeId);
                        break;
                    case 'member':
                        $scopeQ->where('member_id', $scopeId);
                        break;
                    case 'staff_wide':
                        // staff_wide tied to the club (like club_wide but for staff)
                        $scopeQ->where('club_id', $club->id);
                        break;
                    case 'staff':
                        $scopeQ->where('staff_id', $scopeId);
                        break;
                }

                // Get unique concept ids for this scope
                $conceptIds = $scopeQ->pluck('payment_concept_id')->unique()->values();

                if ($conceptIds->isEmpty()) {
                    return response()->json([
                        'data' => [
                            'mode' => 'scope',
                            'scope' => ['type' => $scopeType, 'id' => $scopeId],
                            'concepts' => [],   // nothing to show
                        ]
                    ]);
                }

                // Optional date filters
                $from = $request->input('date_from');
                $to = $request->input('date_to');

                // Fetch concepts (for labels, amounts, due dates)
                $concepts = PaymentConcept::query()
                    ->whereIn('id', $conceptIds)
                    ->orderBy('concept')
                    ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id']);

                // For each concept, pull payments + build per-concept summary
                $conceptReports = $concepts->map(function ($concept) use ($club, $from, $to) {
                    $q = Payment::query()
                        ->where('club_id', $club->id)
                        ->where('payment_concept_id', $concept->id)
                        ->with([
                            'member:id,applicant_name',
                            'staff:id,name',
                            'concept:id,concept,amount',
                            'receivedBy:id,name',
                        ]);

                    if ($from || $to) {
                        $q->whereBetween('payment_date', [$from ?? '1900-01-01', $to ?? '2999-12-31']);
                    }

                    $q->orderBy('payment_date')->orderBy('id');
                    $rows = $q->get();

                    // --- per-concept summary that de-duplicates charges (payer x concept) ---
                    $totalPaid = (float) $rows->sum('amount_paid');

                    $groups = $rows->groupBy(function ($p) {
                        $payerKey = $p->member_adventurer_id
                            ? ('m:' . $p->member_adventurer_id)
                            : ('s:' . $p->staff_adventurer_id);
                        return $payerKey . '|c:' . $p->payment_concept_id;
                    });

                    $chargeSummaries = $groups->map(function ($paymentsForCharge) {
                        $expected = (float) $paymentsForCharge->max('expected_amount');
                        $paid = (float) $paymentsForCharge->sum('amount_paid');
                        $remaining = max($expected - $paid, 0.0);
                        return ['expected' => $expected, 'paid' => $paid, 'remaining' => $remaining];
                    });

                    $totalExpected = (float) $chargeSummaries->sum('expected');
                    $totalRemaining = (float) $chargeSummaries->sum('remaining');
                    $paymentsCount = $rows->count();
                    $chargesCount = $chargeSummaries->count();

                    // breakdown by payment type for this concept
                    $paymentTypeTotals = $rows
                        ->groupBy('payment_type')
                        ->mapWithKeys(fn($g, $type) => [$type => (float) $g->sum('amount_paid')]);

                    foreach (['cash', 'zelle', 'check'] as $type) {
                        if (!isset($paymentTypeTotals[$type]))
                            $paymentTypeTotals[$type] = 0.0;
                    }

                    $summary = [
                        'payments_count' => $paymentsCount,
                        'charges_count' => $chargesCount,
                        'amount_paid_sum' => $totalPaid,
                        'expected_sum' => $totalExpected,
                        'balance_remaining' => $totalRemaining,
                        'by_payment_type' => $paymentTypeTotals,
                    ];

                    return [
                        'concept' => [
                            'id' => $concept->id,
                            'concept' => $concept->concept,
                            'amount' => $concept->amount,
                            'payment_expected_by' => $concept->payment_expected_by,
                            'type' => $concept->type,
                        ],
                        'payments' => $rows,
                        'summary' => $summary,
                    ];
                })->values();

                return response()->json([
                    'data' => [
                        'mode' => 'scope',
                        'scope' => ['type' => $scopeType, 'id' => $scopeId],
                        'concepts' => $conceptReports, // array => one element per tab in the UI
                    ]
                ]);
            }
            case 'date':
            case 'member':
            default:
                return response()->json([
                    'message' => 'Report mode not implemented yet.'
                ], 422);
        }
    }
}
