<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_attempts_are_traceable_without_storing_attempted_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'audit@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $failed = AuditLog::query()->where('action', 'login_failed')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $failed->entity_id);
        $this->assertSame('a***@example.test', $failed->entity_label);
        $this->assertNotNull($failed->metadata['identifier_hash'] ?? null);
        $this->assertStringNotContainsString('wrong-password', json_encode($failed->toArray()));
        $this->assertNotNull($failed->event_uuid);
        $this->assertNotNull($failed->integrity_hash);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_succeeded',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        $this->post('/logout')->assertRedirect('/');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mutation_request',
            'actor_id' => $user->id,
            'route' => 'logout',
        ]);
    }

    public function test_sensitive_values_are_redacted_from_model_change_history(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->forceFill(['password' => Hash::make('replacement-password')])->save();

        $updated = AuditLog::query()
            ->where('action', 'updated')
            ->where('entity_type', 'User')
            ->where('entity_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('[REDACTED]', $updated->changes['before']['password']);
        $this->assertSame('[REDACTED]', $updated->changes['after']['password']);
        $this->assertStringNotContainsString('replacement-password', json_encode($updated->toArray()));
    }
}
