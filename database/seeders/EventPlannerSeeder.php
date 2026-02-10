<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

class EventPlannerSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::first();

        if (!$club) {
            $director = User::factory()->create([
                'profile_type' => 'club_director',
                'sub_role' => null,
                'status' => 'active',
            ]);

            $club = Club::factory()->create([
                'user_id' => $director->id,
                'director_name' => $director->name,
                'status' => 'active',
            ]);

            $director->club_id = $club->id;
            $director->save();
        }

        $director = User::find($club->user_id) ?? User::factory()->create([
            'profile_type' => 'club_director',
            'sub_role' => null,
            'status' => 'active',
            'club_id' => $club->id,
        ]);

        $museumEvent = Event::firstOrCreate([
            'club_id' => $club->id,
            'title' => 'City Museum Trip',
        ], [
            'created_by_user_id' => $director->id,
            'event_type' => 'museum_trip',
            'start_at' => now()->addWeeks(3),
            'end_at' => now()->addWeeks(3)->addHours(5),
            'timezone' => 'America/New_York',
            'location_name' => 'City History Museum',
            'location_address' => '123 Main St, City, ST',
            'status' => 'planned',
            'requires_approval' => true,
            'risk_level' => 'low',
        ]);

        EventPlan::firstOrCreate([
            'event_id' => $museumEvent->id,
        ], [
            'schema_version' => 1,
            'plan_json' => [
                'sections' => [
                    [
                        'name' => 'Overview',
                        'summary' => 'Half-day educational visit with guided tour and scavenger hunt.',
                        'items' => [
                            ['label' => 'Educational goals', 'detail' => 'Local history + science exhibit focus'],
                            ['label' => 'Group size', 'detail' => 'Up to 35 kids, 8 adults'],
                        ],
                    ],
                    [
                        'name' => 'Logistics',
                        'summary' => 'Bus transportation with staggered pickup times.',
                        'items' => [
                            ['label' => 'Departure', 'detail' => '8:30 AM from church parking lot'],
                            ['label' => 'Return', 'detail' => '1:30 PM'],
                            ['label' => 'Tickets', 'detail' => 'Group rate confirmed, hold until 2 weeks prior'],
                        ],
                    ],
                    [
                        'name' => 'Safety',
                        'summary' => 'Low risk event with standard supervision and emergency plan.',
                        'items' => [
                            ['label' => 'Ratio', 'detail' => '1 adult per 5 kids'],
                            ['label' => 'First aid', 'detail' => 'Two trained staff with kits'],
                        ],
                    ],
                    [
                        'name' => 'Communications',
                        'summary' => 'Parent updates via email + SMS the day before.',
                        'items' => [
                            ['label' => 'Checklist', 'detail' => 'Remind to bring water bottle + lunch'],
                        ],
                    ],
                ],
            ],
            'missing_items_json' => [
                'Confirm final headcount',
                'Collect remaining permission slips',
                'Assign chaperone groups',
            ],
            'conversation_json' => [],
        ]);

        $campEvent = Event::firstOrCreate([
            'club_id' => $club->id,
            'title' => 'Spring Campout',
        ], [
            'created_by_user_id' => $director->id,
            'event_type' => 'camp',
            'start_at' => now()->addWeeks(6),
            'end_at' => now()->addWeeks(6)->addDays(2),
            'timezone' => 'America/New_York',
            'location_name' => 'Pine Ridge Campground',
            'location_address' => '456 Forest Rd, Valley, ST',
            'status' => 'planned',
            'requires_approval' => true,
            'risk_level' => 'medium',
        ]);

        EventPlan::firstOrCreate([
            'event_id' => $campEvent->id,
        ], [
            'schema_version' => 1,
            'plan_json' => [
                'sections' => [
                    [
                        'name' => 'Overview',
                        'summary' => 'Weekend camp focused on outdoors skills and team building.',
                        'items' => [
                            ['label' => 'Theme', 'detail' => 'Trailblazers'],
                            ['label' => 'Capacity', 'detail' => '50 kids, 12 staff'],
                        ],
                    ],
                    [
                        'name' => 'Logistics',
                        'summary' => 'Two buses, onsite cabins, meal plan with camp kitchen.',
                        'items' => [
                            ['label' => 'Arrival', 'detail' => 'Friday 4 PM check-in'],
                            ['label' => 'Departure', 'detail' => 'Sunday 2 PM check-out'],
                            ['label' => 'Meals', 'detail' => '5 meals + 2 snacks'],
                        ],
                    ],
                    [
                        'name' => 'Safety',
                        'summary' => 'Moderate risk with overnight supervision and activity waivers.',
                        'items' => [
                            ['label' => 'Medical', 'detail' => 'Medication lockbox with log'],
                            ['label' => 'Emergency', 'detail' => 'Nearest clinic 12 miles away'],
                        ],
                    ],
                    [
                        'name' => 'Budget',
                        'summary' => 'Per-person fee and fundraising offset.',
                        'items' => [
                            ['label' => 'Per camper fee', 'detail' => '$85'],
                            ['label' => 'Fundraising target', 'detail' => '$1,500'],
                        ],
                    ],
                ],
            ],
            'missing_items_json' => [
                'Finalize bus contract',
                'Collect medical forms',
                'Confirm menu with kitchen team',
                'Assign cabin leaders',
            ],
            'conversation_json' => [],
        ]);
    }
}
