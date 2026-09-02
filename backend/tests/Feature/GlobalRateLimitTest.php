<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottlePreAuthentication;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_ip_cannot_exceed_the_public_api_limit_and_health_remains_available(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);

        foreach (range(1, 120) as $attempt) {
            $this->getJson('/api/health')
                ->assertOk()
                ->assertJsonPath('status', 'ok');
        }

        $response = $this->getJson('/api/health');

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->assertSanitizedRateLimitResponse($response->getContent());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_authenticated_activity_limit_is_shared_by_user_across_ip_addresses(): void
    {
        Route::middleware('throttle:api-authenticated')
            ->get('/api/test-authenticated-activity-limit', fn () => response()->json([
                'status' => 'ok',
            ]));

        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, 300) as $attempt) {
            $this->withServerVariables([
                'REMOTE_ADDR' => $attempt <= 150 ? '198.51.100.20' : '198.51.100.21',
            ])->getJson('/api/test-authenticated-activity-limit')
                ->assertOk();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->getJson('/api/test-authenticated-activity-limit');

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->assertSanitizedRateLimitResponse($response->getContent());
    }

    public function test_authenticated_activity_limiter_falls_back_to_ip_without_a_user(): void
    {
        Route::middleware('throttle:api-authenticated')
            ->get('/api/test-authenticated-activity-ip-fallback', fn () => response()->json([
                'status' => 'ok',
            ]));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30']);

        foreach (range(1, 300) as $attempt) {
            $this->getJson('/api/test-authenticated-activity-ip-fallback')
                ->assertOk();
        }

        $response = $this->getJson('/api/test-authenticated-activity-ip-fallback');

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->assertSanitizedRateLimitResponse($response->getContent());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.31'])
            ->getJson('/api/test-authenticated-activity-ip-fallback')
            ->assertOk();
    }

    public function test_dashboard_read_limit_is_generous_and_shared_by_authenticated_user(): void
    {
        Route::middleware('throttle:dashboard-read')
            ->get('/api/test-dashboard-read-limit', fn () => response()->json([
                'status' => 'ok',
            ]));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach (range(1, 120) as $attempt) {
            $this->withServerVariables([
                'REMOTE_ADDR' => $attempt <= 60 ? '198.51.100.40' : '198.51.100.41',
            ])->getJson('/api/test-dashboard-read-limit')
                ->assertOk();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->getJson('/api/test-dashboard-read-limit');

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->assertSanitizedRateLimitResponse($response->getContent());

        Sanctum::actingAs(User::factory()->create());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->getJson('/api/test-dashboard-read-limit')
            ->assertOk();
    }

    public function test_audit_read_limit_is_generous_and_shared_by_authenticated_user(): void
    {
        Route::middleware('throttle:audit-read')
            ->get('/api/test-audit-read-limit', fn () => response()->json([
                'status' => 'ok',
            ]));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach (range(1, 120) as $attempt) {
            $this->withServerVariables([
                'REMOTE_ADDR' => $attempt <= 60 ? '198.51.100.50' : '198.51.100.51',
            ])->getJson('/api/test-audit-read-limit')
                ->assertOk();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.52'])
            ->getJson('/api/test-audit-read-limit');

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->assertSanitizedRateLimitResponse($response->getContent());

        Sanctum::actingAs(User::factory()->create());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.52'])
            ->getJson('/api/test-audit-read-limit')
            ->assertOk();
    }

    public function test_recommendation_audit_cooldown_is_independent_from_reads_and_scoped_by_user(): void
    {
        Route::middleware('throttle:recommendation-read')
            ->get('/api/test-recommendation-limit/{audit}', fn () => response()->json([
                'status' => 'ok',
            ]));
        Route::middleware('throttle:recommendation-generate-audit')
            ->post('/api/test-recommendation-limit/{audit}', fn () => response()->json([
                'status' => 'ok',
            ]));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/test-recommendation-limit/100')->assertOk();
        $this->postJson('/api/test-recommendation-limit/100')->assertOk();

        $this->postJson('/api/test-recommendation-limit/100')
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests.',
            ]);

        $this->getJson('/api/test-recommendation-limit/100')->assertOk();
        $this->postJson('/api/test-recommendation-limit/101')->assertOk();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/test-recommendation-limit/100')->assertOk();
    }

    public function test_recommendation_short_window_quota_is_shared_across_audits_for_one_user(): void
    {
        Route::middleware('throttle:recommendation-generate-user')
            ->post('/api/test-recommendation-user-limit/{audit}', fn () => response()->json(['status' => 'ok']));

        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, 10) as $audit) {
            $this->postJson("/api/test-recommendation-user-limit/{$audit}")->assertOk();
        }

        $this->postJson('/api/test-recommendation-user-limit/11')
            ->assertTooManyRequests();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/test-recommendation-user-limit/12')->assertOk();
    }

    public function test_recommendation_daily_quota_is_shared_across_audits_for_one_user(): void
    {
        Route::middleware('throttle:recommendation-generate-daily')
            ->post('/api/test-recommendation-daily-limit/{audit}', fn () => response()->json(['status' => 'ok']));

        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, 50) as $audit) {
            $this->postJson("/api/test-recommendation-daily-limit/{$audit}")->assertOk();
        }

        $this->postJson('/api/test-recommendation-daily-limit/51')
            ->assertTooManyRequests();
    }

    public function test_recommendation_global_provider_quota_is_shared_across_callers(): void
    {
        Route::middleware('throttle:recommendation-generate-global')
            ->post('/api/test-recommendation-global-limit/{audit}', fn () => response()->json(['status' => 'ok']));

        foreach (range(1, 300) as $audit) {
            $this->postJson("/api/test-recommendation-global-limit/{$audit}")->assertOk();
        }

        $this->postJson('/api/test-recommendation-global-limit/301')
            ->assertTooManyRequests();
    }

    public function test_admin_read_mutation_and_expensive_limits_are_enforced(): void
    {
        Route::middleware('throttle:admin-read')
            ->get('/api/test-admin-read-limit', fn () => response()->json(['status' => 'ok']));
        Route::middleware('throttle:admin-mutation')
            ->post('/api/test-admin-mutation-limit', fn () => response()->json(['status' => 'ok']));
        Route::middleware('throttle:admin-expensive')
            ->get('/api/test-admin-expensive-limit', fn () => response()->json(['status' => 'ok']));

        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, 240) as $attempt) {
            $this->getJson('/api/test-admin-read-limit')->assertOk();
        }
        $this->getJson('/api/test-admin-read-limit')->assertTooManyRequests();

        foreach (range(1, 30) as $attempt) {
            $this->postJson('/api/test-admin-mutation-limit')->assertOk();
        }
        $this->postJson('/api/test-admin-mutation-limit')->assertTooManyRequests();

        foreach (range(1, 60) as $attempt) {
            $this->getJson('/api/test-admin-expensive-limit')->assertOk();
        }
        $this->getJson('/api/test-admin-expensive-limit')->assertTooManyRequests();
    }

    public function test_pre_auth_ip_limit_runs_before_sanctum_authentication(): void
    {
        RateLimiter::for('security-test-pre-auth', fn ($request) => Limit::perMinute(2)
            ->by('security-test-pre-auth:'.$request->ip()));
        Route::middleware([
            ThrottlePreAuthentication::class.':security-test-pre-auth',
            'auth:sanctum',
        ])->get('/api/test-pre-auth-limit', fn () => response()->json(['status' => 'ok']));

        $this->withHeader('Authorization', 'Bearer invalid-token');
        $this->getJson('/api/test-pre-auth-limit')->assertUnauthorized();
        $this->getJson('/api/test-pre-auth-limit')->assertUnauthorized();
        $this->getJson('/api/test-pre-auth-limit')->assertTooManyRequests();
    }

    public function test_global_limiters_are_layered_without_removing_specific_limits(): void
    {
        $this->assertRouteHasMiddleware('GET', 'api/health', [
            'throttle:api-public',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/register', [
            'throttle:api-public',
            'throttle:register',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/login', [
            'throttle:api-public',
            'throttle:login',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/email/verification-notification', [
            'throttle:api-public',
            'throttle:verification',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/analytics/page-view', [
            'throttle:analytics-page-view',
        ]);
        $this->assertRouteHasMiddleware('GET', 'api/me', [
            ThrottlePreAuthentication::class.':api-pre-auth',
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:30,1',
        ]);
        $this->assertRouteHasMiddleware('GET', 'api/dashboard', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:dashboard-read',
        ]);
        $this->assertRouteExcludesMiddleware('GET', 'api/dashboard', 'throttle:30,1');
        $this->assertRouteHasMiddleware('GET', 'api/audits', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:audit-read',
        ]);
        $this->assertRouteExcludesMiddleware('GET', 'api/audits', 'throttle:30,1');
        $this->assertRouteHasMiddleware('GET', 'api/audits/{id}', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:audit-read',
        ]);
        $this->assertRouteExcludesMiddleware('GET', 'api/audits/{id}', 'throttle:30,1');
        $this->assertRouteHasMiddleware('GET', 'api/audits/{audit}/recommendations', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:recommendation-read',
        ]);
        $this->assertRouteExcludesMiddleware('GET', 'api/audits/{audit}/recommendations', 'throttle:30,1');
        $this->assertRouteHasMiddleware('POST', 'api/audits', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:10,60',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/audits/{audit}/recommendations', [
            ThrottlePreAuthentication::class.':api-pre-auth',
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:recommendation-generate-audit',
            'throttle:recommendation-generate-user',
            'throttle:recommendation-generate-daily',
            'throttle:recommendation-generate-global',
        ]);
        $this->assertRouteExcludesMiddleware('POST', 'api/audits/{audit}/recommendations', 'throttle:30,1');

        $this->assertRouteHasMiddleware('GET', 'api/admin/users', [
            ThrottlePreAuthentication::class.':api-pre-auth',
            'auth:sanctum',
            'admin',
            'throttle:admin-read',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/admin/users', [
            ThrottlePreAuthentication::class.':api-pre-auth',
            'auth:sanctum',
            'admin',
            'throttle:admin-mutation',
        ]);
        $this->assertRouteHasMiddleware('GET', 'api/admin/analytics/overview', [
            ThrottlePreAuthentication::class.':api-pre-auth',
            'auth:sanctum',
            'admin',
            'throttle:admin-read',
            'throttle:admin-expensive',
        ]);
        $this->assertRouteHasMiddleware('GET', 'api/health/readiness', [
            'auth:sanctum',
            'admin',
            'verified',
            'throttle:admin-read',
            'throttle:admin-expensive',
        ]);
    }

    public function test_rate_limiter_uses_the_array_cache_store_during_tests(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('cache.limiter'));
    }

    /**
     * @param  list<string>  $expectedMiddleware
     */
    private function assertRouteHasMiddleware(
        string $method,
        string $uri,
        array $expectedMiddleware,
    ): void {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (IlluminateRoute $route): bool => $route->uri() === $uri
                && in_array($method, $route->methods(), true));

        $this->assertInstanceOf(IlluminateRoute::class, $route);

        foreach ($expectedMiddleware as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    private function assertRouteExcludesMiddleware(
        string $method,
        string $uri,
        string $unexpectedMiddleware,
    ): void {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (IlluminateRoute $route): bool => $route->uri() === $uri
                && in_array($method, $route->methods(), true));

        $this->assertInstanceOf(IlluminateRoute::class, $route);
        $this->assertContains($unexpectedMiddleware, $route->excludedMiddleware());
    }

    private function assertSanitizedRateLimitResponse(string $content): void
    {
        foreach ([
            'exception',
            'trace',
            'stack',
            'SQLSTATE',
            '.php',
            'password',
            'secret',
            'Redis',
            'cache',
        ] as $sensitiveDetail) {
            $this->assertStringNotContainsString($sensitiveDetail, $content);
        }
    }
}
