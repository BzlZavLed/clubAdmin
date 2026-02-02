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
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Barryvdh\DomPDF\Facade\Pdf;
use Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use App\Support\ClubHelper;
use Illuminate\Support\Str;
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

        // Allow selecting club via query param
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubs = Club::where('user_id', $user->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name']);


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

        // --- Catalogs: Pay-To Options (from accounts) ---
        $payToOptions = Account::query()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'pay_to as value', 'label', 'club_id'])
            ->values();





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
        $staffColumns = ['id', 'name', 'email', 'club_id'];
        if (Schema::hasColumn('staff_adventurers', 'assigned_class')) {
            $staffColumns[] = 'assigned_class';
        }

        $staff = StaffAdventurer::query()
            ->where('club_id', $club->id)
            ->orderBy('name')
            ->get($staffColumns)
            ->map(function ($s) {
                if (!isset($s->assigned_class)) {
                    $s->assigned_class = null;
                    $s->class_warning = 'Staff class assignment missing';
                }
                return $s;
            });

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'club' => ['id' => $club->id, 'club_name' => $club->club_name],
                'club_id' => $club->id,
                'clubs' => $clubs,
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

    /**
     * Resolve a club that belongs to the user, optionally by explicit id.
     */
    protected function resolveClubForUser($user, $clubId = null): Club
    {
        $query = Club::where('user_id', $user->id);
        if ($clubId) {
            $query->where('id', $clubId);
        }

        $club = $query->first();

        if (!$club) {
            $club = Club::where('user_id', $user->id)->firstOrFail();
        }

        return $club;
    }

    public function financialReport(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubId = $club->id;

        // Base validation (shared)
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['concept', 'scope', 'account', 'date', 'member'])],
            'concept_id' => ['nullable', 'integer', Rule::exists('payment_concepts', 'id')->where(fn($q) => $q->where('club_id', $clubId))],
            'scope_type' => ['nullable', 'string'],
            'scope_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'paginate' => ['sometimes', 'boolean'],   // optional: to enable pagination
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $mode = $validated['mode'];
        $paginate = (bool) ($validated['paginate'] ?? false);
        $perPage = (int) ($validated['per_page'] ?? 100);
        $payTo = $validated['pay_to'] ?? null;
        if ($payTo) {
            $exists = Account::query()
                ->where('club_id', $club->id)
                ->where('pay_to', $payTo)
                ->exists();
            if (!$exists) {
                return response()->json(['message' => 'Cuenta invalida.'], 422);
            }
        }


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
                        'member:id,type,id_data',
                        'staff:id,type,id_data,user_id',
                        'staff.user:id,name',
                        'concept:id,concept,amount',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');
                if ($payTo) {
                    $q->where('pay_to', $payTo);
                }

                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $q->whereBetween('payment_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $q->whereDate('payment_date', $validated['date']);
                }

                if ($paginate) {
                    $page = $q->paginate($perPage);
                    $rows = $this->attachPaymentPayerNames(collect($page->items()));
                    $page->setCollection($rows);
                } else {
                    $rows = $this->attachPaymentPayerNames($q->get());
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

            case 'account': {
                $conceptId = $validated['concept_id'] ?? null;

                $paymentsQ = Payment::query()
                    ->where('club_id', $club->id)
                    ->with([
                        'member:id,type,id_data',
                        'staff:id,type,id_data,user_id',
                        'staff.user:id,name',
                        'concept:id,concept,amount',
                        'account:id,club_id,pay_to,label',
                        'receivedBy:id,name',
                    ]);

                if ($payTo) {
                    $paymentsQ->where('pay_to', $payTo);
                }
                if ($conceptId) {
                    $paymentsQ->where('payment_concept_id', $conceptId);
                }

                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $paymentsQ->whereBetween('payment_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $paymentsQ->whereDate('payment_date', $validated['date']);
                }

                $payments = $this->attachPaymentPayerNames($paymentsQ->get());

                $expensesQ = Expense::query()
                    ->where('club_id', $club->id);

                if ($payTo) {
                    $expensesQ->where('pay_to', $payTo);
                }
                if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                    $from = $validated['date_from'] ?? '1900-01-01';
                    $to = $validated['date_to'] ?? '2999-12-31';
                    $expensesQ->whereBetween('expense_date', [$from, $to]);
                } elseif (!empty($validated['date'])) {
                    $expensesQ->whereDate('expense_date', $validated['date']);
                }

                $expenses = $expensesQ->get([
                    'id',
                    'pay_to',
                    'amount',
                    'expense_date',
                    'description',
                    'status',
                    'reimbursed_to',
                ]);

                $accountLabels = Account::query()
                    ->where('club_id', $club->id)
                    ->get(['pay_to', 'label'])
                    ->mapWithKeys(fn($a) => [$a->pay_to => $a->label])
                    ->all();

                $entriesByAccount = [];

                foreach ($payments as $p) {
                    $key = $p->pay_to ?? $p->account?->pay_to ?? 'unassigned';
                    $entriesByAccount[$key][] = [
                        'entry_type' => 'payment',
                        'id' => $p->id,
                        'date' => $p->payment_date,
                        'amount' => (float) $p->amount_paid,
                        'payment_type' => $p->payment_type,
                        'concept' => $p->concept?->concept ?? $p->concept_text ?? '—',
                        'member' => $p->member?->applicant_name ?? null,
                        'staff' => $p->staff?->name ?? null,
                    ];
                }

                foreach ($expenses as $e) {
                    $key = $e->pay_to ?? 'unassigned';
                    $entriesByAccount[$key][] = [
                        'entry_type' => 'expense',
                        'id' => $e->id,
                        'date' => $e->expense_date,
                        'amount' => (float) $e->amount,
                        'payment_type' => null,
                        'concept' => $e->description ?? '—',
                        'member' => null,
                        'staff' => $e->reimbursed_to,
                    ];
                }

                $accountsReport = collect($entriesByAccount)->map(function ($entries, $payToKey) use ($accountLabels) {
                    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));
                    $paid = array_sum(array_map(fn($e) => $e['entry_type'] === 'payment' ? $e['amount'] : 0, $entries));
                    $spent = array_sum(array_map(fn($e) => $e['entry_type'] === 'expense' ? $e['amount'] : 0, $entries));
                    return [
                        'pay_to' => $payToKey,
                        'label' => $accountLabels[$payToKey] ?? ($payToKey === 'unassigned' ? 'Cuenta sin asignar' : $payToKey),
                        'totals' => [
                            'paid' => $paid,
                            'spent' => $spent,
                            'net' => $paid - $spent,
                        ],
                        'entries' => $entries,
                    ];
                })->values();

                return response()->json([
                    'data' => [
                        'mode' => 'account',
                        'account' => $payTo ? ['pay_to' => $payTo] : null,
                        'accounts' => $accountsReport,
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
                        'member:id,type,id_data',
                        'staff:id,type,id_data,user_id',
                        'staff.user:id,name',
                        'receivedBy:id,name',
                    ])
                    ->orderBy('payment_date')->orderBy('id');
                if ($payTo) {
                    $paymentsQ->where('pay_to', $payTo);
                }

                if ($from || $to) {
                    $paymentsQ->whereBetween('payment_date', [$from ?? '1900-01-01', $to ?? '2999-12-31']);
                }

                $paymentsByConcept = $this->attachPaymentPayerNames($paymentsQ->get())->groupBy('payment_concept_id');

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

    protected function attachPaymentPayerNames(Collection $rows): Collection
    {
        return $rows->map(function ($p) {
            $memberDetail = ClubHelper::memberDetail($p->member);
            $staffDetail = ClubHelper::staffDetail($p->staff);
            $memberName = $memberDetail['name'] ?? null;
            $staffName = $staffDetail['name'] ?? ($p->staff?->user?->name ?? null);

            $p->setRelation('member', $p->member ? [
                'id' => $p->member->id,
                'applicant_name' => $memberName ?? '—',
            ] : null);

            $p->setRelation('staff', $p->staff ? [
                'id' => $p->staff->id,
                'name' => $staffName ?? '—',
            ] : null);

            return $p;
        });
    }

    protected function buildSummaryFromRows(Collection $rows): array
    {
        $totalPaid = (float) $rows->sum('amount_paid');

        $groups = $rows->groupBy(function ($p) {
            $payerKey = $p->member_id
                ? ('m:' . $p->member_id)
                : ('s:' . $p->staff_id);
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

        foreach (['cash', 'zelle', 'check', 'initial'] as $t) {
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

    public function financialAccountBalancesPdf(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $data = $this->buildAccountReportData($club);

        $pdf = Pdf::loadView('reports.account_balances', [
            'club' => $club,
            'accounts' => $data['accounts'],
            'payments' => $data['payments'],
            'expenses' => $data['expenses'],
            'receipts' => $data['receipts'] ?? [],
        ])->setPaper('a4', 'landscape');

        return $pdf->download('account-balances.pdf');
    }

    public function financialAccountBalances(Request $request)
    {
        $user = $request->user();
        $club = $this->resolveClubForUser($user, $request->input('club_id'));
        $clubs = Club::where('user_id', $user->id)
            ->orderBy('club_name')
            ->get(['id', 'club_name']);

        $data = $this->buildAccountReportData($club);
        $data['club_id'] = $club->id;
        $data['clubs'] = $clubs;

        return response()->json(['data' => $data]);
    }

    protected function buildAccountReportData(Club $club): array
    {
        // Fetch label map for pay_to from accounts
        $payToLabelMap = Account::query()
            ->where('club_id', $club->id)
            ->get(['pay_to', 'label'])
            ->mapWithKeys(fn($a) => [$a->pay_to => $a->label])
            ->all();

        // Sum payments by pay_to from payments
        $entries = Payment::query()
            ->where('payments.club_id', $club->id)
            ->selectRaw('payments.pay_to as account, COALESCE(SUM(payments.amount_paid), 0) as entries')
            ->groupBy('payments.pay_to')
            ->get()
            ->map(function ($row) use ($payToLabelMap) {
                $account = $row->account ?? 'unassigned';
                $entries = (float) $row->entries;
                return [
                    'account' => $account,
                    'label' => $payToLabelMap[$account] ?? ($account === 'unassigned' ? 'Cuenta sin asignar' : $account),
                    'entries' => $entries,
                    'expenses' => 0.0,
                    'balance' => $entries,
                ];
            })
            ->values();

        // Sum expenses by pay_to
        $expensesByAccount = Expense::query()
            ->where('club_id', $club->id)
            ->selectRaw('pay_to as account, COALESCE(SUM(amount),0) as expenses')
            ->groupBy('pay_to')
            ->pluck('expenses', 'account');

        // Merge expenses into entries collection
        $entries = $entries->map(function ($acc) use ($expensesByAccount) {
            $exp = (float) ($expensesByAccount[$acc['account']] ?? 0);
            $acc['expenses'] = $exp;
            $acc['balance'] = ($acc['entries'] ?? 0) - $exp;
            return $acc;
        });

        // Detailed payment rows for income table
        $paymentReceiptCounter = 1;
        $payments = Payment::query()
            ->where('payments.club_id', $club->id)
            ->leftJoin('payment_concepts', 'payment_concepts.id', '=', 'payments.payment_concept_id')
            ->leftJoin('accounts as acc', 'acc.id', '=', 'payments.account_id')
            ->with([
                'member:id,type,id_data',
                'staff:id,type,id_data,user_id',
                'staff.user:id,name',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('payments.id')
            ->get([
                'payments.id',
                'payments.payment_date',
                'payments.amount_paid',
                'payments.payment_type',
                'payments.member_id',
                'payments.staff_id',
                'payments.payment_concept_id',
                'payments.check_image_path',
                'payments.concept_text',
                DB::raw('COALESCE(payments.pay_to, acc.pay_to) as account'),
                'acc.label as account_label',
                'payment_concepts.concept as concept_name',
            ])
            ->map(function ($p) use ($payToLabelMap, &$paymentReceiptCounter) {
                $ref = null;
                $url = null;
                if ($p->check_image_path) {
                    $ref = 'P' . $paymentReceiptCounter++;
                    $url = $this->toPublicUrl($p->check_image_path);
                }
                return [
                    'id' => $p->id,
                    'payment_date' => $p->payment_date,
                    'amount_paid' => (float) $p->amount_paid,
                    'payment_type' => $p->payment_type,
                    'account' => $p->account ?? 'unassigned',
                    'account_label' => $p->account_label ?? $payToLabelMap[$p->account] ?? (($p->account ?? null) === 'unassigned' ? 'Cuenta sin asignar' : ($p->account ?? 'Cuenta sin asignar')),
                    'concept' => $p->concept_name ?? $p->concept_text ?? '—',
                    'member' => $p->member ? ['id' => $p->member->id, 'applicant_name' => (ClubHelper::memberDetail($p->member)['name'] ?? '—')] : null,
                    'staff' => $p->staff ? ['id' => $p->staff->id, 'name' => (ClubHelper::staffDetail($p->staff)['name'] ?? ($p->staff->user?->name ?? '—'))] : null,
                    'receipt_path' => $p->check_image_path,
                    'receipt_ref' => $ref,
                    'receipt_url' => $url,
                ];
            })
            ->values();

        $expenses = Expense::query()
            ->where('club_id', $club->id)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get(['id', 'pay_to', 'amount', 'expense_date', 'description', 'reimbursed_to', 'status', 'receipt_path'])
            ->values();

        // Assign receipt references and map to DTOs
        $expenseReceiptCounter = 1;
        $expenseRows = $expenses->map(function ($e) use ($payToLabelMap, &$expenseReceiptCounter) {
            $ref = null;
            if ($e->receipt_path) {
                $ref = 'R' . $expenseReceiptCounter++;
            }
            return [
                'id' => $e->id,
                'pay_to' => $e->pay_to,
                'pay_to_label' => $payToLabelMap[$e->pay_to] ?? $e->pay_to,
                'amount' => (float) $e->amount,
                'expense_date' => $e->expense_date,
                'description' => $e->description,
                'reimbursed_to' => $e->reimbursed_to,
                'status' => $e->status,
                'receipt_path' => $e->receipt_path,
                'receipt_ref' => $ref,
                'receipt_url' => $e->receipt_url ?? null,
            ];
        });

        $buildAnnex = function ($ref, $path, $id, $labelPrefix) {
            $fullPath = storage_path('app/public/' . ltrim($path, '/'));
            $dataUri = null;
            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath) ?: 'image/jpeg';
                $data = base64_encode(file_get_contents($fullPath));
                $dataUri = "data:$mime;base64,$data";
            }
            return [
                'ref' => $ref,
                'source' => $labelPrefix,
                'record_id' => $id,
                'data_uri' => $dataUri,
                'filename' => basename($path),
            ];
        };

        $receiptAnnexes = collect();
        $expenseRows->filter(fn($e) => $e['receipt_path'])->each(function ($e) use (&$receiptAnnexes, $buildAnnex) {
            $receiptAnnexes->push($buildAnnex($e['receipt_ref'], $e['receipt_path'], $e['id'], 'Expense'));
        });

        $payments->filter(fn($p) => $p['receipt_path'])->each(function ($p) use (&$receiptAnnexes, $buildAnnex) {
            $receiptAnnexes->push($buildAnnex($p['receipt_ref'], $p['receipt_path'], $p['id'], 'Payment'));
        });

        return [
            'accounts' => $entries,
            'payments' => $payments,
            'expenses' => $expenseRows,
            'receipts' => $receiptAnnexes->values(),
        ];
    }

    protected function toPublicUrl(?string $path): ?string
    {
        if (!$path) return null;

        $url = Storage::disk('public')->url($path);
        $host = request()?->getSchemeAndHttpHost();

        if (Str::startsWith($url, ['http://', 'https://'])) {
            if (!$host) return $url;
            $parsed = parse_url($url);
            $combined = rtrim($host, '/');
            $combined .= $parsed['path'] ?? '';
            if (!empty($parsed['query'])) {
                $combined .= '?' . $parsed['query'];
            }
            return $combined;
        }

        $url = Str::start($url, '/');
        return $host ? rtrim($host, '/') . $url : url($url);
    }
}
