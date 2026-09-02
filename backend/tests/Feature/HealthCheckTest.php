<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_endpoint_stays_generic_when_healthy(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
            ]);
    }

    public function test_database_failure_returns_a_safe_degraded_response(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException(
                'SQLSTATE connection failed at /var/www/app.php with password=secret',
            ));

        $response = $this->getJson('/api/health');

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'degraded',
            ]);

        $this->assertResponseContainsNoInfrastructureDetails($response->getContent());
    }

    public function test_detailed_readiness_requires_a_verified_admin(): void
    {
        $this->getJson('/api/health/readiness')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/health/readiness')->assertForbidden();

        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->getJson('/api/health/readiness')->assertForbidden();

        Sanctum::actingAs($this->createAdmin(unverified: true));
        $this->getJson('/api/health/readiness')->assertForbidden();
    }

    public function test_readiness_checks_database_and_reports_a_healthy_audit_queue(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/health/readiness')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'redis' => 'not_required',
                    'audit_queue' => 'ok',
                ],
                'audit_counts' => [
                    'stale_pending' => 0,
                    'stale_running' => 0,
                    'recent_failed' => 0,
                ],
            ]);
    }

    public function test_readiness_database_failure_is_safe_and_skips_audit_counts(): void
    {
        Sanctum::actingAs($this->createAdmin());
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException(
                'Database db.internal:5432 rejected password=secret at /var/www/app.php',
            ));

        $response = $this->getJson('/api/health/readiness');

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'error',
                    'redis' => 'not_required',
                    'audit_queue' => 'not_checked',
                ],
            ]);

        $this->assertResponseContainsNoInfrastructureDetails($response->getContent());
        $this->assertStringNotContainsString('db.internal', $response->getContent());
        $this->assertStringNotContainsString('5432', $response->getContent());
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
    }

    public function test_readiness_checks_redis_when_the_queue_uses_it(): void
    {
        config([
            'queue.default' => 'redis',
            'cache.default' => 'array',
        ]);
        Sanctum::actingAs($this->createAdmin());

        $connection = Mockery::mock();
        $connection->shouldReceive('command')->once()->with('ping')->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        $this->getJson('/api/health/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.redis', 'ok');
    }

    public function test_redis_cache_failure_returns_only_safe_readiness_details(): void
    {
        config([
            'queue.default' => 'sync',
            'cache.default' => 'redis',
        ]);
        Sanctum::actingAs($this->createAdmin());

        Redis::shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andThrow(new RuntimeException(
                'Connection refused for redis://:super-secret@redis.internal:6379 at C:\\app\\vendor\\client.php',
            ));

        $response = $this->getJson('/api/health/readiness');

        $response
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'error')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->assertResponseContainsNoInfrastructureDetails($response->getContent());
    }

    public function test_readiness_detects_stale_pending_and_running_audits(): void
    {
        $this->travelTo(now()->startOfSecond());
        Sanctum::actingAs($user = $this->createAdmin());
        $domain = $this->createDomain($user);

        $stalePending = $this->createAudit($domain, [
            'status' => Audit::STATUS_PENDING,
        ]);
        Audit::withoutTimestamps(fn () => $stalePending
            ->forceFill(['created_at' => now()->subMinutes(11)])
            ->save());

        $this->createAudit($domain, [
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now()->subMinutes(16),
        ]);
        $this->createAudit($domain, [
            'status' => Audit::STATUS_FAILED,
            'failed_at' => now()->subMinutes(30),
        ]);

        $this->getJson('/api/health/readiness')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.audit_queue', 'at_risk')
            ->assertJsonPath('audit_counts.stale_pending', 1)
            ->assertJsonPath('audit_counts.stale_running', 1)
            ->assertJsonPath('audit_counts.recent_failed', 1);
    }

    public function test_pending_and_running_audits_younger_than_their_thresholds_are_not_stale(): void
    {
        $this->travelTo(now()->startOfSecond());
        Sanctum::actingAs($user = $this->createAdmin());
        $domain = $this->createDomain($user);

        $this->assertSame(15, config('health.audit_queue.stale_running_minutes'));

        $freshPending = $this->createAudit($domain, [
            'status' => Audit::STATUS_PENDING,
        ]);
        Audit::withoutTimestamps(fn () => $freshPending
            ->forceFill(['created_at' => now()->subMinutes(9)])
            ->save());
        $this->createAudit($domain, [
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now()->subMinutes(14),
        ]);

        $this->getJson('/api/health/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.audit_queue', 'ok')
            ->assertJsonPath('audit_counts.stale_pending', 0)
            ->assertJsonPath('audit_counts.stale_running', 0);
    }

    public function test_stale_running_threshold_is_configurable(): void
    {
        $this->travelTo(now()->startOfSecond());
        Sanctum::actingAs($user = $this->createAdmin());
        $domain = $this->createDomain($user);

        $this->createAudit($domain, [
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now()->subMinutes(16),
        ]);

        config(['health.audit_queue.stale_running_minutes' => 20]);

        $this->getJson('/api/health/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('audit_counts.stale_running', 0);

        config(['health.audit_queue.stale_running_minutes' => 10]);

        $this->getJson('/api/health/readiness')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.audit_queue', 'at_risk')
            ->assertJsonPath('audit_counts.stale_running', 1);
    }

    private function createDomain(User $user): Domain
    {
        return Domain::query()->create([
            'user_id' => $user->id,
            'domain_name' => 'health-check.example',
            'url' => 'https://health-check.example',
        ]);
    }

    private function createAdmin(bool $unverified = false): User
    {
        $factory = User::factory();
        $admin = ($unverified ? $factory->unverified() : $factory)->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAudit(Domain $domain, array $attributes = []): Audit
    {
        return Audit::query()->create(array_merge([
            'domain_id' => $domain->id,
            'requested_url' => 'https://health-check.example',
        ], $attributes));
    }

    private function assertResponseContainsNoInfrastructureDetails(string $content): void
    {
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('redis.internal', $content);
        $this->assertStringNotContainsString('6379', $content);
        $this->assertStringNotContainsString('super-secret', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('C:\\\\app', $content);
        $this->assertStringNotContainsString('exception', strtolower($content));
        $this->assertStringNotContainsString('trace', strtolower($content));
    }
}
