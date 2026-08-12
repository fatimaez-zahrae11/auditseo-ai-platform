<?php

namespace Tests\Feature;

use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_active_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNull($user->blocked_at);
        $this->assertNull($user->blocked_reason);
        $this->assertNull($user->blocked_by);
    }

    public function test_blocking_users_are_set_to_null_when_the_blocker_is_deleted(): void
    {
        $blocker = User::factory()->create();
        $blockedUser = User::factory()->create();
        $blockedUser->is_active = false;
        $blockedUser->blocked_by = $blocker->id;
        $blockedUser->save();

        $blocker->delete();

        $this->assertNull($blockedUser->fresh()->blocked_by);
    }

    public function test_activation_migration_can_be_reversed_and_reapplied_on_sqlite(): void
    {
        $columns = [
            'is_active',
            'blocked_at',
            'blocked_reason',
            'blocked_by',
        ];
        $migration = require database_path(
            'migrations/2026_08_12_000100_add_activation_fields_to_users_table.php',
        );

        $this->assertTrue(Schema::hasColumns('users', $columns));

        $migration->down();

        $this->assertFalse(Schema::hasColumns('users', $columns));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('users', $columns));
    }

    public function test_registration_cannot_override_activation_or_blocking_metadata(): void
    {
        $blocker = User::factory()->create();

        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Password1',
            'is_active' => false,
            'blocked_at' => now()->toJSON(),
            'blocked_reason' => 'Injected reason',
            'blocked_by' => $blocker->id,
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->sole();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->blocked_at);
        $this->assertNull($user->blocked_reason);
        $this->assertNull($user->blocked_by);
    }

    public function test_inactive_user_cannot_log_in_and_receives_no_token(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('Password1'),
        ]);
        $user->is_active = false;
        $user->blocked_at = now();
        $user->blocked_reason = 'Administrative action';
        $user->save();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Account disabled',
            ])
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => AuthAuditLog::EVENT_LOGIN,
            'status' => AuthAuditLog::STATUS_FAILED,
        ]);
        $this->assertStringNotContainsString('Administrative action', $response->getContent());
    }

    public function test_active_user_can_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.blocked_at')
            ->assertJsonMissingPath('user.blocked_reason')
            ->assertJsonMissingPath('user.blocked_by');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_deactivated_users_next_authenticated_request_is_forbidden_and_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-token');
        $otherToken = $user->createToken('other-token');

        $user->is_active = false;
        $user->blocked_at = now();
        $user->save();

        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/me')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Account disabled',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_public_health_remains_public_while_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
            ]);

        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/audits')->assertUnauthorized();
    }
}
