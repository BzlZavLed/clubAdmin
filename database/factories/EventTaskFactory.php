<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventTaskFactory extends Factory
{
    protected $model = EventTask::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->paragraph,
            'assigned_to_user_id' => User::factory(),
            'due_at' => $this->faker->optional()->dateTimeBetween('now', '+1 month'),
            'status' => $this->faker->randomElement(['todo', 'in_progress', 'done']),
            'checklist_json' => $this->faker->optional()->randomElements([
                ['label' => 'Confirm venue', 'done' => false],
                ['label' => 'Collect payments', 'done' => false],
            ], 1),
        ];
    }
}
