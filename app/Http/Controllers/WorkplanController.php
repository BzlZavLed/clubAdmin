<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Member;
use App\Models\Staff;
use App\Models\Workplan;
use App\Models\WorkplanEvent;
use App\Models\WorkplanRule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Log;
use Illuminate\Support\Facades\Auth;
use App\Support\ClubHelper;

class WorkplanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->load([
            'staff.classes',
            'staffClass',
            'clubs',
            'church',
        ]);
        $clubIds = ClubHelper::clubIdsForUser($user);
        $clubs = Club::whereIn('id', $clubIds)->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club workplan.');
        }

        $workplan = null;
        if ($selectedClubId) {
            $existing = $this->getWorkplanForUser($user, $selectedClubId, false);
            if ($existing) {
                $workplan = $existing->load([
                    'rules',
                    'events' => function ($query) {
                        $query->with(['classPlans' => function ($q) {
                            $q->with(['staff.user', 'class']);
                        }])->orderBy('date')->orderBy('start_time');
                    }
                ]);
            }
        }

        return Inertia::render('ClubDirector/Workplan', [
            'auth_user' => Auth::user(),
            'workplan' => $workplan,
            'clubs' => $clubs,
            'selected_club_id' => $selectedClubId,
        ]);
    }

    public function preview(Request $request)
    {
        $payload = $this->validatePayload($request);
        $workplan = $this->getWorkplanForUser($request->user(), null, false);

        $existingEvents = $workplan
            ? $workplan->events()->with('rule')->where('status', 'active')->get()
            : collect();
        $existingGenerated = $existingEvents->where('is_generated', true);

        $ruleMap = $this->buildRuleMap($payload['rules']);
        $targets = $this->buildTargets($payload['start_date'], $payload['end_date'], $ruleMap, $payload);

        $diff = $this->diffEvents($existingGenerated, $targets);

        return response()->json([
            'adds' => array_values($diff['adds']),
            'removals' => $diff['removals'],
        ]);
    }

    public function confirm(Request $request)
    {
        $payload = $this->validatePayload($request);
        $workplan = $this->getWorkplanForUser($request->user(), null, true);

        $workplan->fill([
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'default_sabbath_location' => $payload['default_sabbath_location'] ?? null,
            'default_sunday_location' => $payload['default_sunday_location'] ?? null,
            'default_sabbath_start_time' => $payload['default_sabbath_start_time'] ?? null,
            'default_sabbath_end_time' => $payload['default_sabbath_end_time'] ?? null,
            'default_sunday_start_time' => $payload['default_sunday_start_time'] ?? null,
            'default_sunday_end_time' => $payload['default_sunday_end_time'] ?? null,
            'timezone' => $payload['timezone'] ?? null,
        ])->save();

        $workplan->rules()->delete();
        $ruleIdMap = [];
        foreach ($payload['rules'] as $ruleData) {
            $rule = $workplan->rules()->create([
                'meeting_type' => $ruleData['meeting_type'],
                'nth_week' => $ruleData['nth_week'],
                'note' => $ruleData['note'] ?? null,
            ]);
            $ruleIdMap[$ruleData['meeting_type'] . ':' . $ruleData['nth_week']] = $rule->id;
        }

        $existingEvents = $workplan->events()->with('rule')->where('status', 'active')->get();
        $existingGenerated = $existingEvents->where('is_generated', true);

        $ruleMap = $this->buildRuleMap($payload['rules']);
        foreach ($ruleMap as &$rule) {
            $key = $rule['meeting_type'] . ':' . $rule['nth_week'];
            $rule['rule_id'] = $ruleIdMap[$key] ?? null;
        }

        $targets = $this->buildTargets($payload['start_date'], $payload['end_date'], $ruleMap, $payload);
        $diff = $this->diffEvents($existingGenerated, $targets);

        if (!empty($diff['removals'])) {
            WorkplanEvent::whereIn('id', $diff['removals'])->delete();
        }

        foreach ($diff['adds'] as $add) {
            $workplan->events()->create([
                'generated_from_rule_id' => $add['rule_id'] ?? null,
                'date' => $add['date'],
                'start_time' => $add['start_time'],
                'end_time' => $add['end_time'],
                'meeting_type' => $add['meeting_type'],
                'title' => $add['title'],
                'description' => $add['description'] ?? null,
                'location' => $add['location'],
                'is_generated' => true,
                'is_edited' => false,
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);
        }

        $workplan->refresh()->load(['rules', 'events' => function ($query) {
            $query->orderBy('date')->orderBy('start_time');
        }]);

        return response()->json([
            'workplan' => $workplan,
        ]);
    }

    public function storeEvent(Request $request)
    {
        $payload = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'meeting_type' => ['required', 'in:sabbath,sunday,special'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $workplan = $this->getWorkplanForUser($request->user());

        $event = $workplan->events()->create(array_merge($payload, [
            'is_generated' => false,
            'is_edited' => false,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return response()->json([
            'event' => $event,
        ]);
    }

    public function updateEvent(Request $request, WorkplanEvent $event)
    {
        $this->authorizeEvent($request->user(), $event);

        $payload = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'meeting_type' => ['required', 'in:sabbath,sunday,special'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $event->fill($payload);
        if ($event->is_generated) {
            $event->is_edited = true;
        }
        $event->save();

        return response()->json([
            'event' => $event->fresh(),
        ]);
    }

    public function destroyEvent(Request $request, WorkplanEvent $event)
    {
        $this->authorizeEvent($request->user(), $event);
        $event->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function data(Request $request)
    {
        $user = $request->user();
        $clubs = Club::whereIn('id', ClubHelper::clubIdsForUser($user))->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club workplan.');
        }

        $workplan = null;
        if ($selectedClubId) {
            $existing = $this->getWorkplanForUser($user, $selectedClubId, false);
            if ($existing) {
                $workplan = $existing->load([
                    'rules',
                    'events' => function ($query) {
                        $query->with(['classPlans' => function ($q) {
                            $q->with(['staff.user', 'class']);
                        }])->orderBy('date')->orderBy('start_time');
                    }
                ]);
            }
        }

        return response()->json([
            'clubs' => $clubs,
            'selected_club_id' => $selectedClubId,
            'workplan' => $this->filterPlansForParent($workplan, $user),
            'memberships' => [],
        ]);
    }

    private function filterPlansForParent($workplan, $user)
    {
        if (!$workplan || $user->profile_type !== 'parent') return $workplan;

        $members = \App\Models\Member::where('parent_id', $user->id)
            ->where('club_id', $workplan->club_id)
            ->get(['id', 'class_id']);

        if ($members->isEmpty()) return $workplan;

        $memberIds = $members->pluck('id')->all();
        $classIds = $members->pluck('class_id')->filter()->all();

        $workplan->events = $workplan->events->map(function ($ev) use ($memberIds, $classIds) {
            $ev->classPlans = $ev->classPlans->filter(function ($plan) use ($memberIds, $classIds) {
                if ($plan->member_id && in_array($plan->member_id, $memberIds)) return true;
                if ($plan->class_id && in_array($plan->class_id, $classIds)) return true;
                if ($plan->class && in_array($plan->class->id, $classIds)) return true;
                return false;
            })->values();
            return $ev;
        });

        return $workplan;
    }

    public function pdf(Request $request)
    {
        $user = $request->user();
        $clubs = Club::whereIn('id', ClubHelper::clubIdsForUser($user))->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club workplan.');
        }

        $workplan = $this->getWorkplanForUser($user, $selectedClubId, false);
        if (!$workplan) {
            abort(404, 'No workplan found for this club.');
        }
        $workplan = $workplan->load([
            'club',
            'events' => function ($q) {
                // Include every event (active, pending approval, special, etc.) so the PDF shows the full calendar.
                $q->with(['classPlans.class', 'classPlans.staff.user'])
                    ->orderBy('date')
                    ->orderBy('start_time');
            }
        ]);

        $start = Carbon::parse($request->input('start_date', $workplan->start_date))->startOfDay();
        $end = Carbon::parse($request->input('end_date', $workplan->end_date))->endOfDay();

        // Expand to include any single/special events that fall just outside the formal range.
        $eventMin = $workplan->events->min('date');
        $eventMax = $workplan->events->max('date');
        if ($eventMin && Carbon::parse($eventMin)->lt($start)) {
            $start = Carbon::parse($eventMin)->startOfDay();
        }
        if ($eventMax && Carbon::parse($eventMax)->gt($end)) {
            $end = Carbon::parse($eventMax)->endOfDay();
        }

        $months = [];
        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = [
                'label' => $cursor->format('F Y'),
                'year' => $cursor->year,
                'month' => $cursor->month,
            ];
            $cursor->addMonth()->startOfMonth();
        }

        $eventsByDate = [];
        foreach ($workplan->events as $ev) {
            $date = Carbon::parse($ev->date)->toDateString();
            if ($date < $start->toDateString() || $date > $end->toDateString()) {
                continue;
            }
            $eventsByDate[$date][] = $ev;
        }

        // Debug log to verify which events are going into the PDF export.
        try {
            Log::info('Workplan PDF events', [
                'user_id' => $user->id,
                'club_id' => $selectedClubId,
                'range' => [$start->toDateString(), $end->toDateString()],
                'events' => $workplan->events->map(function ($ev) {
                    return [
                        'id' => $ev->id,
                        'date' => $ev->date instanceof Carbon ? $ev->date->toDateString() : (string) $ev->date,
                        'meeting_type' => $ev->meeting_type,
                        'status' => $ev->status,
                        'title' => $ev->title,
                        'is_generated' => $ev->is_generated,
                    ];
                })->values(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log workplan PDF events', ['error' => $e->getMessage()]);
        }

        $pdf = Pdf::loadView('pdf.workplan', [
            'workplan' => $workplan,
            'months' => $months,
            'eventsByDate' => $eventsByDate,
            'start' => $start,
            'end' => $end,
        ])->setPaper('a4', 'portrait');

        $filename = 'workplan-' . ($workplan->club->club_name ?? 'club') . '.pdf';
        return $pdf->download($filename);
    }

    public function ics(Request $request)
    {
        $user = $request->user();
        $clubs = Club::whereIn('id', ClubHelper::clubIdsForUser($user))->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club workplan.');
        }

        $workplan = $this->getWorkplanForUser($user, $selectedClubId, false);
        if (!$workplan) {
            abort(404, 'No workplan found for this club.');
        }
        $workplan = $workplan->load('club', 'events');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//nadClubs//Workplan//EN',
            'CALSCALE:GREGORIAN',
        ];

        foreach ($workplan->events as $ev) {
            $startDate = Carbon::parse($ev->date)->format('Ymd');
            $uid = 'wp-' . $ev->id . '@nadclubs';
            $summary = $ev->title ?: ucfirst($ev->meeting_type) . ' meeting';
            $descParts = [];
            if ($ev->description) $descParts[] = $ev->description;
            if ($ev->location) $descParts[] = 'Location: ' . $ev->location;
            if ($ev->is_generated) $descParts[] = '(Generated)';
            $description = implode(' | ', $descParts);

            $dtStart = $startDate;
            $dtEnd = $startDate;
            if ($ev->start_time) {
                $dtStart .= 'T' . str_replace(':', '', substr($ev->start_time, 0, 5)) . '00';
            }
            if ($ev->end_time) {
                $dtEnd .= 'T' . str_replace(':', '', substr($ev->end_time, 0, 5)) . '00';
            } else {
                $dtEnd = $dtStart;
            }

            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:' . $uid,
                'DTSTAMP:' . Carbon::now()->utc()->format('Ymd\THis\Z'),
                'SUMMARY:' . $this->escapeIcs($summary),
                'DTSTART:' . $dtStart,
                'DTEND:' . $dtEnd,
                'DESCRIPTION:' . $this->escapeIcs($description),
                'LOCATION:' . $this->escapeIcs($ev->location ?? ''),
                'END:VEVENT',
            ]);
        }

        $lines[] = 'END:VCALENDAR';

        $content = implode("\r\n", $lines);
        $filename = 'workplan-' . ($workplan->club->club_name ?? 'club') . '.ics';

        return response($content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function classPlansPdf(Request $request)
    {
        $user = $request->user();
        $clubs = Club::whereIn('id', ClubHelper::clubIdsForUser($user))->orderBy('club_name')->get(['id', 'club_name']);
        $selectedClubId = $request->input('club_id') ?: $user->club_id ?: ($clubs->first()->id ?? null);
        if ($selectedClubId && !$clubs->contains('id', $selectedClubId)) {
            abort(403, 'Not allowed to view this club workplan.');
        }

        $classId = $request->input('class_id');
        if ($user->profile_type === 'club_personal' && !$classId) {
            $staff = Staff::where('user_id', $user->id)->with('classes')->first();
            $classId = $staff?->classes?->first()?->id;
        }

        $workplan = $this->getWorkplanForUser($user, $selectedClubId, false);
        if (!$workplan) {
            abort(404, 'No workplan found for this club.');
        }
        $workplan = $workplan->load([
            'events' => function ($q) {
                $q->with(['classPlans' => function ($cp) {
                    $cp->with(['class', 'staff.user']);
                }])->orderBy('date')->orderBy('start_time');
            },
            'club',
        ]);

        $needsApproval = $request->boolean('needs_approval', false);
        $statusFilter = $request->input('status'); // approved, rejected, pending, all

        $plans = collect();
        foreach ($workplan->events as $event) {
            foreach ($event->classPlans as $plan) {
                if ($classId && (string)($plan->class_id ?? $plan->class?->id) !== (string)$classId) {
                    continue;
                }
                if ($needsApproval && !$plan->requires_approval) {
                    continue;
                }
                if ($statusFilter && $statusFilter !== 'all') {
                    $isPending = in_array($plan->status, ['submitted', 'changes_requested']);
                    if ($statusFilter === 'pending' && !$isPending) continue;
                    if ($statusFilter === 'approved' && $plan->status !== 'approved') continue;
                    if ($statusFilter === 'rejected' && $plan->status !== 'rejected') continue;
                }
                $plans->push([
                    'date' => optional($event->date)->format('Y-m-d'),
                    'title' => $plan->title,
                    'type' => $plan->type,
                    'status' => $plan->status,
                    'class_name' => $plan->class?->class_name,
                    'staff_name' => $plan->staff?->user?->name ?? $plan->staff?->name,
                    'requested_date' => optional($plan->requested_date)->format('Y-m-d'),
                    'note' => $plan->request_note,
                    'requires_approval' => $plan->requires_approval,
                    'authorized_at' => optional($plan->authorized_at)->format('Y-m-d'),
                    'location' => $plan->location_override,
                    'location_override' => $plan->location_override,
                    'created_at' => optional($plan->created_at)->format('Y-m-d'),
                    'updated_at' => optional($plan->updated_at)->format('Y-m-d'),
                ]);
            }
        }

        $className = $classId
            ? ($plans->first()['class_name'] ?? 'Selected class')
            : ($plans->pluck('class_name')->filter()->unique()->values()->implode(', ') ?: 'All classes');
        $staffNames = $plans->pluck('staff_name')->filter()->unique()->implode(', ');

        $pdf = Pdf::loadView('pdf.class_plans', [
            'workplan' => $workplan,
            'plans' => $plans,
            'class_name' => $className,
            'staff_names' => $staffNames,
        ])->setPaper('a4', 'portrait');

        $filename = 'class-plans-' . ($className ?: 'all') . '.pdf';
        return $pdf->download($filename);
    }

    private function escapeIcs(string $value): string
    {
        $escaped = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', '\n', ''], $value);
        return $escaped;
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['nullable', 'string'],
            'default_sabbath_location' => ['nullable', 'string', 'max:255'],
            'default_sunday_location' => ['nullable', 'string', 'max:255'],
            'default_sabbath_start_time' => ['nullable', 'date_format:H:i'],
            'default_sabbath_end_time' => ['nullable', 'date_format:H:i'],
            'default_sunday_start_time' => ['nullable', 'date_format:H:i'],
            'default_sunday_end_time' => ['nullable', 'date_format:H:i'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.meeting_type' => ['required', 'in:sabbath,sunday'],
            'rules.*.nth_week' => ['required', 'integer', 'min:1', 'max:5'],
            'rules.*.note' => ['nullable', 'string'],
        ]);
    }

    private function getWorkplanForUser($user, $clubId = null, bool $create = true): ?Workplan
    {
        $clubId = $clubId ?: $user->club_id;
        if (!$clubId) {
            abort(422, 'Select a club first to manage the workplan.');
        }

        $existing = Workplan::where('club_id', $clubId)->first();
        if ($existing || !$create) {
            return $existing;
        }

        return Workplan::firstOrCreate(
            ['club_id' => $clubId],
            [
                'start_date' => Carbon::now()->startOfMonth()->toDateString(),
                'end_date' => Carbon::now()->addMonthsNoOverflow(1)->endOfMonth()->toDateString(),
            ]
        );
    }

    private function buildRuleMap(array $rules): array
    {
        $ruleMap = [];
        foreach ($rules as $rule) {
            $ruleMap[] = [
                'meeting_type' => $rule['meeting_type'],
                'nth_week' => $rule['nth_week'],
                'note' => $rule['note'] ?? null,
            ];
        }
        return $ruleMap;
    }

    private function buildTargets(string $startDate, string $endDate, array $rules, array $payload): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $targets = [];
        $weekdayMap = [
            'sabbath' => Carbon::SATURDAY,
            'sunday' => Carbon::SUNDAY,
        ];

        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $year = $cursor->year;
            $month = $cursor->month;
            foreach ($rules as $rule) {
                $weekday = $weekdayMap[$rule['meeting_type']] ?? null;
                if ($weekday === null) {
                    continue;
                }
                $date = $this->nthWeekdayOfMonth($year, $month, $weekday, (int) $rule['nth_week']);
                if (!$date) {
                    continue;
                }
                if ($date->lt($start) || $date->gt($end)) {
                    continue;
                }
                $key = $date->toDateString() . ':' . $rule['meeting_type'];
                $targets[$key] = [
                    'date' => $date->toDateString(),
                    'meeting_type' => $rule['meeting_type'],
                    'start_time' => $this->defaultForType($payload, $rule['meeting_type'], 'start_time'),
                    'end_time' => $this->defaultForType($payload, $rule['meeting_type'], 'end_time'),
                    'location' => $this->defaultForType($payload, $rule['meeting_type'], 'location'),
                    'title' => ucfirst($rule['meeting_type']) . ' Meeting',
                    'description' => $rule['note'] ?? null,
                    'rule_id' => $rule['rule_id'] ?? null,
                ];
            }
            $cursor->addMonth()->startOfMonth();
        }

        return $targets;
    }

    private function diffEvents(Collection $existingGenerated, array $targets): array
    {
        $existingMap = [];
        foreach ($existingGenerated as $event) {
            $key = $event->date->toDateString() . ':' . $event->meeting_type;
            $existingMap[$key] = $event;
        }

        $adds = [];
        foreach ($targets as $key => $target) {
            if (!isset($existingMap[$key])) {
                $adds[$key] = $target;
            }
        }

        $removals = [];
        foreach ($existingMap as $key => $event) {
            if (!isset($targets[$key]) && !$event->is_edited) {
                $removals[] = $event->id;
            }
        }

        return compact('adds', 'removals');
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): ?Carbon
    {
        $firstOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $offset = ($weekday - $firstOfMonth->dayOfWeek + 7) % 7;
        $date = $firstOfMonth->copy()->addDays($offset + 7 * ($nth - 1));
        if ($date->month !== $month) {
            return null;
        }
        return $date;
    }

    private function defaultForType(array $payload, string $type, string $field): ?string
    {
        $map = [
            'start_time' => "default_{$type}_start_time",
            'end_time' => "default_{$type}_end_time",
            'location' => "default_{$type}_location",
        ];
        $key = $map[$field] ?? null;
        return $key && isset($payload[$key]) ? $payload[$key] : null;
    }

    private function allowedClubs($user)
    {
        if ($user->profile_type === 'parent') {
            $ids = Member::where('parent_id', $user->id)->pluck('club_id')->unique()->filter()->values();
            return \App\Models\Club::whereIn('id', $ids)->orderBy('club_name')->get(['id', 'club_name']);
        }

        return $user->clubs()->orderBy('club_name')->get(['clubs.id', 'club_name']);
    }

    private function parentMemberships($user)
    {
        if ($user->profile_type !== 'parent') {
            return [];
        }

        return Member::where('parent_id', $user->id)
            ->get(['id', 'type', 'id_data', 'club_id', 'parent_id']);
    }

    private function authorizeEvent($user, WorkplanEvent $event): void
    {
        if ($event->workplan->club_id !== $user->club_id) {
            abort(403, 'Not allowed to edit this event.');
        }
    }

    private function withClassName($user)
    {
        if ($user->profile_type !== 'club_personal') {
            return $user;
        }
        $staff = Staff::with('class')->where('user_id', $user->id)->first();
        if ($staff) {
            $user->setAttribute('assigned_class_id', $staff->assigned_class);
            $user->setAttribute('assigned_class_name', optional($staff->class)->class_name);
        }
        return $user;
    }
}
