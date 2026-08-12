<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AdminSystemApiTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        '/api/admin/system/logs',
        '/api/admin/system/health-detailed',
    ];

    private ?string $logBackupPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = storage_path('logs/laravel.log');

        if (is_file($path)) {
            $this->logBackupPath = $path.'.admin-system-test-'.uniqid('', true);
            rename($path, $this->logBackupPath);
        }
    }

    protected function tearDown(): void
    {
        $path = storage_path('logs/laravel.log');

        if (is_file($path)) {
            unlink($path);
        }

        if ($this->logBackupPath !== null && is_file($this->logBackupPath)) {
            rename($this->logBackupPath, $path);
        }

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_system_routes(): void
    {
        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_non_admin_user_cannot_access_system_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden']);
        }
    }

    public function test_inactive_admin_cannot_access_system_routes(): void
    {
        Sanctum::actingAs($this->createAdmin(['is_active' => false]));

        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Account disabled']);
        }
    }

    public function test_missing_log_file_returns_a_safe_empty_response(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/system/logs')
            ->assertOk()
            ->assertJsonPath('lines', [])
            ->assertJsonPath('count', 0)
            ->assertJsonStructure(['lines', 'count', 'generated_at', 'note'])
            ->assertJsonPath(
                'note',
                'The Laravel application log does not exist; no log lines are available.',
            );
    }

    public function test_logs_are_newest_first_and_limited_by_default_and_maximum(): void
    {
        $this->writeLog(collect(range(1, 250))
            ->map(fn (int $number): string => "log-line-{$number}")
            ->implode("\n")."\n");
        Sanctum::actingAs($this->createAdmin());

        $default = $this->getJson('/api/admin/system/logs')
            ->assertOk()
            ->assertJsonPath('count', 100)
            ->assertJsonPath('lines.0', 'log-line-250')
            ->assertJsonPath('lines.99', 'log-line-151');

        $this->assertCount(100, $default->json('lines'));

        $capped = $this->getJson('/api/admin/system/logs?lines=999')
            ->assertOk()
            ->assertJsonPath('count', 200)
            ->assertJsonPath('lines.0', 'log-line-250')
            ->assertJsonPath('lines.199', 'log-line-51');

        $this->assertCount(200, $capped->json('lines'));
    }

    public function test_sensitive_log_values_and_absolute_paths_are_redacted(): void
    {
        $this->writeLog(implode("\n", [
            'Authorization: Bearer token.secret.value',
            '{"Authorization":"Bearer json.token.secret","X-API-Key":"json-api-key"}',
            'API_KEY=top-secret-key password=hunter2 APP_KEY=base64:app-secret',
            'DATABASE_URL=postgresql://admin:db-password@db.internal:5432/auditseo',
            'Cookie: session=private-cookie',
            'Trace at C:\\Users\\private\\project\\app.php and /var/www/private/app.php',
        ])."\n");
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/system/logs')->assertOk();
        $content = implode("\n", $response->json('lines'));

        $this->assertStringContainsString('[REDACTED]', $content);
        $this->assertStringContainsString('[REDACTED_DSN]', $content);
        $this->assertStringContainsString('[REDACTED_PATH]', $content);
        $this->assertStringNotContainsString('token.secret.value', $content);
        $this->assertStringNotContainsString('json.token.secret', $content);
        $this->assertStringNotContainsString('json-api-key', $content);
        $this->assertStringNotContainsString('top-secret-key', $content);
        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('app-secret', $content);
        $this->assertStringNotContainsString('db-password', $content);
        $this->assertStringNotContainsString('private-cookie', $content);
        $this->assertStringNotContainsString('C:\\Users\\private', $content);
        $this->assertStringNotContainsString('/var/www/private', $content);
    }

    public function test_file_path_query_parameters_cannot_change_the_log_target(): void
    {
        $this->writeLog("fixed-laravel-log\n");
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/system/logs?path=../../.env&file=C:\\Windows\\win.ini')
            ->assertOk()
            ->assertJsonPath('lines', ['fixed-laravel-log'])
            ->assertJsonPath('count', 1);
    }

    public function test_admin_can_retrieve_safe_detailed_health_metrics(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $domain = Domain::query()->create([
            'user_id' => $admin->id,
            'domain_name' => 'system-health.example',
            'url' => 'https://system-health.example',
        ]);

        $pending = $this->createAudit($domain, Audit::STATUS_PENDING);
        Audit::withoutTimestamps(fn () => $pending->forceFill([
            'created_at' => now()->subMinutes(11),
        ])->save());
        $this->createAudit($domain, Audit::STATUS_RUNNING, [
            'started_at' => now()->subMinutes(16),
        ]);
        $this->createAudit($domain, Audit::STATUS_FAILED, [
            'failed_at' => now()->subMinutes(30),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'system-health-failed-job',
            'connection' => 'redis',
            'queue' => 'audits',
            'payload' => '{}',
            'exception' => 'redacted test exception',
            'failed_at' => now()->subMinutes(20),
        ]);
        AccessLog::query()->create([
            'user_id' => $admin->id,
            'method' => 'GET',
            'route' => '/api/me',
            'status_code' => 200,
            'created_at' => now()->subHour(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/system/health-detailed')
            ->assertOk()
            ->assertJsonPath('app_env', 'testing')
            ->assertJsonPath('debug_enabled', true)
            ->assertJsonPath('database_status', 'ok')
            ->assertJsonPath('redis_status', 'not_required')
            ->assertJsonPath('cache_status', 'ok')
            ->assertJsonPath('queue_connection', 'sync')
            ->assertJsonPath('cache_driver', 'array')
            ->assertJsonPath('stale_pending_audits', 1)
            ->assertJsonPath('stale_running_audits', 1)
            ->assertJsonPath('recent_failed_audits', 1)
            ->assertJsonPath('recent_failed_jobs', 1)
            ->assertJsonPath('access_logs_last_24h', 1)
            ->assertJsonPath('generated_at', now()->toJSON())
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_detailed_health_never_serializes_infrastructure_exception_details(): void
    {
        Sanctum::actingAs($this->createAdmin());
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException(
                'postgresql://admin:db-secret@db.internal/auditseo password=hunter2 token=abc',
            ));

        $response = $this->getJson('/api/admin/system/health-detailed')
            ->assertOk()
            ->assertJsonPath('database_status', 'unavailable')
            ->assertJsonPath('stale_pending_audits', null)
            ->assertJsonPath('recent_failed_jobs', null);

        $content = strtolower($response->getContent());

        foreach (['db-secret', 'db.internal', 'hunter2', 'postgresql://', 'password', 'token', 'exception', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_existing_health_endpoints_keep_their_current_behavior(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->getJson('/api/health/readiness')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
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
    private function createAudit(Domain $domain, string $status, array $attributes = []): Audit
    {
        return Audit::query()->create(array_merge([
            'domain_id' => $domain->id,
            'requested_url' => 'https://system-health.example',
            'status' => $status,
        ], $attributes));
    }

    private function writeLog(string $contents): void
    {
        $directory = storage_path('logs');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(storage_path('logs/laravel.log'), $contents);
    }
}
