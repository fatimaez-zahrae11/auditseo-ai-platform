<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        '/api/admin/analytics/overview',
        '/api/admin/analytics/traffic',
        '/api/admin/analytics/web-traffic',
        '/api/admin/analytics/active-users',
        '/api/admin/analytics/heavy-users',
    ];

    public function test_unauthenticated_user_cannot_access_analytics_routes(): void
    {
        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_authenticated_non_admin_user_cannot_access_analytics_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Forbidden',
                ]);
        }
    }

    public function test_inactive_admin_cannot_access_analytics_routes(): void
    {
        $admin = $this->createAdmin();
        $admin->is_active = false;
        $admin->save();
        Sanctum::actingAs($admin);

        foreach (self::ROUTES as $route) {
            $this->getJson($route)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Account disabled',
                ]);
        }
    }

    public function test_admin_can_get_overview_metrics(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $activeUser = User::factory()->create();
        $inactiveUser = User::factory()->create();
        $inactiveUser->is_active = false;
        $inactiveUser->save();

        $pending = $this->createAuditFor($activeUser, Audit::STATUS_PENDING);
        $this->createAuditFor($activeUser, Audit::STATUS_RUNNING);
        $completed = $this->createAuditFor($activeUser, Audit::STATUS_COMPLETED);
        $this->createAuditFor($inactiveUser, Audit::STATUS_FAILED);
        $this->createRecommendation($pending, now()->subHour());
        $this->createRecommendation($completed, now()->subDays(2));

        $this->createAccessLog($activeUser, now()->subMinutes(10));
        $this->createAccessLog($activeUser, now()->subHours(23));
        $this->createAccessLog($activeUser, now()->subDays(3));

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonPath('total_users', 3)
            ->assertJsonPath('active_users', 1)
            ->assertJsonPath('inactive_users', 1)
            ->assertJsonPath('admin_users', 1)
            ->assertJsonPath('total_audits', 4)
            ->assertJsonPath('pending_audits', 1)
            ->assertJsonPath('running_audits', 1)
            ->assertJsonPath('completed_audits', 1)
            ->assertJsonPath('failed_audits', 1)
            ->assertJsonPath('total_recommendations', 2)
            ->assertJsonPath('requests_last_24h', 2)
            ->assertJsonPath('requests_last_7d', 3)
            ->assertJsonPath('generated_at', now()->toJSON())
            ->assertJsonPath('metadata.active_users_window_minutes', 15)
            ->assertJsonPath(
                'metadata.active_users_definition',
                fn (string $definition): bool => str_contains(
                    $definition,
                    'not true real-time online presence',
                ),
            );
    }

    public function test_active_users_returns_only_users_with_activity_in_last_fifteen_minutes(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $recentUser = User::factory()->create();
        $inactiveButRecentUser = User::factory()->create();
        $inactiveButRecentUser->is_active = false;
        $inactiveButRecentUser->save();
        $staleUser = User::factory()->create();

        $this->createAccessLog($recentUser, now()->subMinutes(5), [
            'ip_address' => '203.0.113.10',
        ]);
        $this->createAccessLog($recentUser, now()->subHours(2));
        $this->createAccessLog($inactiveButRecentUser, now()->subMinutes(14));
        $this->createAccessLog($staleUser, now()->subMinutes(16));

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/analytics/active-users');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('users.0.id', $recentUser->id)
            ->assertJsonPath('users.0.last_ip', '203.0.113.10')
            ->assertJsonPath('users.0.request_count_last_15m', 1)
            ->assertJsonPath('users.0.request_count_last_24h', 2)
            ->assertJsonPath('users.1.id', $inactiveButRecentUser->id)
            ->assertJsonPath('users.1.is_active', false)
            ->assertJsonMissing(['id' => $staleUser->id])
            ->assertJsonPath('metadata.window_minutes', 15)
            ->assertJsonPath(
                'metadata.definition',
                fn (string $definition): bool => str_contains(
                    $definition,
                    'activity-based and not true real-time online presence',
                ),
            );
    }

    public function test_admin_can_get_real_traffic_aggregates_with_default_period(): void
    {
        $this->travelTo('2026-08-12 12:34:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, '2026-08-10 09:00:00');
        $this->createRecommendation($audit, '2026-08-10 10:00:00');
        $this->createAccessLog($user, '2026-08-10 08:00:00');
        $this->createAccessLog($user, '2026-08-10 11:00:00', ['status_code' => 422]);
        $this->createAccessLog($user, '2026-08-01 11:00:00', ['status_code' => 500]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/analytics/traffic');

        $response
            ->assertOk()
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('totals.requests', 2)
            ->assertJsonPath('totals.audits', 1)
            ->assertJsonPath('totals.recommendations', 1)
            ->assertJsonPath('totals.http_errors', 1)
            ->assertJsonPath('metadata.period', '7d')
            ->assertJsonPath('metadata.granularity', 'day')
            ->assertJsonPath('metadata.generated_at', now()->toJSON());

        $point = collect($response->json('series'))->firstWhere('period', '2026-08-10');
        $this->assertSame(2, $point['requests']);
        $this->assertSame(1, $point['audits']);
        $this->assertSame(1, $point['recommendations']);
        $this->assertSame(1, $point['http_errors']);
    }

    public function test_traffic_uses_hourly_granularity_by_default_for_twenty_four_hours(): void
    {
        $this->travelTo('2026-08-12 12:34:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $this->createAccessLog($user, '2026-08-12 11:15:00');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/analytics/traffic?period=24h')
            ->assertOk()
            ->assertJsonCount(24, 'series')
            ->assertJsonPath('metadata.period', '24h')
            ->assertJsonPath('metadata.granularity', 'hour')
            ->assertJsonPath('totals.requests', 1);
    }

    public function test_traffic_period_and_granularity_are_validated(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/analytics/traffic?period=90d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->getJson('/api/admin/analytics/traffic?granularity=minute')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('granularity');
    }

    public function test_empty_traffic_period_returns_zero_filled_real_buckets(): void
    {
        $this->travelTo('2026-08-12 12:34:00');
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/analytics/traffic?period=30d');

        $response
            ->assertOk()
            ->assertJsonCount(30, 'series')
            ->assertJsonPath('totals.requests', 0)
            ->assertJsonPath('totals.audits', 0)
            ->assertJsonPath('totals.recommendations', 0)
            ->assertJsonPath('totals.http_errors', 0);
        $this->assertTrue(collect($response->json('series'))->every(
            fn (array $point): bool => $point['requests'] === 0
                && $point['audits'] === 0
                && $point['recommendations'] === 0
                && $point['http_errors'] === 0,
        ));
    }

    public function test_heavy_users_use_real_metrics_and_deterministic_usage_score(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $heavyUser = User::factory()->create();
        $lighterUser = User::factory()->create();

        foreach (range(1, 5) as $index) {
            $this->createAccessLog($heavyUser, now()->subHours($index), [
                'status_code' => $index === 1 ? 500 : 200,
            ]);
        }

        foreach (range(1, 2) as $index) {
            $this->createAccessLog($lighterUser, now()->subHours($index));
        }

        $completed = $this->createAuditFor(
            $heavyUser,
            Audit::STATUS_COMPLETED,
            now()->subDay(),
        );
        $this->createAuditFor($heavyUser, Audit::STATUS_FAILED, now()->subDays(2));
        $this->createAuditFor($lighterUser, Audit::STATUS_PENDING, now()->subDay());
        $this->createRecommendation($completed, now()->subHours(12));

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/analytics/heavy-users');

        $response
            ->assertOk()
            ->assertJsonPath('users.0.id', $heavyUser->id)
            ->assertJsonPath('users.0.role', User::ROLE_USER)
            ->assertJsonPath('users.0.is_active', true)
            ->assertJsonPath('users.0.requests_count', 5)
            ->assertJsonPath('users.0.error_requests_count', 1)
            ->assertJsonPath('users.0.audits_count', 2)
            ->assertJsonPath('users.0.completed_audits_count', 1)
            ->assertJsonPath('users.0.failed_audits_count', 1)
            ->assertJsonPath('users.0.recommendations_count', 1)
            ->assertJsonPath('users.0.last_seen_at', now()->subHour()->toJSON())
            ->assertJsonPath('users.0.usage_score', 35)
            ->assertJsonPath('users.1.id', $lighterUser->id)
            ->assertJsonPath('users.1.requests_count', 2)
            ->assertJsonPath('users.1.usage_score', 12)
            ->assertJsonPath('metadata.period', '7d')
            ->assertJsonPath(
                'metadata.usage_score_formula',
                'requests + audits * 10 + recommendations * 8 + errors * 2',
            )
            ->assertJsonPath('metadata.api_activity_available', true)
            ->assertJsonPath('metadata.data_sources', [
                'users',
                'access_logs',
                'audits',
                'ai_recommendations',
            ]);

        $this->assertEqualsCanonicalizing([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'requests_count',
            'error_requests_count',
            'audits_count',
            'completed_audits_count',
            'failed_audits_count',
            'recommendations_count',
            'last_seen_at',
            'usage_score',
        ], array_keys($response->json('users.0')));
    }

    public function test_heavy_user_periods_bound_every_usage_metric(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();

        foreach ([12, 72, 480, 744] as $hoursAgo) {
            $createdAt = now()->subHours($hoursAgo);
            $this->createAccessLog($user, $createdAt);
            $audit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, $createdAt);
            $this->createRecommendation($audit, $createdAt);
        }

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/analytics/heavy-users?period=24h')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $user->id)
            ->assertJsonPath('users.0.requests_count', 1)
            ->assertJsonPath('users.0.audits_count', 1)
            ->assertJsonPath('users.0.recommendations_count', 1)
            ->assertJsonPath('metadata.period', '24h');
        AccessLog::query()->where('user_id', $admin->id)->delete();

        $this->getJson('/api/admin/analytics/heavy-users?period=7d')
            ->assertOk()
            ->assertJsonPath('users.0.requests_count', 2)
            ->assertJsonPath('users.0.audits_count', 2)
            ->assertJsonPath('users.0.recommendations_count', 2)
            ->assertJsonPath('metadata.period', '7d');
        AccessLog::query()->where('user_id', $admin->id)->delete();

        $this->getJson('/api/admin/analytics/heavy-users?period=30d')
            ->assertOk()
            ->assertJsonPath('users.0.requests_count', 3)
            ->assertJsonPath('users.0.audits_count', 3)
            ->assertJsonPath('users.0.recommendations_count', 3)
            ->assertJsonPath('metadata.period', '30d');
    }

    public function test_heavy_user_ranking_uses_documented_tie_breakers(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $moreRequests = User::factory()->create();
        $oneAudit = User::factory()->create();
        $moreAudits = User::factory()->create();
        $moreRecommendations = User::factory()->create();
        $seenLater = User::factory()->create();
        $seenEarlier = User::factory()->create();

        foreach (range(1, 10) as $index) {
            $this->createAccessLog($moreRequests, now()->subMinutes($index));
        }
        $this->createAuditFor($oneAudit, Audit::STATUS_PENDING, now()->subDay());

        foreach (range(1, 4) as $index) {
            $this->createAuditFor($moreAudits, Audit::STATUS_PENDING, now()->subHours($index));
        }
        $oldAudit = $this->createAuditFor(
            $moreRecommendations,
            Audit::STATUS_COMPLETED,
            now()->subDays(31),
        );
        foreach (range(1, 5) as $index) {
            $this->createRecommendation($oldAudit, now()->subMinutes($index));
        }

        $this->createAccessLog($seenLater, now()->subHour());
        $this->createAccessLog($seenEarlier, now()->subHours(2));
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/analytics/heavy-users?period=7d')
            ->assertOk();

        $this->assertSame([
            $moreAudits->id,
            $moreRecommendations->id,
            $moreRequests->id,
            $oneAudit->id,
            $seenLater->id,
            $seenEarlier->id,
        ], collect($response->json('users'))->pluck('id')->all());
    }

    public function test_heavy_users_pagination_and_per_page_cap_work(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();

        foreach (range(1, 5) as $index) {
            $user = User::factory()->create();
            $this->createAccessLog($user, now()->subHours($index));
        }

        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/admin/analytics/heavy-users?period=7d&per_page=2&page=2',
        )
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);

        AccessLog::query()->where('user_id', $admin->id)->delete();
        $this->getJson('/api/admin/analytics/heavy-users?period=7d&per_page=500')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_heavy_users_include_real_audit_activity_without_access_logs(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, now()->subDay());
        $this->createRecommendation($audit, now()->subHours(12));
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/analytics/heavy-users?period=7d')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $user->id)
            ->assertJsonPath('users.0.requests_count', 0)
            ->assertJsonPath('users.0.audits_count', 1)
            ->assertJsonPath('users.0.recommendations_count', 1)
            ->assertJsonPath('users.0.last_seen_at', null)
            ->assertJsonPath('users.0.usage_score', 18);
    }

    public function test_heavy_users_validate_period_and_return_an_empty_page_cleanly(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/analytics/heavy-users?period=90d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');

        AccessLog::query()->delete();
        $this->getJson('/api/admin/analytics/heavy-users?period=24h')
            ->assertOk()
            ->assertJsonCount(0, 'users')
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_active_users_per_page_is_capped_and_queries_do_not_grow_per_user(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create();
        $this->createAccessLog($firstUser, now()->subMinute());
        Sanctum::actingAs($admin);

        $capturing = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$capturing, &$queries): void {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        $capturing = true;
        $this->getJson('/api/admin/analytics/active-users?per_page=500')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
        $capturing = false;
        $singleUserQueryCount = count($queries);

        foreach (range(1, 10) as $index) {
            $user = User::factory()->create();
            $this->createAccessLog($user, now()->subMinutes($index));
        }

        $queries = [];
        $capturing = true;
        $this->getJson('/api/admin/analytics/active-users?per_page=500')->assertOk();
        $capturing = false;

        $this->assertSame($singleUserQueryCount, count($queries));
        $this->assertLessThanOrEqual(3, count($queries));
    }

    public function test_analytics_responses_do_not_expose_raw_or_sensitive_access_data(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $this->createAccessLog($user, now()->subMinute(), [
            'route' => '/api/private-route',
            'user_agent' => 'Authorization Bearer private-token password=secret api_key=secret',
        ]);
        Sanctum::actingAs($admin);

        foreach (self::ROUTES as $route) {
            $content = $this->getJson($route)->assertOk()->getContent();

            $this->assertStringNotContainsString('/api/private-route', $content);
            $this->assertStringNotContainsString('private-token', $content);
            $this->assertStringNotContainsString('password=secret', $content);
            $this->assertStringNotContainsString('api_key=secret', $content);
        }
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAccessLog(
        User $user,
        mixed $createdAt,
        array $attributes = [],
    ): AccessLog {
        return AccessLog::create([
            'user_id' => $user->id,
            'ip_address' => $attributes['ip_address'] ?? null,
            'method' => 'GET',
            'route' => $attributes['route'] ?? '/api/me',
            'status_code' => $attributes['status_code'] ?? 200,
            'user_agent' => $attributes['user_agent'] ?? null,
            'created_at' => $createdAt,
        ]);
    }

    private function createAuditFor(User $user, string $status, mixed $createdAt = null): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => fake()->unique()->domainName(),
            'url' => fake()->url(),
        ]);
        $audit = $domain->audits()->create([
            'status' => $status,
            'requested_url' => $domain->url,
        ]);

        if ($createdAt !== null) {
            $audit->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $audit->refresh();
    }

    private function createRecommendation(Audit $audit, mixed $createdAt): AiRecommendation
    {
        $recommendation = AiRecommendation::create([
            'audit_id' => $audit->id,
            'generated_text' => 'Recommendation',
        ]);
        $recommendation->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $recommendation->refresh();
    }
}
