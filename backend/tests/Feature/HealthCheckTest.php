<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_public_health_endpoint_reports_a_healthy_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'app' => 'AuditSEO API',
                'database' => 'ok',
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
                'app' => 'AuditSEO API',
                'database' => 'error',
            ]);

        $content = $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('/var/www/app.php', $content);
        $this->assertStringNotContainsString('secret', $content);
        $this->assertStringNotContainsString('trace', $content);
    }
}
