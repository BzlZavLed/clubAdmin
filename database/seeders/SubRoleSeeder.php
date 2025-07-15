<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\SubRole;

class SubRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['key' => 'treasurer', 'label' => 'Treasurer'],
            ['key' => 'secretary', 'label' => 'Secretary'],
            ['key' => 'adviser', 'label' => 'Staff'],
        ];

        foreach ($roles as $role) {
            SubRole::updateOrCreate(['key' => $role['key']], $role);
        }
    }
}
