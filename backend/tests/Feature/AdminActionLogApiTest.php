<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\User;
use App\Services\AdminActionLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminActionLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_admin_action_logs(): void
    {
        $this->getJson('/api/admin/action-logs')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_non_admin_user_cannot_view_admin_action_logs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/action-logs')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);
    }

    public function test_inactive_admin_cannot_view_admin_action_logs(): void
    {
        Sanctum::actingAs($this->createAdmin(['is_active' => false]));

        $this->getJson('/api/admin/action-logs')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Account disabled']);
    }

    public function test_admin_can_list_action_logs_with_admin_email_and_safe_fields(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create();
        $log = $this->createLog($admin, [
            'target_type' => 'User',
            'target_id' => $target->id,
            'metadata' => ['reason_code' => 'policy'],
            'ip_address' => '203.0.113.10',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/action-logs')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('action_logs.0.id', $log->id)
            ->assertJsonPath('action_logs.0.admin_user_id', $admin->id)
            ->assertJsonPath('action_logs.0.admin_user_email', $admin->email)
            ->assertJsonPath('action_logs.0.action', AdminActionLog::ACTION_USER_DEACTIVATED)
            ->assertJsonPath('action_logs.0.target_type', 'User')
            ->assertJsonPath('action_logs.0.target_id', $target->id)
            ->assertJsonPath('action_logs.0.metadata.reason_code', 'policy')
            ->assertJsonPath('action_logs.0.ip_address', '203.0.113.10');

        $this->assertEqualsCanonicalizing([
            'id',
            'admin_user_id',
            'admin_user_email',
            'action',
            'target_type',
            'target_id',
            'metadata',
            'ip_address',
            'created_at',
        ], array_keys($response->json('action_logs.0')));
    }

    public function test_action_log_pagination_uses_a_safe_maximum(): void
    {
        $admin = $this->createAdmin();
        $this->createLogs($admin, 105);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/action-logs?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'action_logs')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 105);

        $this->getJson('/api/admin/action-logs?per_page=999')
            ->assertOk()
            ->assertJsonCount(100, 'action_logs')
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_all_action_log_filters_work(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $otherAdmin = $this->createAdmin();
        $target = User::factory()->create();

        $matching = $this->createLog($admin, [
            'action' => AdminActionLog::ACTION_USER_REACTIVATED,
            'target_type' => 'User',
            'target_id' => $target->id,
            'created_at' => now()->subDay(),
        ]);
        $this->createLog($otherAdmin, [
            'action' => AdminActionLog::ACTION_USER_REACTIVATED,
            'target_type' => 'User',
            'target_id' => $target->id,
            'created_at' => now()->subDay(),
        ]);
        $this->createLog($admin, [
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            'created_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $query = http_build_query([
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_USER_REACTIVATED,
            'target_type' => 'User',
            'target_id' => $target->id,
            'created_from' => '2026-08-11',
            'created_to' => '2026-08-11',
        ]);

        $this->getJson("/api/admin/action-logs?{$query}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('action_logs.0.id', $matching->id);
    }

    public function test_creating_a_user_writes_an_admin_action_log(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/admin/users', [
                'name' => 'Audited User',
                'email' => 'audited@example.com',
                'password' => 'Password1',
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'audited@example.com')->sole();
        $this->assertActionLogged(
            $admin,
            AdminActionLog::ACTION_USER_CREATED,
            $user,
            '203.0.113.20',
        );
    }

    public function test_deactivating_and_reactivating_a_user_write_action_logs(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/deactivate", [
            'blocked_reason' => 'Sensitive internal reason',
        ])->assertOk();

        $this->assertActionLogged(
            $admin,
            AdminActionLog::ACTION_USER_DEACTIVATED,
            $user,
        );
        $this->assertNull(AdminActionLog::latest('id')->firstOrFail()->metadata);

        $this->patchJson("/api/admin/users/{$user->id}/reactivate")->assertOk();

        $this->assertActionLogged(
            $admin,
            AdminActionLog::ACTION_USER_REACTIVATED,
            $user,
        );
    }

    public function test_viewing_system_logs_and_detailed_health_write_action_logs(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/system/logs')->assertOk();
        $this->getJson('/api/admin/system/health-detailed')->assertOk();

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            'target_type' => null,
            'target_id' => null,
        ]);
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_SYSTEM_HEALTH_VIEWED,
            'target_type' => null,
            'target_id' => null,
        ]);
    }

    public function test_sensitive_metadata_keys_are_recursively_removed_on_write_and_read(): void
    {
        $admin = $this->createAdmin();
        $logger = app(AdminActionLogger::class);
        $request = Request::create('/api/admin/example', server: [
            'REMOTE_ADDR' => '203.0.113.30',
            'HTTP_AUTHORIZATION' => 'Bearer request-token',
            'HTTP_COOKIE' => 'session=request-cookie',
        ]);

        $logger->log($admin, 'safe.action', metadata: [
            'safe_key' => 'safe-value',
            'password' => 'password-value',
            'token' => 'token-value',
            'authorization' => 'Bearer metadata-token',
            'api_key' => 'api-key-value',
            'secret' => 'secret-value',
            'cookie' => 'cookie-value',
            'session' => 'session-value',
            '.env' => 'APP_KEY=env-secret',
            '.env.APP_KEY' => 'nested-env-secret',
            'resend_api_key' => 'resend-secret',
            'ai_provider_api_key' => 'provider-secret',
            'nested' => [
                'safe_nested' => true,
                'access_token' => 'nested-token',
            ],
        ], request: $request);

        $stored = AdminActionLog::query()->sole();
        $serialized = $stored->toJson();

        $this->assertSame([
            'safe_key' => 'safe-value',
            'nested' => ['safe_nested' => true],
        ], $stored->metadata);
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $serialized);
        }

        AdminActionLog::query()->whereKey($stored->id)->update([
            'metadata' => json_encode([
                'safe_key' => 'still-safe',
                'password' => 'legacy-password',
            ]),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/action-logs')->assertOk();
        $this->assertSame(['safe_key' => 'still-safe'], $response->json('action_logs.0.metadata'));
        $this->assertStringNotContainsString('legacy-password', $response->getContent());
    }

    public function test_admin_action_logging_failure_does_not_break_original_actions(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);
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
    private function createLog(User $admin, array $attributes = []): AdminActionLog
    {
        return AdminActionLog::query()->create(array_merge([
            'admin_user_id' => $admin->id,
            'action' => AdminActionLog::ACTION_USER_DEACTIVATED,
            'created_at' => now(),
        ], $attributes));
    }

    private function createLogs(User $admin, int $count): void
    {
        foreach (range(1, $count) as $index) {
            $this->createLog($admin, [
                'action' => "test.action.{$index}",
                'created_at' => now()->subSeconds($index),
            ]);
        }
    }

    private function assertActionLogged(
        User $admin,
        string $action,
        User $target,
        ?string $ipAddress = null,
    ): void {
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_type' => 'User',
            'target_id' => $target->id,
            'ip_address' => $ipAddress ?? '127.0.0.1',
        ]);
    }

    /**
     * @return list<string>
     */
    private function sensitiveValues(): array
    {
        return [
            'password-value',
            'token-value',
            'metadata-token',
            'api-key-value',
            'secret-value',
            'cookie-value',
            'session-value',
            'env-secret',
            'nested-env-secret',
            'resend-secret',
            'provider-secret',
            'nested-token',
            'request-token',
            'request-cookie',
        ];
    }
}
