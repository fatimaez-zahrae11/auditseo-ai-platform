<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\AdminActionLog;
use App\Models\AuthAuditLog;
use App\Models\User;
use App\Services\ActionLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminActionLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_action_logs(): void
    {
        $this->getJson('/api/admin/action-logs')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_non_admin_user_cannot_view_action_logs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/action-logs')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);
    }

    public function test_inactive_admin_cannot_view_action_logs(): void
    {
        Sanctum::actingAs($this->createAdmin(['is_active' => false]));

        $this->getJson('/api/admin/action-logs')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Account disabled']);
    }

    public function test_admin_can_list_unified_action_logs_with_safe_fields(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create();
        app(ActionLogger::class)->log(
            $admin,
            AdminActionLog::ACTION_USER_DEACTIVATED,
            $target,
            metadata: ['reason_code' => 'policy'],
        );
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/action-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.actor.name', $admin->name)
            ->assertJsonPath('data.0.actor.email', $admin->email)
            ->assertJsonPath('data.0.actor.role', User::ROLE_ADMIN)
            ->assertJsonPath('data.0.action', AdminActionLog::ACTION_USER_DEACTIVATED)
            ->assertJsonPath('data.0.entity_type', 'user')
            ->assertJsonPath('data.0.entity_id', $target->id)
            ->assertJsonPath('data.0.status', ActionLog::STATUS_SUCCESS)
            ->assertJsonPath('data.0.metadata_summary', 'reason code: policy');

        $this->assertEqualsCanonicalizing([
            'id',
            'actor',
            'action',
            'entity_type',
            'entity_id',
            'status',
            'metadata_summary',
            'created_at',
        ], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing([
            'id',
            'name',
            'email',
            'role',
        ], array_keys($response->json('data.0.actor')));
    }

    public function test_action_log_filters_are_applied_server_side(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create([
            'name' => 'Searchable Actor',
            'email' => 'searchable@example.com',
        ]);
        $otherUser = User::factory()->create();

        $matching = $this->createLog($user, [
            'action' => ActionLog::ACTION_AUDIT_CREATED,
            'entity_type' => 'audit',
            'entity_id' => 42,
            'status' => ActionLog::STATUS_FAILURE,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $this->createLog($otherUser, [
            'action' => ActionLog::ACTION_AUDIT_CREATED,
            'entity_type' => 'audit',
            'entity_id' => 43,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $this->createLog($admin, [
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            'entity_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createLog(null, [
            'action' => 'system.maintenance',
            'entity_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $query = http_build_query([
            'role' => 'user',
            'actor_user_id' => $user->id,
            'q' => 'searchable',
            'action' => ActionLog::ACTION_AUDIT_CREATED,
            'entity_type' => 'audit',
            'status' => 'failure',
            'date_from' => '2026-08-11',
            'date_to' => '2026-08-11',
        ]);
        $this->getJson("/api/admin/action-logs?{$query}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $matching->id);

        $this->assertRoleFilter($admin, 'admin', [$admin->id]);
        $this->assertRoleFilter($admin, 'user', [$user->id, $otherUser->id]);
        $this->getJson('/api/admin/action-logs?role=system')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.role', 'system');
        $this->getJson('/api/admin/action-logs?q=system.logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.id', $admin->id);
    }

    public function test_invalid_action_log_filters_return_validation_errors(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/action-logs?actor_user_id=not-a-number')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actor_user_id');
        $this->getJson('/api/admin/action-logs?actor_user_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actor_user_id');
        $this->getJson('/api/admin/action-logs?role=owner')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->getJson('/api/admin/action-logs?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->getJson('/api/admin/action-logs?date_from=2026-08-12&date_to=2026-08-11')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_action_log_pagination_uses_a_safe_maximum(): void
    {
        $admin = $this->createAdmin();
        foreach (range(1, 105) as $index) {
            $this->createLog($admin, [
                'action' => "test.action.{$index}",
                'created_at' => now()->subSeconds($index),
                'updated_at' => now()->subSeconds($index),
            ]);
        }
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/action-logs?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 105);

        $this->getJson('/api/admin/action-logs?per_page=999')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_actions_are_written_to_legacy_and_unified_logs(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users', [
            'name' => 'Audited User',
            'email' => 'audited@example.com',
            'password' => 'Password1',
        ])->assertCreated();

        $user = User::query()->where('email', 'audited@example.com')->sole();
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_USER_CREATED,
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $admin->id,
            'actor_role' => User::ROLE_ADMIN,
            'action' => AdminActionLog::ACTION_USER_CREATED,
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
    }

    public function test_unified_log_migration_backfills_existing_admin_and_auth_actions(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        AdminActionLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            'metadata' => [
                'lines_returned' => 10,
                'password' => 'legacy-password',
            ],
            'created_at' => now()->subMinute(),
        ]);
        AuthAuditLog::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthAuditLog::EVENT_LOGIN,
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);

        $migration = require database_path(
            'migrations/2026_08_31_020000_create_action_logs_table.php',
        );
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $admin->id,
            'actor_role' => User::ROLE_ADMIN,
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
        $backfilledAdminLog = ActionLog::query()
            ->where('actor_user_id', $admin->id)
            ->where('action', AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED)
            ->sole();
        $this->assertSame(['lines_returned' => 10], $backfilledAdminLog->metadata);
        $this->assertStringNotContainsString('legacy-password', $backfilledAdminLog->toJson());
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $user->id,
            'actor_role' => User::ROLE_USER,
            'action' => ActionLog::ACTION_USER_LOGGED_IN,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
    }

    public function test_regular_user_registration_and_login_are_recorded(): void
    {
        Notification::fake();
        $this->postJson('/api/register', [
            'name' => 'Registered User',
            'email' => 'registered@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $registered = User::query()->where('email', 'registered@example.com')->sole();
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $registered->id,
            'actor_role' => User::ROLE_USER,
            'action' => ActionLog::ACTION_USER_REGISTERED,
            'entity_type' => 'user',
            'entity_id' => $registered->id,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);

        $loginUser = User::factory()->create([
            'password' => 'Password1',
            'email_verified_at' => now(),
        ]);
        $this->postJson('/api/login', [
            'email' => $loginUser->email,
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $loginUser->id,
            'actor_role' => User::ROLE_USER,
            'action' => ActionLog::ACTION_USER_LOGGED_IN,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
    }

    public function test_sensitive_metadata_is_neither_stored_nor_exposed(): void
    {
        $admin = $this->createAdmin();
        app(ActionLogger::class)->log($admin, 'safe.action', metadata: [
            'reason_code' => 'policy',
            'password' => 'password-value',
            'access_token' => 'token-value',
            'authorization' => 'Bearer metadata-token',
            'api_key' => 'api-key-value',
            'cookie' => 'cookie-value',
            'request_body' => 'request-body-value',
            'provider_prompt' => 'prompt-value',
            'provider_payload' => 'payload-value',
            'nested' => [
                'safe_nested' => true,
                'session_secret' => 'nested-secret',
            ],
        ]);

        $stored = ActionLog::query()->sole();
        $this->assertSame([
            'reason_code' => 'policy',
            'nested' => ['safe_nested' => true],
        ], $stored->metadata);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/action-logs')
            ->assertOk()
            ->assertJsonPath('data.0.metadata_summary', 'reason code: policy')
            ->assertJsonMissingPath('data.0.metadata');

        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $stored->toJson());
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_action_logging_failure_does_not_break_original_admin_actions(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);
        Schema::drop('action_logs');
        Schema::drop('admin_action_logs');

        $this->postJson('/api/admin/users', [
            'name' => 'Still Created',
            'email' => 'still-created@example.com',
            'password' => 'Password1',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'still-created@example.com');

        $this->assertDatabaseHas('users', ['email' => 'still-created@example.com']);
        $this->getJson('/api/admin/system/health-detailed')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_ADMIN,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLog(?User $actor, array $attributes = []): ActionLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        $updatedAt = $attributes['updated_at'] ?? $createdAt;
        unset($attributes['created_at'], $attributes['updated_at']);

        $log = ActionLog::query()->create(array_merge([
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->role ?? ActionLog::ROLE_SYSTEM,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'action' => ActionLog::ACTION_USER_LOGGED_IN,
            'status' => ActionLog::STATUS_SUCCESS,
        ], $attributes));

        if ($createdAt !== null) {
            $log->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ])->save();
        }

        return $log->refresh();
    }

    /**
     * @param  list<int>  $expectedActorIds
     */
    private function assertRoleFilter(User $admin, string $role, array $expectedActorIds): void
    {
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/admin/action-logs?role={$role}")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $expectedActorIds,
            collect($response->json('data'))->pluck('actor.id')->all(),
        );
    }

    /** @return list<string> */
    private function sensitiveValues(): array
    {
        return [
            'password-value',
            'token-value',
            'metadata-token',
            'api-key-value',
            'cookie-value',
            'request-body-value',
            'prompt-value',
            'payload-value',
            'nested-secret',
        ];
    }
}
