<?php

namespace Database\Seeders;

use App\Models\TaskFormSchema;
use Illuminate\Database\Seeder;

class TaskFormSchemaSeeder extends Seeder
{
    public function run(): void
    {
        $schemas = [
            [
                'key' => 'permission_slips',
                'name' => 'Permission Slip',
                'description' => 'Collect parent/guardian consent and emergency details.',
                'schema_json' => [
                    'fields' => [
                        ['key' => 'event_title', 'label' => 'Event Title', 'type' => 'text', 'required' => true],
                        ['key' => 'event_dates', 'label' => 'Event Dates', 'type' => 'text', 'required' => true],
                        ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'required' => true],
                        ['key' => 'drop_off', 'label' => 'Drop-off Instructions', 'type' => 'textarea'],
                        ['key' => 'pickup', 'label' => 'Pick-up Instructions', 'type' => 'textarea'],
                        ['key' => 'emergency_contact', 'label' => 'Emergency Contact', 'type' => 'text'],
                        ['key' => 'notes', 'label' => 'Additional Notes', 'type' => 'textarea'],
                    ],
                ],
            ],
            [
                'key' => 'transportation_plan',
                'name' => 'Transportation Plan',
                'description' => 'Transportation details and driver assignments.',
                'schema_json' => [
                    'fields' => [
                        ['key' => 'vehicles', 'label' => 'Vehicles/Seats', 'type' => 'textarea', 'required' => true],
                        ['key' => 'drivers', 'label' => 'Drivers', 'type' => 'textarea'],
                        ['key' => 'route', 'label' => 'Route/Meeting Point', 'type' => 'text'],
                        ['key' => 'departure_time', 'label' => 'Departure Time', 'type' => 'text'],
                        ['key' => 'return_time', 'label' => 'Return Time', 'type' => 'text'],
                    ],
                ],
            ],
            [
                'key' => 'emergency_contacts',
                'name' => 'Emergency Contact List',
                'description' => 'Emergency contacts and medical notes.',
                'schema_json' => [
                    'fields' => [
                        ['key' => 'contact_list', 'label' => 'Contacts', 'type' => 'textarea', 'required' => true],
                        ['key' => 'medical_notes', 'label' => 'Medical Notes', 'type' => 'textarea'],
                        ['key' => 'allergies', 'label' => 'Allergies', 'type' => 'textarea'],
                    ],
                ],
            ],
            [
                'key' => 'chaperone_assignments',
                'name' => 'Chaperone Assignments',
                'description' => 'Staff/chaperone assignments and coverage.',
                'schema_json' => [
                    'fields' => [
                        ['key' => 'assignments', 'label' => 'Assignments', 'type' => 'textarea', 'required' => true],
                        ['key' => 'ratios', 'label' => 'Adult/Child Ratios', 'type' => 'text'],
                    ],
                ],
            ],
            [
                'key' => 'camp_reservation',
                'name' => 'Campsite Reservation',
                'description' => 'Reservation confirmation details.',
                'schema_json' => [
                    'fields' => [
                        ['key' => 'site_name', 'label' => 'Site Name', 'type' => 'text', 'required' => true],
                        ['key' => 'reservation_id', 'label' => 'Reservation ID', 'type' => 'text'],
                        ['key' => 'check_in', 'label' => 'Check-in', 'type' => 'text'],
                        ['key' => 'check_out', 'label' => 'Check-out', 'type' => 'text'],
                        ['key' => 'contact', 'label' => 'Contact Info', 'type' => 'text'],
                    ],
                ],
            ],
        ];

        foreach ($schemas as $schema) {
            TaskFormSchema::updateOrCreate(
                ['key' => $schema['key']],
                $schema
            );
        }
    }
}
