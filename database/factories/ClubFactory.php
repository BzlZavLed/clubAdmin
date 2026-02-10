<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClubFactory extends Factory
{
    protected $model = Club::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state([
                'profile_type' => 'club_director',
                'sub_role' => null,
                'status' => 'active',
            ]),
            'club_name' => $this->faker->company . ' Adventurers',
            'church_name' => $this->faker->company . ' Church',
            'director_name' => $this->faker->name,
            'creation_date' => $this->faker->date(),
            'pastor_name' => $this->faker->name,
            'conference_name' => $this->faker->word,
            'conference_region' => $this->faker->word,
            'club_type' => 'adventurers',
            'status' => 'active',
            'church_id' => $director->church_id,
        ];
    }
}
