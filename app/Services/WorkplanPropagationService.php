<?php

namespace App\Services;

use App\Models\Association;
use App\Models\AssociationWorkplanEvent;
use App\Models\AssociationWorkplanPublication;
use App\Models\Club;
use App\Models\District;
use App\Models\DistrictWorkplanEvent;
use App\Models\DistrictWorkplanPublication;
use App\Models\Union;
use App\Models\UnionWorkplanEvent;
use App\Models\UnionWorkplanPublication;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WorkplanPropagationService
{
    public function publishUnion(Union $union, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($union, $year, $user) {
            $events = UnionWorkplanEvent::query()
                ->where('union_id', $union->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->get();

            $publication = UnionWorkplanPublication::query()->updateOrCreate(
                ['union_id' => $union->id, 'year' => $year],
                [
                    'status' => 'published',
                    'published_at' => now(),
                    'unpublished_at' => null,
                    'published_by' => $user?->id,
                ]
            );

            $associations = $union->associations()->where('status', 'active')->get();
            $eventIds = $events->pluck('id')->all();

            AssociationWorkplanEvent::query()
                ->whereIn('association_id', $associations->pluck('id'))
                ->where('year', $year)
                ->whereNotNull('union_workplan_event_id')
                ->when($eventIds, fn ($query) => $query->whereNotIn('union_workplan_event_id', $eventIds))
                ->delete();

            foreach ($associations as $association) {
                foreach ($events as $event) {
                    $associationEvent = AssociationWorkplanEvent::query()->firstOrNew([
                        'association_id' => $association->id,
                        'union_workplan_event_id' => $event->id,
                    ]);

                    $associationEvent->fill([
                        'year' => $year,
                        'event_type' => $event->event_type,
                        'title' => $event->title,
                        'description' => $event->description,
                        'location' => $event->location,
                        'target_club_types' => $event->target_club_types,
                        'is_mandatory' => (bool) $event->is_mandatory,
                        'status' => 'active',
                    ]);

                    if (!$associationEvent->exists) {
                        $associationEvent->fill([
                            'date' => $event->date,
                            'end_date' => $event->end_date,
                            'start_time' => $event->start_time,
                            'end_time' => $event->end_time,
                            'created_by' => $user?->id,
                        ]);
                    }

                    $associationEvent->save();
                }
            }

            return [
                'publication' => $publication,
                'associations' => $associations->count(),
                'events' => $events->count(),
            ];
        });
    }

    public function unpublishUnion(Union $union, int $year): array
    {
        return DB::transaction(function () use ($union, $year) {
            $associationIds = $union->associations()->pluck('id');
            $associationEventIds = AssociationWorkplanEvent::query()
                ->whereIn('association_id', $associationIds)
                ->where('year', $year)
                ->whereNotNull('union_workplan_event_id')
                ->pluck('id');

            $deletedClubEvents = WorkplanEvent::query()
                ->where('source_type', AssociationWorkplanEvent::class)
                ->whereIn('source_id', $associationEventIds)
                ->delete();

            $deletedAssociationEvents = AssociationWorkplanEvent::query()
                ->whereIn('id', $associationEventIds)
                ->delete();

            UnionWorkplanPublication::query()->updateOrCreate(
                ['union_id' => $union->id, 'year' => $year],
                [
                    'status' => 'unpublished',
                    'unpublished_at' => now(),
                ]
            );

            return [
                'association_events' => $deletedAssociationEvents,
                'club_events' => $deletedClubEvents,
            ];
        });
    }

    public function syncUnionMissing(Union $union, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($union, $year, $user) {
            $events = UnionWorkplanEvent::query()
                ->where('union_id', $union->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->get();

            $associations = $union->associations()->where('status', 'active')->get();
            $associationEventsCreated = 0;
            $associationIdsTouched = [];
            $clubEventsCreated = 0;
            $clubIdsTouched = [];

            foreach ($associations as $association) {
                $existingSourceIds = AssociationWorkplanEvent::query()
                    ->where('association_id', $association->id)
                    ->where('year', $year)
                    ->whereNotNull('union_workplan_event_id')
                    ->pluck('union_workplan_event_id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

                foreach ($events as $event) {
                    if ($existingSourceIds->has((int) $event->id)) {
                        continue;
                    }

                    AssociationWorkplanEvent::query()->create([
                        'association_id' => $association->id,
                        'union_workplan_event_id' => $event->id,
                        'year' => $year,
                        'date' => $event->date,
                        'end_date' => $event->end_date,
                        'start_time' => $event->start_time,
                        'end_time' => $event->end_time,
                        'event_type' => $event->event_type,
                        'title' => $event->title,
                        'description' => $event->description,
                        'location' => $event->location,
                        'target_club_types' => $event->target_club_types,
                        'is_mandatory' => (bool) $event->is_mandatory,
                        'status' => 'active',
                        'created_by' => $user?->id,
                    ]);

                    $associationEventsCreated++;
                    $associationIdsTouched[$association->id] = true;
                }

                $associationPublication = AssociationWorkplanPublication::query()
                    ->where('association_id', $association->id)
                    ->where('year', $year)
                    ->first();

                if (($associationPublication?->status ?? null) === 'published') {
                    $sync = $this->syncAssociationMissingToClubs($association, $year, $user);
                    $clubEventsCreated += $sync['club_events_created'];
                    foreach ($sync['club_ids_touched'] as $clubId) {
                        $clubIdsTouched[$clubId] = true;
                    }
                }
            }

            return [
                'associations' => $associations->count(),
                'association_events_created' => $associationEventsCreated,
                'associations_touched' => count($associationIdsTouched),
                'club_events_created' => $clubEventsCreated,
                'clubs_touched' => count($clubIdsTouched),
            ];
        });
    }

    public function publishAssociation(Association $association, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($association, $year, $user) {
            $events = AssociationWorkplanEvent::query()
                ->where('association_id', $association->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->get();

            $clubs = Club::withoutGlobalScopes()
                ->where('status', 'active')
                ->whereHas('district', fn ($query) => $query->where('association_id', $association->id))
                ->get(['id', 'club_name', 'club_type']);

            $createdOrUpdated = 0;
            $keptEventIds = [];
            $workplanIds = [];
            $allAssociationEventIds = AssociationWorkplanEvent::query()
                ->where('association_id', $association->id)
                ->where('year', $year)
                ->pluck('id');

            foreach ($clubs as $club) {
                $workplan = Workplan::query()->firstOrCreate(
                    ['club_id' => $club->id],
                    [
                        'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                        'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                    ]
                );
                $workplanIds[] = $workplan->id;

                foreach ($events as $event) {
                    if (!$this->eventAppliesToClub($event, $club->club_type)) {
                        continue;
                    }

                    $clubEvent = WorkplanEvent::query()->updateOrCreate(
                        [
                            'workplan_id' => $workplan->id,
                            'source_type' => AssociationWorkplanEvent::class,
                            'source_id' => $event->id,
                        ],
                        [
                            'date' => $event->date,
                            'end_date' => $event->end_date,
                            'start_time' => $event->start_time,
                            'end_time' => $event->end_time,
                            'meeting_type' => 'special',
                            'title' => $event->title,
                            'description' => $event->description,
                            'location' => $event->location,
                            'is_generated' => true,
                            'is_edited' => false,
                            'status' => 'active',
                            'created_by' => $user?->id,
                        ]
                    );
                    $keptEventIds[] = $clubEvent->id;
                    $createdOrUpdated++;
                }
            }

            WorkplanEvent::query()
                ->whereIn('workplan_id', $workplanIds)
                ->where('source_type', AssociationWorkplanEvent::class)
                ->whereIn('source_id', $allAssociationEventIds)
                ->when($keptEventIds, fn ($query) => $query->whereNotIn('id', $keptEventIds))
                ->delete();

            $publication = AssociationWorkplanPublication::query()->updateOrCreate(
                ['association_id' => $association->id, 'year' => $year],
                [
                    'status' => 'published',
                    'published_at' => now(),
                    'unpublished_at' => null,
                    'published_by' => $user?->id,
                ]
            );

            return [
                'publication' => $publication,
                'clubs' => $clubs->count(),
                'events' => $events->count(),
                'club_events' => $createdOrUpdated,
            ];
        });
    }

    public function syncAssociationMissing(Association $association, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($association, $year, $user) {
            $sync = $this->syncAssociationMissingToClubs($association, $year, $user);

            return [
                'clubs' => count($sync['club_ids_touched']),
                'club_events_created' => $sync['club_events_created'],
            ];
        });
    }

    public function unpublishAssociation(Association $association, int $year): array
    {
        return DB::transaction(function () use ($association, $year) {
            $eventIds = AssociationWorkplanEvent::query()
                ->where('association_id', $association->id)
                ->where('year', $year)
                ->pluck('id');

            $deletedClubEvents = WorkplanEvent::query()
                ->where('source_type', AssociationWorkplanEvent::class)
                ->whereIn('source_id', $eventIds)
                ->delete();

            AssociationWorkplanPublication::query()->updateOrCreate(
                ['association_id' => $association->id, 'year' => $year],
                [
                    'status' => 'unpublished',
                    'unpublished_at' => now(),
                ]
            );

            return ['club_events' => $deletedClubEvents];
        });
    }

    public function publishDistrict(District $district, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($district, $year, $user) {
            $events = DistrictWorkplanEvent::query()
                ->where('district_id', $district->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->get();

            $clubs = Club::withoutGlobalScopes()
                ->where('status', 'active')
                ->where('district_id', $district->id)
                ->get(['id', 'club_name', 'club_type']);

            $createdOrUpdated = 0;
            $keptEventIds = [];
            $workplanIds = [];
            $allDistrictEventIds = DistrictWorkplanEvent::query()
                ->where('district_id', $district->id)
                ->where('year', $year)
                ->pluck('id');

            foreach ($clubs as $club) {
                $workplan = Workplan::query()->firstOrCreate(
                    ['club_id' => $club->id],
                    [
                        'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                        'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                    ]
                );
                $workplanIds[] = $workplan->id;

                foreach ($events as $event) {
                    if (!$this->eventAppliesToClub($event, $club->club_type)) {
                        continue;
                    }

                    $clubEvent = WorkplanEvent::query()->updateOrCreate(
                        [
                            'workplan_id' => $workplan->id,
                            'source_type' => DistrictWorkplanEvent::class,
                            'source_id' => $event->id,
                        ],
                        [
                            'date' => $event->date,
                            'end_date' => $event->end_date,
                            'start_time' => $event->start_time,
                            'end_time' => $event->end_time,
                            'meeting_type' => 'special',
                            'title' => $event->title,
                            'description' => $event->description,
                            'location' => $event->location,
                            'is_generated' => true,
                            'is_edited' => false,
                            'status' => 'active',
                            'created_by' => $user?->id,
                        ]
                    );
                    $keptEventIds[] = $clubEvent->id;
                    $createdOrUpdated++;
                }
            }

            WorkplanEvent::query()
                ->whereIn('workplan_id', $workplanIds)
                ->where('source_type', DistrictWorkplanEvent::class)
                ->whereIn('source_id', $allDistrictEventIds)
                ->when($keptEventIds, fn ($query) => $query->whereNotIn('id', $keptEventIds))
                ->delete();

            $publication = DistrictWorkplanPublication::query()->updateOrCreate(
                ['district_id' => $district->id, 'year' => $year],
                [
                    'status' => 'published',
                    'published_at' => now(),
                    'unpublished_at' => null,
                    'published_by' => $user?->id,
                ]
            );

            return [
                'publication' => $publication,
                'clubs' => $clubs->count(),
                'events' => $events->count(),
                'club_events' => $createdOrUpdated,
            ];
        });
    }

    public function syncDistrictMissing(District $district, int $year, ?User $user = null): array
    {
        return DB::transaction(function () use ($district, $year, $user) {
            $sync = $this->syncDistrictMissingToClubs($district, $year, $user);

            return [
                'clubs' => count($sync['club_ids_touched']),
                'club_events_created' => $sync['club_events_created'],
            ];
        });
    }

    public function unpublishDistrict(District $district, int $year): array
    {
        return DB::transaction(function () use ($district, $year) {
            $eventIds = DistrictWorkplanEvent::query()
                ->where('district_id', $district->id)
                ->where('year', $year)
                ->pluck('id');

            $deletedClubEvents = WorkplanEvent::query()
                ->where('source_type', DistrictWorkplanEvent::class)
                ->whereIn('source_id', $eventIds)
                ->delete();

            DistrictWorkplanPublication::query()->updateOrCreate(
                ['district_id' => $district->id, 'year' => $year],
                [
                    'status' => 'unpublished',
                    'unpublished_at' => now(),
                ]
            );

            return ['club_events' => $deletedClubEvents];
        });
    }

    protected function eventAppliesToClub(AssociationWorkplanEvent|DistrictWorkplanEvent $event, ?string $clubType): bool
    {
        $targets = collect($event->target_club_types ?? [])
            ->map(fn ($target) => $this->normalizeClubType($target))
            ->filter()
            ->values()
            ->all();

        if (empty($targets)) {
            return true;
        }

        return in_array($this->normalizeClubType($clubType), $targets, true);
    }

    protected function normalizeClubType(?string $value): string
    {
        return str_replace(['-', '_', ' '], '', mb_strtolower((string) $value));
    }

    protected function syncAssociationMissingToClubs(Association $association, int $year, ?User $user = null): array
    {
        $events = AssociationWorkplanEvent::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->get();

        $clubs = Club::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereHas('district', fn ($query) => $query->where('association_id', $association->id))
            ->get(['id', 'club_name', 'club_type']);

        $clubEventsCreated = 0;
        $clubIdsTouched = [];

        foreach ($clubs as $club) {
            $workplan = Workplan::query()->firstOrCreate(
                ['club_id' => $club->id],
                [
                    'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                    'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                ]
            );

            $existingSourceIds = WorkplanEvent::query()
                ->where('workplan_id', $workplan->id)
                ->where('source_type', AssociationWorkplanEvent::class)
                ->pluck('source_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            foreach ($events as $event) {
                if (!$this->eventAppliesToClub($event, $club->club_type)) {
                    continue;
                }

                if ($existingSourceIds->has((int) $event->id)) {
                    continue;
                }

                WorkplanEvent::query()->create([
                    'workplan_id' => $workplan->id,
                    'source_type' => AssociationWorkplanEvent::class,
                    'source_id' => $event->id,
                    'date' => $event->date,
                    'end_date' => $event->end_date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'meeting_type' => 'special',
                    'title' => $event->title,
                    'description' => $event->description,
                    'location' => $event->location,
                    'is_generated' => true,
                    'is_edited' => false,
                    'status' => 'active',
                    'created_by' => $user?->id,
                ]);

                $clubEventsCreated++;
                $clubIdsTouched[$club->id] = true;
            }
        }

        return [
            'club_events_created' => $clubEventsCreated,
            'club_ids_touched' => array_keys($clubIdsTouched),
        ];
    }

    protected function syncDistrictMissingToClubs(District $district, int $year, ?User $user = null): array
    {
        $events = DistrictWorkplanEvent::query()
            ->where('district_id', $district->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->get();

        $clubs = Club::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('district_id', $district->id)
            ->get(['id', 'club_name', 'club_type']);

        $clubEventsCreated = 0;
        $clubIdsTouched = [];

        foreach ($clubs as $club) {
            $workplan = Workplan::query()->firstOrCreate(
                ['club_id' => $club->id],
                [
                    'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                    'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                ]
            );

            $existingSourceIds = WorkplanEvent::query()
                ->where('workplan_id', $workplan->id)
                ->where('source_type', DistrictWorkplanEvent::class)
                ->pluck('source_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            foreach ($events as $event) {
                if (!$this->eventAppliesToClub($event, $club->club_type)) {
                    continue;
                }

                if ($existingSourceIds->has((int) $event->id)) {
                    continue;
                }

                WorkplanEvent::query()->create([
                    'workplan_id' => $workplan->id,
                    'source_type' => DistrictWorkplanEvent::class,
                    'source_id' => $event->id,
                    'date' => $event->date,
                    'end_date' => $event->end_date,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'meeting_type' => 'special',
                    'title' => $event->title,
                    'description' => $event->description,
                    'location' => $event->location,
                    'is_generated' => true,
                    'is_edited' => false,
                    'status' => 'active',
                    'created_by' => $user?->id,
                ]);

                $clubEventsCreated++;
                $clubIdsTouched[$club->id] = true;
            }
        }

        return [
            'club_events_created' => $clubEventsCreated,
            'club_ids_touched' => array_keys($clubIdsTouched),
        ];
    }
}
