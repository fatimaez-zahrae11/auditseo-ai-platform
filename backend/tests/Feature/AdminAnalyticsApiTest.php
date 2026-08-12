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

    public function test_heavy_users_are_ranked_by_request_usage_with_period_metrics(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $heavyUser = User::factory()->create();
        $lighterUser = User::factory()->create();

        foreach (range(1, 5) as $index) {
            $this->createAccessLog($heavyUser, now()->subHours($index));
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

        $this->getJson('/api/admin/analytics/heavy-users')
            ->assertOk()
            ->assertJsonPath('users.0.id', $heavyUser->id)
            ->assertJsonPath('users.0.requests_count', 5)
            ->assertJsonPath('users.0.audits_count', 2)
            ->assertJsonPath('users.0.completed_audits_count', 1)
            ->assertJsonPath('users.0.failed_audits_count', 1)
            ->assertJsonPath('users.0.recommendations_count', 1)
            ->assertJsonPath('users.1.id', $lighterUser->id)
            ->assertJsonPath('users.1.requests_count', 2);
    }

    public function test_heavy_users_date_filters_apply_to_usage_metrics(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $includedUser = User::factory()->create();
        $excludedUser = User::factory()->create();

        $this->createAccessLog($includedUser, '2026-08-05 10:00:00');
        $this->createAccessLog($includedUser, '2026-08-05 11:00:00');
        $this->createAccessLog($includedUser, '2026-08-09 10:00:00');
        $this->createAccessLog($excludedUser, '2026-08-09 10:00:00');
        $includedAudit = $this->createAuditFor(
            $includedUser,
            Audit::STATUS_COMPLETED,
            '2026-08-05 12:00:00',
        );
        $this->createAuditFor(
            $includedUser,
            Audit::STATUS_FAILED,
            '2026-08-09 12:00:00',
        );
        $this->createRecommendation($includedAudit, '2026-08-05 13:00:00');

        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/admin/analytics/heavy-users?from=2026-08-05&to=2026-08-05',
        )
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $includedUser->id)
            ->assertJsonPath('users.0.requests_count', 2)
            ->assertJsonPath('users.0.audits_count', 1)
            ->assertJsonPath('users.0.completed_audits_count', 1)
            ->assertJsonPath('users.0.failed_audits_count', 0)
            ->assertJsonPath('users.0.recommendations_count', 1);
    }

    public function test_heavy_users_pagination_and_per_page_cap_work(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();

        foreach (range(1, 5) as $index) {
            $user = User::factory()->create();
            $this->createAccessLog($user, "2026-08-05 0{$index}:00:00");
        }

        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/admin/analytics/heavy-users?from=2026-08-05&to=2026-08-05&per_page=2&page=2',
        )
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);
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
            'status_code' => 200,
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
