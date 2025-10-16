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
use Illuminate\Support\Collection;
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

        // Base validation (shared)
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['concept', 'scope', 'date', 'member'])],
            'concept_id' => ['nullable', 'integer', 'exists:payment_concepts,id'],
            'scope_type' => ['nullable', 'string'],
            'scope_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'paginate' => ['sometimes', 'boolean'],   // optional: to enable pagination
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $mode = $validated['mode'];
        $paginate = (bool) ($validated['paginate'] ?? false);
        $perPage = (int) ($validated['per_page'] ?? 100);


        switch ($mode) {

            case 'concept': {
                // Ensure concept belongs to this club (and optionally active)
                $concept = PaymentConcept::query()
                    ->where('id', $validated['concept_id'] ?? 0)
                    ->where('club_id', $club->id)
                    // ->where('status', 'active')  // uncomment if you enforce active here
                    ->firstOrFail();

                $q = Payment::query()
                    ->where('club_id', $club->id)
                    ->where('payment_concept_id', $concept->id)
                    ->with([
                        'member:id,applicant_name',
                        'staff:id,name',
                        'concept:id,concept,amount',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');

                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $q->whereBetween('payment_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $q->whereDate('payment_date', $validated['date']);
                }

                if ($paginate) {
                    $page = $q->paginate($perPage);
                    $rows = collect($page->items());
                } else {
                    $rows = $q->get();
                    $page = null;
                }

                $summary = $this->buildSummaryFromRows($rows);

                return response()->json([
                    'data' => [
                        'mode' => 'concept',
                        'concept' => ['id' => $concept->id, 'concept' => $concept->concept, 'amount' => $concept->amount, 'payment_expected_by' => $concept->payment_expected_by],
                        'payments' => $paginate ? $page : $rows,
                        'summary' => $summary,
                    ]
                ]);
            }

            case 'scope': {
                // Extra validation for scope mode
                $request->validate([
                    'scope_type' => ['required', Rule::in(['club_wide', 'class', 'member', 'staff_wide', 'staff'])],
                    'scope_id' => ['nullable', 'integer'],
                ]);

                $scopeType = $validated['scope_type'];
                $scopeId = $validated['scope_id'] ?? null;
                $from = $validated['date_from'] ?? null;
                $to = $validated['date_to'] ?? null;

                // Normalize staff_wide → staff + staff_all=true rows
                $normalizedType = $scopeType === 'staff_wide' ? 'staff' : $scopeType;

                $baseScopeQ = PaymentConceptScope::query()
                    ->whereHas('concept', fn($q) => $q->where('club_id', $club->id)->where('status', 'active'))
                    ->where('scope_type', $normalizedType);

                switch ($normalizedType) {
                    case 'club_wide':
                        $baseScopeQ->where('club_id', $club->id);
                        break;

                    case 'class':
                        $scopeId ? $baseScopeQ->where('class_id', $scopeId)
                            : $baseScopeQ->whereNotNull('class_id');
                        break;

                    case 'member':
                        $scopeId ? $baseScopeQ->where('member_id', $scopeId)
                            : $baseScopeQ->whereNotNull('member_id');
                        break;

                    case 'staff':
                        if ($scopeId) {
                            // include staff-wide for this club OR specific staff
                            $baseScopeQ->where(function ($q) use ($club, $scopeId) {
                                $q->where(function ($qq) use ($club) {
                                    $qq->where('staff_all', true)
                                        ->where('club_id', $club->id);
                                })
                                    ->orWhere(function ($qq) use ($scopeId) {
                                        $qq->where('staff_all', false)
                                            ->where('staff_id', $scopeId);
                                    });
                            });
                        } else {
                            // Only staff-wide (club-level) if no staff chosen
                            $baseScopeQ->where('staff_all', true)->where('club_id', $club->id);
                        }
                        break;
                }

                $scopeRows = $baseScopeQ
                    ->with([
                        'concept:id,concept,amount,payment_expected_by,type,club_id',
                        'club:id,club_name',
                        'class:id,class_name',
                        'member:id,applicant_name',
                        'staff:id,name',
                    ])
                    ->orderBy('id')
                    ->get(['id', 'payment_concept_id', 'scope_type', 'club_id', 'class_id', 'member_id', 'staff_id', 'staff_all']);

                if ($scopeRows->isEmpty()) {
                    return response()->json([
                        'data' => [
                            'mode' => 'scope',
                            'scope' => ['type' => $scopeType, 'id' => $scopeId],
                            'scopes' => [],
                        ]
                    ]);
                }

                // Group by scope identity (e.g., class|<id>, staff_all|<club>, staff|<id>, etc.)
                $identityKey = function ($s) {
                    return match ($s->scope_type) {
                        'club_wide' => "club|{$s->club_id}",
                        'class' => "class|{$s->class_id}",
                        'member' => "member|{$s->member_id}",
                        'staff' => $s->staff_all ? "staff_all|{$s->club_id}" : "staff|{$s->staff_id}",
                        default => "{$s->scope_type}|{$s->id}",
                    };
                };

                $identityLabel = function ($s) {
                    return match ($s->scope_type) {
                        'club_wide' => 'Club wide' . ($s->club?->club_name ? " ({$s->club->club_name})" : ''),
                        'class' => 'Class: ' . ($s->class?->class_name ?? $s->class_id),
                        'member' => 'Member: ' . ($s->member?->applicant_name ?? $s->member_id),
                        'staff' => $s->staff_all
                        ? ('Staff wide' . ($s->club?->club_name ? " ({$s->club->club_name})" : ''))
                        : ('Staff: ' . ($s->staff?->name ?? $s->staff_id)),
                        default => ucfirst($s->scope_type),
                    };
                };

                $byIdentity = $scopeRows->groupBy(fn($s) => $identityKey($s));

                $allConceptIds = $scopeRows->pluck('payment_concept_id')->unique()->values();

                $concepts = PaymentConcept::query()
                    ->whereIn('id', $allConceptIds)
                    ->get(['id', 'concept', 'amount', 'payment_expected_by', 'type', 'club_id'])
                    ->keyBy('id');

                $paymentsQ = Payment::query()
                    ->where('club_id', $club->id)
                    ->whereIn('payment_concept_id', $allConceptIds)
                    ->with([
                        'member:id,applicant_name',
                        'staff:id,name',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');

                if ($from || $to) {
                    $paymentsQ->whereBetween('payment_date', [$from ?? '1900-01-01', $to ?? '2999-12-31']);
                }

                $paymentsByConcept = $paymentsQ->get()->groupBy('payment_concept_id');

                $scopeBlocks = $byIdentity->map(function ($rowsForIdentity) use ($identityKey, $identityLabel, $paymentsByConcept, $concepts) {

                    $conceptIds = $rowsForIdentity->pluck('payment_concept_id')->unique()->values();

                    $conceptReports = $conceptIds->map(function ($cid) use ($paymentsByConcept, $concepts, $identityKey) {
                        $rows = ($paymentsByConcept->get($cid) ?? collect())->values();

                        return [
                            'concept' => [
                                'id' => $cid,
                                'concept' => $concepts[$cid]->concept ?? '—',
                                'amount' => $concepts[$cid]->amount ?? null,
                                'payment_expected_by' => $concepts[$cid]->payment_expected_by ?? null,
                                'type' => $concepts[$cid]->type ?? null,
                            ],
                            'payments' => $rows,
                            'summary' => $this->buildSummaryFromRows($rows)
                        ];
                    })->values();

                    // Roll-up summary per scope identity
                    $scopeSummary = (function ($conceptReports) {
                        $acc = [
                            'payments_count' => 0,
                            'charges_count' => 0,
                            'amount_paid_sum' => 0.0,
                            'expected_sum' => 0.0,
                            'balance_remaining' => 0.0,
                            'by_payment_type' => ['cash' => 0.0, 'zelle' => 0.0, 'check' => 0.0],
                        ];
                        foreach ($conceptReports as $cr) {
                            $s = $cr['summary'];
                            $acc['payments_count'] += (int) ($s['payments_count'] ?? 0);
                            $acc['charges_count'] += (int) ($s['charges_count'] ?? 0);
                            $acc['amount_paid_sum'] += (float) ($s['amount_paid_sum'] ?? 0);
                            $acc['expected_sum'] += (float) ($s['expected_sum'] ?? 0);
                            $acc['balance_remaining'] += (float) ($s['balance_remaining'] ?? 0);
                            foreach (['cash', 'zelle', 'check'] as $t) {
                                $acc['by_payment_type'][$t] += (float) ($s['by_payment_type'][$t] ?? 0);
                            }
                        }
                        return $acc;
                    })($conceptReports->all());

                    $first = $rowsForIdentity->first();

                    return [
                        'scope' => [
                            'identity_key' => $identityKey($first),
                            'type' => $first->scope_type,
                            'label' => $identityLabel($first),
                            'club' => $first->club ? ['id' => $first->club->id, 'club_name' => $first->club->club_name] : null,
                            'class' => $first->class ? ['id' => $first->class->id, 'class_name' => $first->class->class_name] : null,
                            'member' => $first->member ? ['id' => $first->member->id, 'applicant_name' => $first->member->applicant_name] : null,
                            'staff' => $first->staff_all ? null : ($first->staff ? ['id' => $first->staff->id, 'name' => $first->staff->name] : null),
                            'staff_all' => (bool) $first->staff_all,
                        ],
                        'concepts' => $conceptReports,
                        'summary' => $scopeSummary,
                    ];
                })->values();

                return response()->json([
                    'data' => [
                        'mode' => 'scope',
                        'scope' => ['type' => $scopeType, 'id' => $scopeId],
                        'scopes' => $scopeBlocks,
                    ]
                ]);
            }

            default:
                return response()->json(['message' => 'Mode not implemented yet'], 400);
        }
    }

    protected function buildSummaryFromRows(Collection $rows): array
    {
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

        $byType = $rows->groupBy('payment_type')
            ->mapWithKeys(fn($g, $t) => [$t => (float) $g->sum('amount_paid')])
            ->all();

        foreach (['cash', 'zelle', 'check'] as $t) {
            $byType[$t] = (float) ($byType[$t] ?? 0.0);
        }

        return [
            'payments_count' => $rows->count(),
            'charges_count' => $chargeSummaries->count(),
            'amount_paid_sum' => $totalPaid,
            'expected_sum' => (float) $chargeSummaries->sum('expected'),
            'balance_remaining' => (float) $chargeSummaries->sum('remaining'),
            'by_payment_type' => $byType,
        ];
    }

}
