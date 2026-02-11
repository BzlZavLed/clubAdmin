<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 days', '+2 months');
        $end = (clone $start)->modify('+4 hours');
        $isPayable = $this->faker->boolean(30);

        return [
            'club_id' => Club::factory(),
            'created_by_user_id' => User::factory()->state([
                'profile_type' => 'club_director',
                'sub_role' => null,
                'status' => 'active',
            ]),
            'title' => $this->faker->sentence(3),
            'event_type' => $this->faker->randomElement(['camp', 'fundraiser', 'museum_trip', 'sports_outing']),
            'start_at' => $start,
            'end_at' => $end,
            'timezone' => 'America/New_York',
            'location_name' => $this->faker->company,
            'location_address' => $this->faker->address,
            'status' => $this->faker->randomElement(['draft', 'planned']),
            'budget_estimated_total' => $this->faker->randomFloat(2, 0, 1000),
            'budget_actual_total' => null,
            'requires_approval' => $this->faker->boolean(20),
            'is_payable' => $isPayable,
            'payment_amount' => $isPayable ? $this->faker->randomFloat(2, 5, 50) : null,
            'risk_level' => $this->faker->randomElement(['low', 'medium', 'high']),
        ];
    }
}
