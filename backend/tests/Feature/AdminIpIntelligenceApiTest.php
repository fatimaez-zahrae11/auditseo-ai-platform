<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\IpGeolocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminIpIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/security/ip-intelligence';

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_regular_user_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);
    }

    public function test_admin_receives_a_clean_empty_response(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('summary.unique_ips', 0)
            ->assertJsonPath('summary.requests_count', 0)
            ->assertJsonPath('summary.errors_count', 0)
            ->assertJsonCount(0, 'top_addresses_heatmap')
            ->assertJsonCount(0, 'map_points')
            ->assertJsonCount(0, 'external_exposure')
            ->assertJsonCount(0, 'results')
            ->assertJsonPath('metadata.period', '24h')
            ->assertJsonPath('metadata.ip_display', 'masked');
    }

    public function test_period_and_risk_filters_are_validated(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson(self::ENDPOINT.'?period=90d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->getJson(self::ENDPOINT.'?risk=extreme')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('risk');
    }

    public function test_private_address_is_cached_and_returned_as_local_network(): void
    {
        $this->travelTo('2026-08-31 12:00:00');
        $admin = $this->createAdmin();
        $this->createAccessLog($admin, '10.20.30.40', 200);
        Sanctum::actingAs($admin);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('results.0.ip_masked', '10.xxx.xxx.40')
            ->assertJsonPath('results.0.country_name', 'Local network')
            ->assertJsonPath('results.0.city', null)
            ->assertJsonPath('results.0.latitude', null)
            ->assertJsonCount(0, 'map_points');

        $this->assertDatabaseHas('ip_geolocations', [
            'ip_hash' => hash('sha256', '10.20.30.40'),
            'country_name' => 'Local network',
            'source' => 'local',
        ]);
    }

    public function test_cached_public_geolocation_and_real_status_aggregates_are_returned(): void
    {
        $this->travelTo('2026-08-31 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        IpGeolocation::create([
            'ip_hash' => hash('sha256', '8.8.8.8'),
            'ip_masked' => '8.xxx.xxx.8',
            'country_code' => 'US',
            'country_name' => 'United States',
            'region' => 'California',
            'city' => 'Mountain View',
            'latitude' => 37.386,
            'longitude' => -122.0838,
            'source' => 'maxmind-geolite2',
            'resolved_at' => now(),
        ]);
        foreach ([200, 401, 403, 404, 429, 500] as $index => $status) {
            $this->createAccessLog($user, '8.8.8.8', $status, "/api/route-{$index}");
        }
        Sanctum::actingAs($admin);

        $response = $this->getJson(self::ENDPOINT);

        $response
            ->assertOk()
            ->assertJsonPath('summary.unique_ips', 1)
            ->assertJsonPath('summary.countries_count', 1)
            ->assertJsonPath('summary.requests_count', 6)
            ->assertJsonPath('summary.errors_count', 5)
            ->assertJsonPath('results.0.country_name', 'United States')
            ->assertJsonPath('results.0.city', 'Mountain View')
            ->assertJsonPath('results.0.request_count', 6)
            ->assertJsonPath('results.0.error_count', 5)
            ->assertJsonPath('results.0.status_401_count', 1)
            ->assertJsonPath('results.0.status_403_count', 1)
            ->assertJsonPath('results.0.status_404_count', 1)
            ->assertJsonPath('results.0.status_429_count', 1)
            ->assertJsonPath('results.0.status_5xx_count', 1)
            ->assertJsonPath('results.0.distinct_routes_count', 6)
            ->assertJsonPath('results.0.distinct_users_count', 1)
            ->assertJsonPath('results.0.users.0.id', $user->id)
            ->assertJsonCount(1, 'map_points')
            ->assertJsonPath('map_points.0.latitude', 37.386)
            ->assertJsonPath('map_points.0.longitude', -122.0838);
    }

    public function test_risk_filter_uses_only_deterministic_logged_signals(): void
    {
        $this->travelTo('2026-08-31 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $this->createAccessLog($user, '1.1.1.1', 200);
        foreach (range(1, 80) as $index) {
            $status = $index <= 6 ? 404 : 200;
            $this->createAccessLog($user, '9.9.9.9', $status, "/api/probe-{$index}");
        }
        Sanctum::actingAs($admin);

        $this->getJson(self::ENDPOINT.'?risk=medium')
            ->assertOk()
            ->assertJsonPath('summary.medium', 1)
            ->assertJsonPath('summary.low', 0)
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.ip_masked', '9.xxx.xxx.9')
            ->assertJsonPath('results.0.risk_score', 27)
            ->assertJsonPath('results.0.risk_reason', 'Repeated 404 scanning');
    }

    public function test_response_never_contains_raw_addresses_or_sensitive_log_data(): void
    {
        $admin = $this->createAdmin();
        $this->createAccessLog(
            $admin,
            '8.8.4.4',
            403,
            '/api/private-token-route',
            'Authorization Bearer private-token password=secret api_key=secret',
        );
        Sanctum::actingAs($admin);

        $content = $this->getJson(self::ENDPOINT)->assertOk()->getContent();

        $this->assertStringNotContainsString('8.8.4.4', $content);
        $this->assertStringNotContainsString('/api/private-token-route', $content);
        $this->assertStringNotContainsString('private-token', $content);
        $this->assertStringNotContainsString('password=secret', $content);
        $this->assertStringNotContainsString('api_key=secret', $content);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
    }

    private function createAccessLog(
        User $user,
        string $ipAddress,
        int $statusCode,
        string $route = '/api/me',
        ?string $userAgent = null,
    ): AccessLog {
        return AccessLog::create([
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'method' => 'GET',
            'route' => $route,
            'status_code' => $statusCode,
            'user_agent' => $userAgent,
            'created_at' => now()->subMinute(),
        ]);
    }
}
