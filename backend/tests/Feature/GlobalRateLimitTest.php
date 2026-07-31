<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
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
        $this->assertRouteHasMiddleware('GET', 'api/me', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:30,1',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/audits', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:10,60',
        ]);
        $this->assertRouteHasMiddleware('POST', 'api/audits/{audit}/recommendations', [
            'auth:sanctum',
            'throttle:api-authenticated',
            'throttle:5,1',
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
