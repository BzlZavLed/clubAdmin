<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubEvaluationSystemInheritanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_created_club_inherits_union_evaluation_system(): void
    {
        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $union = Union::query()->create([
            'name' => 'Interamerica Test',
            'evaluation_system' => 'carpetas',
            'status' => 'active',
        ]);

        $association = Association::query()->create([
            'union_id' => $union->id,
            'name' => 'Association Test',
            'status' => 'active',
        ]);

        $district = District::query()->create([
            'association_id' => $association->id,
            'name' => 'District Test',
            'status' => 'active',
        ]);

        $church = Church::query()->create([
            'district_id' => $district->id,
            'church_name' => 'Pacto de Amor',
            'email' => 'church@example.com',
        ]);

        $this->actingAs($superadmin)
            ->post('/super-admin/clubs', [
                'club_name' => 'Club Carpeta',
                'church_id' => $church->id,
                'director_user_id' => $director->id,
                'creation_date' => now()->toDateString(),
                'club_type' => 'pathfinders',
            ])
            ->assertRedirect();

        $club = Club::withoutGlobalScopes()->where('club_name', 'Club Carpeta')->first();

        $this->assertNotNull($club);
        $this->assertSame('carpetas', $club->evaluation_system);
    }
}
