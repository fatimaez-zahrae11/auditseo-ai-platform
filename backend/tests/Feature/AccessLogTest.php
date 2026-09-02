<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_log_is_created_for_an_authenticated_api_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('access-log-test');

        $this->withToken($token->plainTextToken)
            ->withHeader('User-Agent', 'AccessLogTest/1.0')
            ->getJson('/api/me')
            ->assertOk();

        $log = AccessLog::sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('GET', $log->method);
        $this->assertSame('/api/me', $log->route);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('AccessLogTest/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }

    public function test_public_health_and_unauthenticated_readiness_are_not_logged(): void
    {
        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health/readiness')->assertUnauthorized();

        $this->assertDatabaseCount('access_logs', 0);
    }

    public function test_authenticated_readiness_can_be_logged_without_response_details(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $token = $user->createToken('readiness-token');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/health/readiness')
            ->assertOk();

        $log = AccessLog::sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('/api/health/readiness', $log->route);
        $this->assertArrayNotHasKey('checks', $log->getAttributes());
        $this->assertArrayNotHasKey('audit_counts', $log->getAttributes());
    }

    public function test_authorization_body_query_string_and_cookies_are_never_logged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('private-token');
        $authorizationSecret = $token->plainTextToken;
        $bodySecret = 'BodyPassword9';
        $querySecret = 'query-api-key-secret';
        $cookieSecret = 'cookie-secret';

        $this->withToken($authorizationSecret)
            ->withCookie('private_session', $cookieSecret)
            ->postJson("/api/logout?api_key={$querySecret}", [
                'password' => $bodySecret,
                'api_key' => 'body-api-key-secret',
            ])
            ->assertOk();

        $log = AccessLog::sole();
        $serializedLog = $log->toJson();

        $this->assertSame('/api/logout', $log->route);
        $this->assertSame($user->id, $log->user_id);
        $this->assertStringNotContainsString($authorizationSecret, $serializedLog);
        $this->assertStringNotContainsString($bodySecret, $serializedLog);
        $this->assertStringNotContainsString($querySecret, $serializedLog);
        $this->assertStringNotContainsString($cookieSecret, $serializedLog);
        $this->assertStringNotContainsString('body-api-key-secret', $serializedLog);
        $this->assertEqualsCanonicalizing([
            'id',
            'user_id',
            'ip_address',
            'method',
            'route',
            'status_code',
            'user_agent',
            'created_at',
        ], array_keys($log->getAttributes()));
    }

    public function test_unauthenticated_request_is_logged_with_null_user_id(): void
    {
        $this->getJson('/api/me?password=not-stored')
            ->assertUnauthorized();

        $log = AccessLog::sole();

        $this->assertNull($log->user_id);
        $this->assertSame('/api/me', $log->route);
        $this->assertSame(401, $log->status_code);
        $this->assertStringNotContainsString('not-stored', $log->toJson());
    }

    public function test_access_logging_failure_does_not_break_the_api_response(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('logging-failure-token');
        Schema::drop('access_logs');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }
}
